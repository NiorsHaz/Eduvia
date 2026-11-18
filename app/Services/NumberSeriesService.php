<?php

namespace App\Services;

use App\Models\NumberSeries;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Numbering is generated inside the caller's transaction, so a rollback also
 * rolls back the counter and receipt numbers never have gaps.
 */
class NumberSeriesService
{
    public function next(string $series, string $periodKey): string
    {
        $definition = NumberSeries::where('code', $series)->where('is_active', true)->first();
        if (! $definition) {
            throw new RuntimeException("Number series [{$series}] is missing or inactive.");
        }

        $counter = DB::table('number_series_counters')
            ->where('series_code', $series)
            ->where('period_key', $periodKey)
            ->lockForUpdate()
            ->first();

        if (! $counter) {
            DB::table('number_series_counters')->insert([
                'series_code' => $series,
                'period_key' => $periodKey,
                'last_value' => 0,
            ]);
        }

        DB::table('number_series_counters')
            ->where('series_code', $series)
            ->where('period_key', $periodKey)
            ->increment('last_value', 1);

        $number = (int) DB::table('number_series_counters')
            ->where('series_code', $series)
            ->where('period_key', $periodKey)
            ->value('last_value');

        $result = str_replace('{YEAR}', $periodKey, $definition->pattern);

        if (preg_match('/\{SEQ:(\d+)\}/', $result, $match)) {
            $result = preg_replace('/\{SEQ:\d+\}/', str_pad((string) $number, (int) $match[1], '0', STR_PAD_LEFT), $result);
        } else {
            $result .= $number;
        }

        return $result;
    }
}
