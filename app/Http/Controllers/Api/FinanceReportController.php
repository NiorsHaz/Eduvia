<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashClosing;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceReportController extends Controller
{
    public function summary(Request $request)
    {
        $yearId = $request->integer('academic_year_id');

        $collectedToday = (int) Payment::where('status', 'verified')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->whereDate('paid_at', today())->sum('amount');

        $collectedMonth = (int) Payment::where('status', 'verified')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount');

        $byMethod = DB::table('payments as p')
            ->join('payment_methods as pm', 'pm.id', '=', 'p.payment_method_id')
            ->where('p.status', 'verified')
            ->when($yearId, fn ($q) => $q->where('p.academic_year_id', $yearId))
            ->whereBetween('p.paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw('pm.name as method, count(*) as payments, SUM(p.amount) as total')
            ->groupBy('pm.name')->get();

        $balances = DB::table('charges as c')
            ->where('c.status', 'open')
            ->when($yearId, fn ($q) => $q->where('c.academic_year_id', $yearId))
            ->selectRaw('
                SUM(c.amount) as gross,
                SUM(c.amount
                    + COALESCE((SELECT SUM(a.amount) FROM charge_adjustments a WHERE a.charge_id = c.id AND a.status = \'approved\'), 0)
                ) as net_due,
                SUM(COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.charge_id = c.id), 0)) as paid
            ')->first();

        $byLevel = DB::table('charges as c')
            ->join('enrollments as e', function ($join) {
                $join->on('e.student_id', '=', 'c.student_id')
                    ->on('e.academic_year_id', '=', 'c.academic_year_id')
                    ->whereNull('e.end_date');
            })
            ->join('classes as cl', 'cl.id', '=', 'e.class_id')
            ->join('levels as l', 'l.id', '=', 'cl.level_id')
            ->where('c.status', 'open')
            ->when($yearId, fn ($q) => $q->where('c.academic_year_id', $yearId))
            ->selectRaw('l.name as level,
                SUM(c.amount + COALESCE((SELECT SUM(a.amount) FROM charge_adjustments a WHERE a.charge_id = c.id AND a.status = \'approved\'), 0)) as expected,
                SUM(COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.charge_id = c.id), 0)) as collected')
            ->groupBy('l.name')->orderBy('l.name')->get();

        $overdue = DB::table('charges as c')
            ->where('c.status', 'open')
            ->when($yearId, fn ($q) => $q->where('c.academic_year_id', $yearId))
            ->whereDate('c.due_date', '<', today())
            ->whereRaw('(c.amount
                + COALESCE((SELECT SUM(a.amount) FROM charge_adjustments a WHERE a.charge_id = c.id AND a.status = \'approved\'), 0)
                - COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.charge_id = c.id), 0)) > 0')
            ->count();

        return [
            'collected_today' => $collectedToday,
            'collected_month' => $collectedMonth,
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'by_method' => $byMethod,
            'totals' => [
                'gross' => (int) ($balances->gross ?? 0),
                'net_due' => (int) ($balances->net_due ?? 0),
                'paid' => (int) ($balances->paid ?? 0),
                'outstanding' => (int) (($balances->net_due ?? 0) - ($balances->paid ?? 0)),
            ],
            'by_level' => $byLevel->map(fn ($row) => [
                'level' => $row->level,
                'expected' => (int) $row->expected,
                'collected' => (int) $row->collected,
                'outstanding' => (int) $row->expected - (int) $row->collected,
            ]),
            'overdue_charges' => $overdue,
            'unallocated' => (int) DB::table('payments as p')
                ->where('p.status', 'verified')
                ->whereRaw('p.amount > COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.payment_id = p.id), 0)')
                ->sum('p.amount'),
        ];
    }

    public function cashClosings(Request $request)
    {
        return CashClosing::with(['cashier', 'approvedBy'])
            ->when($request->filled('date'), fn ($q) => $q->whereDate('closing_date', $request->date('date')))
            ->orderByDesc('closing_date')->get();
    }

    public function closeCash(Request $request)
    {
        $data = $request->validate([
            'closing_date' => 'required|date',
            'counted_amount' => 'required|integer|min:0',
            'note' => 'nullable|string',
        ]);

        $expected = (int) Payment::query()
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payment_methods.code', 'cash')
            ->where('payments.status', 'verified')
            ->whereDate('payments.paid_at', $data['closing_date'])
            ->sum('payments.amount');

        $closing = CashClosing::updateOrCreate(
            ['closing_date' => $data['closing_date'], 'cashier_user_id' => $request->user()->id],
            [
                'expected_amount' => $expected,
                'counted_amount' => $data['counted_amount'],
                'difference' => (int) $data['counted_amount'] - $expected,
                'status' => 'closed',
                'note' => $data['note'] ?? null,
                'closed_at' => now(),
            ]
        );

        return response()->json($closing, 201);
    }
}
