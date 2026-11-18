<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\StudentDiscount;
use App\Services\FeeService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FeeController extends Controller
{
    public function __construct(private readonly FeeService $fees) {}

    public function generate(Request $request)
    {
        $data = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'level_ids' => 'nullable|array',
            'level_ids.*' => 'integer|exists:levels,id',
            'class_ids' => 'nullable|array',
            'class_ids.*' => 'integer|exists:classes,id',
            'include_optional' => 'boolean',
            'preview' => 'boolean',
        ]);

        $result = $this->fees->generate(
            (int) $data['academic_year_id'],
            [
                'level_ids' => $data['level_ids'] ?? [],
                'class_ids' => $data['class_ids'] ?? [],
                'include_optional' => $request->boolean('include_optional'),
            ],
            $request->user()->id,
            ! $request->boolean('preview')
        );

        return response()->json($result, 201);
    }

    public function studentDiscounts(Request $request)
    {
        return StudentDiscount::with(['discount'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->get();
    }

    public function grantDiscount(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'discount_id' => 'required|integer|exists:discounts,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'note' => 'nullable|string',
        ]);

        $grant = StudentDiscount::firstOrCreate(
            ['student_id' => $data['student_id'], 'discount_id' => $data['discount_id'], 'academic_year_id' => $data['academic_year_id']],
            ['status' => 'pending', 'note' => $data['note'] ?? null, 'requested_by' => $request->user()->id]
        );

        return response()->json($grant, 201);
    }

    public function decideDiscount(Request $request, StudentDiscount $studentDiscount)
    {
        $data = $request->validate([
            'status' => 'required|in:approved,rejected,revoked,expired',
            'note' => 'nullable|string',
        ]);

        if ($studentDiscount->status !== 'pending' && $data['status'] === 'approved') {
            throw ValidationException::withMessages(['status' => 'Only pending grants can be approved.']);
        }

        $studentDiscount->update([
            'status' => $data['status'],
            'approved_by' => $request->user()->id,
            'approved_at' => $data['status'] === 'approved' ? now() : null,
            'note' => $data['note'] ?? $studentDiscount->note,
        ]);

        $applied = $data['status'] === 'approved'
            ? $this->fees->applyApprovedDiscounts((int) $studentDiscount->student_id, (int) $studentDiscount->academic_year_id)
            : 0;

        return ['grant' => $studentDiscount->fresh(), 'adjustments_created' => $applied];
    }

    public function runPenalties(Request $request)
    {
        $data = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'charge_id' => 'nullable|integer|exists:charges,id',
        ]);

        $charges = Charge::where('academic_year_id', $data['academic_year_id'])
            ->where('status', 'open')
            ->when($data['charge_id'] ?? null, fn ($q) => $q->whereKey($data['charge_id']))
            ->get();

        $applied = 0;
        foreach ($charges as $charge) {
            $applied += $this->fees->applyPenalties($charge, $request->user()->id);
        }

        return ['penalties_applied' => $applied, 'charges_scanned' => $charges->count()];
    }
}
