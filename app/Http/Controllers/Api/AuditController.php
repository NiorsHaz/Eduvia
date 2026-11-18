<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        return Activity::with('causer')
            ->when($request->filled('log_name'), fn ($q) => $q->where('log_name', $request->input('log_name')))
            ->when($request->filled('causer_id'), fn ($q) => $q->where('causer_id', $request->integer('causer_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('q'), fn ($q) => $q->where('description', 'like', '%'.$request->string('q').'%'))
            ->orderByDesc('id')->paginate(50);
    }

    public function logs()
    {
        return Activity::query()->select('log_name')->distinct()->orderBy('log_name')->pluck('log_name');
    }
}
