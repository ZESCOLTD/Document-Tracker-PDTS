<?php

namespace Database\Seeders;

use App\Models\PdtsDocumentType;
use Illuminate\Database\Seeder;

class PdtsDocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Memorandum',
            'Official Letter',
            'Report',
            'Contract',
            'Procurement Document',
            'Request / Submission',
            'Other',
        ] as $name) {
            PdtsDocumentType::firstOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );
        }
    }
}