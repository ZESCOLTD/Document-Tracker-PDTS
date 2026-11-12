<?php

namespace Database\Seeders;

use App\Models\PdtsDirectorate;
use Illuminate\Database\Seeder;

/**
 * The organisation's directorates, the top of Directorate → Department → Office,
 * each with its Director's office. Safe to re-run; existing rows are left as they are.
 */
class PdtsDirectorateSeeder extends Seeder
{
    public const DIRECTORATES = [
        'Strategy and Business Development',
        'Corporate Support Services',
        'Distribution & Customer Service',
        'Human Capital and Development',
        'Hydro-Power Projects',
        'Information & Communication Technology',
        'Investments & Finance',
        'Power Generation',
        'Projects & Planning',
        'Transmissions Operations and Trade',
    ];

    public function run(): void
    {
        foreach (self::DIRECTORATES as $name) {
            $directorate = PdtsDirectorate::firstOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );

            // Every directorate is headed by a Director, whose office sits
            // directly under the directorate rather than in a department.
            $directorate->offices()->firstOrCreate(
                ['name' => 'Director'],
                ['is_head' => true, 'is_active' => true],
            );
        }
    }
}
