<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Configuration for the typed reference tables behind /api/v1/reference/{name}.
 * Keeps simple administrative data (levels, rooms, fee categories, ...) in one
 * validated, permission-checked CRUD surface instead of dozens of controllers.
 */
class ReferenceRegistry
{
    public function get(string $resource): array
    {
        $config = config("references.{$resource}");

        if (! $config) {
            throw new InvalidArgumentException("Unknown reference resource [{$resource}].");
        }

        return $config;
    }

    public function all(): array
    {
        return config('references', []);
    }
}
