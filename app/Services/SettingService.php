<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SettingDefinition;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Typed, scoped settings registry. Resolution order (most specific wins):
 * level > academic year > campus > school-wide > definition default.
 * The admin screen is generated from setting_definitions.
 */
class SettingService
{
    public function definitions(): Collection
    {
        return SettingDefinition::orderBy('module')->orderBy('key')->get();
    }

    public function definition(string $key): SettingDefinition
    {
        return SettingDefinition::findOrFail($key);
    }

    public function get(string $key, array $scope = []): mixed
    {
        $definition = $this->definition($key);

        $query = Setting::where('key', $key);
        $this->applyScope($query, $scope, true);

        $row = $query->orderByRaw(
            'CASE WHEN level_id IS NOT NULL THEN 3 WHEN academic_year_id IS NOT NULL THEN 2 WHEN campus_id IS NOT NULL THEN 1 ELSE 0 END DESC'
        )->first();

        return $row?->value ?? $definition->default_value;
    }

    public function set(string $key, mixed $value, array $scope = [], ?int $userId = null): Setting
    {
        $definition = $this->definition($key);
        $this->assertValid($definition, $value);

        $scope = [
            'campus_id' => $scope['campus_id'] ?? null,
            'academic_year_id' => $scope['academic_year_id'] ?? null,
            'level_id' => $scope['level_id'] ?? null,
        ];

        $setting = Setting::firstOrNew([
            'key' => $key,
            'campus_id' => $scope['campus_id'],
            'academic_year_id' => $scope['academic_year_id'],
            'level_id' => $scope['level_id'],
        ]);
        $setting->value = $value;
        $setting->updated_by = $userId;
        $setting->updated_at = now();
        $setting->save();

        activity('settings')->performedOn($setting)->withProperties(['value' => $value])->log('setting.updated');

        return $setting;
    }

    /**
     * Resolve every definition for one scope in a single pass (admin screens and report logic).
     */
    public function all(array $scope = []): array
    {
        $query = Setting::query();
        $this->applyScope($query, $scope, true);
        $rows = $query->get();

        $resolved = [];
        foreach ($this->definitions() as $definition) {
            $match = $rows->where('key', $definition->key)
                ->sortByDesc(fn (Setting $s) => $s->level_id ? 3 : ($s->academic_year_id ? 2 : ($s->campus_id ? 1 : 0)))
                ->first();

            $resolved[$definition->key] = $match?->value ?? $definition->default_value;
        }

        return $resolved;
    }

    private function applyScope($query, array $scope, bool $allowFallback): void
    {
        if (! $allowFallback) {
            foreach (['campus_id', 'academic_year_id', 'level_id'] as $field) {
                $query->where($field, $scope[$field] ?? null);
            }

            return;
        }

        // A row matches when every non-null scope value agrees and unspecified
        // scope columns are null (that is the fallback chain).
        $query->where(function ($q) use ($scope) {
            $q->whereNull('level_id')->orWhere('level_id', $scope['level_id'] ?? null);
        })->where(function ($q) use ($scope) {
            $q->whereNull('academic_year_id')->orWhere('academic_year_id', $scope['academic_year_id'] ?? null);
        })->where(function ($q) use ($scope) {
            $q->whereNull('campus_id')->orWhere('campus_id', $scope['campus_id'] ?? null);
        });
    }

    public function assertValid(SettingDefinition $definition, mixed $value): void
    {
        $valid = match ($definition->value_type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'decimal' => is_numeric($value),
            'string' => is_string($value),
            'enum' => in_array($value, $definition->allowed_values ?? [], true),
            'json' => is_array($value),
            default => false,
        };

        if (! $valid) {
            throw new InvalidArgumentException("Invalid value for setting [{$definition->key}] of type {$definition->value_type}.");
        }
    }
}
