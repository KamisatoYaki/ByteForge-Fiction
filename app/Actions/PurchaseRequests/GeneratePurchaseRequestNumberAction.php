<?php

namespace App\Actions\PurchaseRequests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Allocates the next PR-YYYY-NNNN number using a locked per-year sequence row.
 * Unique index on purchase_requests.request_number remains a second line of defense.
 */
class GeneratePurchaseRequestNumberAction
{
    public static function run(?Carbon $at = null): string
    {
        $at ??= Carbon::now(config('app.timezone'));
        $year = (int) $at->format('Y');

        return DB::transaction(function () use ($year) {
            $sequence = DB::table('purchase_request_number_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                try {
                    DB::table('purchase_request_number_sequences')->insert([
                        'year' => $year,
                        'last_value' => 0,
                    ]);
                } catch (Throwable) {
                    // Concurrent first insert for this year — fall through to lock.
                }

                $sequence = DB::table('purchase_request_number_sequences')
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();
            }

            $next = ((int) ($sequence->last_value ?? 0)) + 1;

            DB::table('purchase_request_number_sequences')
                ->where('year', $year)
                ->update(['last_value' => $next]);

            return sprintf('PR-%d-%04d', $year, $next);
        });
    }
}
