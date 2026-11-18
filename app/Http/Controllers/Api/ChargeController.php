<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\ChargeAdjustment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChargeController extends Controller
{
    public function index(Request $request)
    {
        $charges = Charge::with(['student', 'feeCategory', 'invoice'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('due_date')->orderBy('id')
            ->paginate(min(200, (int) $request->input('per_page', 50)));

        $charges->getCollection()->transform(function (Charge $charge) {
            $charge->setAttribute('balances', $charge->balances());

            return $charge;
        });

        return $charges;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'fee_category_id' => 'required|integer|exists:fee_categories,id',
            'description' => 'required|string|max:200',
            'amount' => 'required|integer|min:0',
            'due_date' => 'required|date',
            'source' => 'in:fee_structure,manual,transport,opening_balance,import',
        ]);

        $charge = Charge::create($data + [
            'source' => $data['source'] ?? 'manual',
            'status' => 'open',
            'created_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        activity('finance')->performedOn($charge)->log('charge.created');

        return response()->json($charge->setAttribute('balances', $charge->balances()), 201);
    }

    public function cancel(Request $request, Charge $charge)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        if ($charge->paidTotal() > 0) {
            return response()->json(['message' => 'This charge already has payments. Reverse the payment instead of cancelling the charge.'], 422);
        }

        $charge->update([
            'status' => 'cancelled',
            'cancelled_by' => $request->user()->id,
            'cancelled_reason' => $data['reason'],
        ]);

        activity('finance')->performedOn($charge)->withProperties($data)->log('charge.cancelled');

        return $charge;
    }

    public function adjustments(Charge $charge)
    {
        return $charge->adjustments()->with(['approvedBy', 'requestedBy'])->get();
    }

    public function storeAdjustment(Request $request, Charge $charge)
    {
        $data = $request->validate([
            'kind' => 'required|in:discount,scholarship,waiver,penalty,correction',
            'amount' => 'required|integer',
            'reason' => 'required_if:kind,waiver,correction|nullable|string|max:255',
            'penalty_rule_id' => 'nullable|integer|exists:penalty_rules,id',
            'period_key' => 'nullable|string|max:40',
        ]);

        $amount = (int) $data['amount'];
        if (in_array($data['kind'], ['discount', 'scholarship', 'waiver'], true) && $amount >= 0) {
            $amount = -abs($amount);
        }
        if ($data['kind'] === 'penalty' && $amount <= 0) {
            return response()->json(['message' => 'A penalty must increase the amount owed.'], 422);
        }

        $adjustment = ChargeAdjustment::create([
            'charge_id' => $charge->id,
            'kind' => $data['kind'],
            'amount' => $amount,
            'penalty_rule_id' => $data['penalty_rule_id'] ?? null,
            'period_key' => $data['period_key'] ?? null,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        return response()->json($adjustment, 201);
    }

    /** Four-eyes for waivers and corrections; discounts follow the same rule. */
    public function decideAdjustment(Request $request, ChargeAdjustment $adjustment)
    {
        $data = $request->validate(['status' => 'required|in:approved,rejected']);

        if ($data['status'] === 'approved' && (int) $adjustment->requested_by === (int) $request->user()->id) {
            return response()->json(['message' => 'An adjustment must be approved by a different person from the requester.'], 422);
        }

        if ($adjustment->status !== 'pending') {
            return response()->json(['message' => 'This adjustment has already been decided.'], 422);
        }

        $adjustment->update([
            'status' => $data['status'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        activity('finance')->performedOn($adjustment)->withProperties($data)->log('charge.adjustment_decided');

        return $adjustment->fresh();
    }

    public function balances(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
        ]);

        $charges = Charge::where('student_id', $data['student_id'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('status', 'open')->get()
            ->map(fn (Charge $charge) => [
                'id' => $charge->id,
                'description' => $charge->description,
                'due_date' => $charge->due_date?->toDateString(),
                'balances' => $charge->balances(),
            ]);

        return [
            'student' => Student::find($data['student_id']),
            'summary' => Charge::balancesForStudent((int) $data['student_id'], (int) $data['academic_year_id']),
            'charges' => $charges,
            'unallocated_credit' => (int) DB::table('payments as p')
                ->whereIn('p.status', ['verified'])
                ->whereRaw('p.amount > COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.payment_id = p.id), 0)')
                ->whereIn('p.id', function ($q) use ($data) {
                    $q->select('pa.payment_id')->from('payment_allocations as pa')
                        ->join('charges as c', 'c.id', '=', 'pa.charge_id')
                        ->where('c.student_id', $data['student_id']);
                })->sum('p.amount'),
        ];
    }
}
