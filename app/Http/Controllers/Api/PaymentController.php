<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\Receipt;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request)
    {
        return Payment::with(['method', 'receipt', 'allocations.charge.student'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_at', '<=', $request->date('to')))
            ->when($request->filled('payment_method_id'), fn ($q) => $q->where('payment_method_id', $request->integer('payment_method_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', '%'.$request->string('q').'%')
                ->orWhere('payer_name', 'like', '%'.$request->string('q').'%')))
            ->orderByDesc('paid_at')->orderByDesc('id')->paginate(30);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'amount' => 'required|integer|min:1',
            'paid_at' => 'nullable|date',
            'reference' => 'nullable|string|max:120',
            'payer_guardian_id' => 'nullable|integer|exists:guardians,id',
            'payer_name' => 'nullable|string|max:160',
            'notes' => 'nullable|string',
            'client_uuid' => 'nullable|uuid',
            'charge_ids' => 'nullable|array',
            'charge_ids.*' => 'integer|exists:charges,id',
            'allocations' => 'nullable|array',
            'allocations.*.charge_id' => 'required_with:allocations|integer|exists:charges,id',
            'allocations.*.amount' => 'required_with:allocations|integer|min:1',
        ]);

        $payment = $this->payments->record($data, $request->user());

        return response()->json($payment, 201);
    }

    public function show(Payment $payment)
    {
        return $payment->load(['method', 'receipt', 'allocations.charge.student', 'reversals', 'payerGuardian']);
    }

    public function verify(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'allocations' => 'nullable|array',
            'allocations.*.charge_id' => 'required_with:allocations|integer|exists:charges,id',
            'allocations.*.amount' => 'required_with:allocations|integer|min:1',
            'charge_ids' => 'nullable|array',
            'charge_ids.*' => 'integer|exists:charges,id',
        ]);

        $verified = $this->payments->verify($payment, $request->user(), $data['allocations'] ?? null, $data['charge_ids'] ?? []);

        return $verified;
    }

    public function allocate(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.charge_id' => 'required|integer|exists:charges,id',
            'allocations.*.amount' => 'required|integer|min:1',
        ]);

        if ($payment->status !== 'verified') {
            throw ValidationException::withMessages(['payment' => 'Only verified payments can be allocated.']);
        }

        DB::transaction(fn () => $this->payments->allocate($payment, $data['allocations'], $request->user()));

        return $payment->fresh(['allocations.charge.student', 'receipt']);
    }

    public function requestReversal(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'kind' => 'required|in:reversal,refund',
            'amount' => 'required|integer|min:1|max:'.$payment->amount,
            'reason' => 'required|string|max:255',
        ]);

        $reversal = $this->payments->requestReversal($payment, $data['kind'], (int) $data['amount'], $data['reason'], $request->user());

        return response()->json($reversal, 201);
    }

    public function approveReversal(Request $request, PaymentReversal $reversal)
    {
        return $this->payments->approveReversal($reversal, $request->user());
    }

    public function reversals(Request $request)
    {
        return PaymentReversal::with(['payment', 'refundMethod'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->get();
    }

    public function receipt(Receipt $receipt)
    {
        return $receipt->load(['payment.method', 'payment.allocations.charge.student', 'payment.payerGuardian'])
            ->toArray() + ['school' => config('school.name')];
    }

    public function receipts(Request $request)
    {
        return Receipt::with('payment')
            ->when($request->filled('q'), fn ($q) => $q->where('receipt_number', 'like', '%'.$request->string('q').'%'))
            ->orderByDesc('issued_at')->paginate(30);
    }
}
