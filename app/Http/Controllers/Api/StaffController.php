<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    /**
     * Staff directory with the login account linked to each member, so that
     * head teachers can see who still has no account and create one.
     */
    public function index(Request $request)
    {
        return Staff::with(['user.roles', 'department:id,name'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($where) => $where
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('employee_number', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('staff_type'), fn ($query) => $query->where('staff_type', $request->input('staff_type')))
            ->when($request->filled('account'), function ($query) use ($request) {
                if ($request->input('account') === 'linked') {
                    $query->whereNotNull('user_id');
                } elseif ($request->input('account') === 'none') {
                    $query->whereNull('user_id');
                }
            })
            ->orderByRaw("case when staff_type = 'teacher' then 0 else 1 end")
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(min(300, max(1, (int) $request->input('per_page', 200))));
    }
}
