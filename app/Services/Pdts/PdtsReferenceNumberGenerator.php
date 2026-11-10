<?php

namespace App\Services\Pdts;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PdtsReferenceNumberGenerator
{
    public function next(): string
    {
        $prefix = strtoupper(trim((string) config('pdts.reference.prefix', 'OMTS')));
        if (! preg_match('/^[A-Z0-9]{2,10}$/', $prefix)) {
            throw new InvalidArgumentException('PDTS reference prefix must be 2 to 10 letters or numbers.');
        }

        $now = now();
        $yearMode = config('pdts.reference.year_mode', 'calendar');
        $referenceYear = match ($yearMode) {
            'calendar' => $now->year,
            'financial' => $now->month >= (int) config('pdts.reference.financial_year_start_month', 1)
                ? $now->year
                : $now->year - 1,
            default => throw new InvalidArgumentException('PDTS reference year mode must be calendar or financial.'),
        };

        $padding = max(1, min(10, (int) config('pdts.reference.padding', 4)));

        return DB::transaction(function () use ($prefix, $referenceYear, $padding): string {
            DB::table('pdts_reference_sequences')->insertOrIgnore([
                'prefix' => $prefix,
                'reference_year' => $referenceYear,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('pdts_reference_sequences')
                ->where('prefix', $prefix)
                ->where('reference_year', $referenceYear)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw new \RuntimeException('Could not lock the PDTS reference sequence.');
            }

            $nextNumber = ((int) $sequence->last_number) + 1;

            DB::table('pdts_reference_sequences')
                ->where('id', $sequence->id)
                ->update([
                    'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);

            return sprintf('%s-%d-%0'.$padding.'d', $prefix, $referenceYear, $nextNumber);
        }, attempts: 3);
    }
}