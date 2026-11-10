<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test documents only. Real ones are created by RegisterPdtsDocument.
 *
 * @extends Factory<PdtsDocument>
 */
class PdtsDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_number' => fake()->unique()->numerify('TEST-######'),
            'title' => fake()->sentence(4),
            'subject' => fake()->sentence(3),
            'document_type_id' => fn () => PdtsDocumentType::firstOrCreate(['name' => 'Memorandum'], ['is_active' => true])->getKey(),
            'originating_department' => 'Operations',
            'originating_office' => 'Lusaka Main Office',
            // A new document is held by whoever registered it.
            'current_custodian_id' => User::factory(),
            'registered_by' => fn (array $attributes) => $attributes['current_custodian_id'],
            'status' => DocumentStatus::Registered->value,
            'registered_at' => now(),
        ];
    }
}
