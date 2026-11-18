<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        return Expense::with(['category', 'paymentMethod', 'requester', 'approver'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->date('to')))
            ->orderByDesc('expense_date')->paginate(30);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'category_id' => 'required|integer|exists:expense_categories,id',
            'supplier' => 'nullable|string|max:160',
            'description' => 'required|string|max:200',
            'amount' => 'required|integer|min:1',
            'expense_date' => 'required|date',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'reference' => 'nullable|string|max:120',
        ]);

        $expense = Expense::create($data + [
            'status' => 'requested',
            'requested_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        return response()->json($expense, 201);
    }

    /** Four-eyes: the approver cannot be the requester. */
    public function decide(Request $request, Expense $expense)
    {
        $data = $request->validate(['status' => 'required|in:approved,rejected,paid']);

        if ((int) $expense->requested_by === (int) $request->user()->id) {
            throw ValidationException::withMessages(['approved_by' => 'An expense must be approved by a different person from the requester.']);
        }

        $expense->update([
            'status' => $data['status'],
            'approved_by' => $request->user()->id,
            'paid_at' => $data['status'] === 'paid' ? now() : null,
        ]);

        activity('finance')->performedOn($expense)->withProperties($data)->log('expense.decided');

        return $expense->fresh(['category']);
    }
}
