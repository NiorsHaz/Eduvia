<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ReferenceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReferenceController extends Controller
{
    public function __construct(private readonly ReferenceRegistry $registry) {}

    public function index(string $resource, Request $request)
    {
        $config = $this->registry->get($resource);
        $this->authorizeAction($config['read'] ?? $config['write']);

        $query = DB::table($config['table']);

        if ($request->filled('q') && ! empty($config['search'])) {
            $term = '%'.$request->string('q').'%';
            $query->where(function ($q) use ($config, $term) {
                foreach ($config['search'] as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        foreach ($config['filters'] ?? [] as $param) {
            if ($request->filled($param)) {
                $query->where($param, $request->input($param));
            }
        }

        $sort = $request->input('sort', $config['sort'] ?? 'id');
        $allowedSorts = array_merge(['id'], $config['fields'], [$config['sort'] ?? 'id']);
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = $config['sort'] ?? 'id';
        }

        $dir = strtolower((string) $request->input('dir', $config['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $perPage = min(500, max(1, (int) $request->input('per_page', 50)));

        return $query->orderBy($sort, $dir)->paginate($perPage);
    }

    public function store(string $resource, Request $request)
    {
        $config = $this->registry->get($resource);
        $this->authorizeAction($config['write']);

        $data = $this->validated($config, $request);

        $id = DB::table($config['table'])->insertGetId($data);
        $row = DB::table($config['table'])->where($config['id'] ?? 'id', $id)->first();

        activity('administration')->withProperties(['resource' => $resource, 'id' => $id])->log("{$resource}.created");

        return response()->json($row, 201);
    }

    public function update(string $resource, string|int $id, Request $request)
    {
        $config = $this->registry->get($resource);
        $this->authorizeAction($config['write']);

        $key = $config['id'] ?? 'id';
        $exists = DB::table($config['table'])->where($key, $id)->exists();
        abort_unless($exists, 404);

        $data = $this->validated($config, $request);

        DB::table($config['table'])->where($key, $id)->update($data);
        activity('administration')->withProperties(['resource' => $resource, 'id' => $id])->log("{$resource}.updated");

        return DB::table($config['table'])->where($key, $id)->first();
    }

    public function destroy(string $resource, string|int $id)
    {
        $config = $this->registry->get($resource);
        $this->authorizeAction($config['write']);

        $key = $config['id'] ?? 'id';

        try {
            DB::table($config['table'])->where($key, $id)->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            throw ValidationException::withMessages([
                'id' => 'This record is referenced by other data and cannot be deleted.',
            ]);
        }

        activity('administration')->withProperties(['resource' => $resource, 'id' => $id])->log("{$resource}.deleted");

        return response()->noContent();
    }

    private function validated(array $config, Request $request): array
    {
        $rules = $config['rules'];

        // Unique codes are scoped to their own table.
        foreach (['code'] as $unique) {
            if (isset($config['fields']) && in_array($unique, $config['fields'], true)
                && in_array($unique, ['code'], true) && $this->hasUniqueCode($config['table'])) {
                $rules[$unique] = ['required', 'string', 'max:40', Rule::unique($config['table'], $unique)->ignore($request->route('id'))];
            }
        }

        $data = $request->validate($rules);

        return array_intersect_key($data, array_flip($config['fields']));
    }

    private function hasUniqueCode(string $table): bool
    {
        return in_array($table, ['levels', 'tracks', 'subjects', 'student_categories', 'fee_categories', 'discounts', 'penalty_rules', 'payment_methods', 'expense_categories', 'number_series'], true);
    }

    private function authorizeAction(string $permission): void
    {
        $user = request()->user();
        abort_unless($user && $user->can($permission), 403, 'You do not have permission to perform this action.');
    }
}
