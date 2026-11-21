<?php

namespace Database\Seeders;

use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\User;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Installs the ZESCO templates used for QR-coded internal documents, from the
 * files in database/seeders/templates, with the QR code where it was placed.
 *
 * Each template goes through the same steps as an upload in Configurations
 * (keep the original, place the QR code, prepare the file), so the result is
 * the same as adding it by hand. A template whose name already exists is left
 * alone, so the seeder can be run again safely.
 */
class PdtsDocumentTemplateSeeder extends Seeder
{
    /** name, file, document type, and QR placement (page, mm from left, mm from top, size in mm) */
    private const TEMPLATES = [
        [
            'name' => 'Internal Memorandum',
            'file' => 'ZESCO_Memorandum_Template.docx',
            'type' => 'Memorandum',
            'placement' => ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 16],
        ],
        [
            'name' => 'Petty Cash VoucherP',
            'file' => 'Petty_Cash_VoucherP.pdf',
            'type' => 'Request / Submission',
            'placement' => ['page' => 'first', 'x' => 79.7, 'y' => 3.4, 'size' => 15],
        ],
        [
            'name' => 'Monthly Report Template',
            'file' => 'Monthly_Report_Template.docx',
            'type' => 'Report',
            'placement' => ['page' => 'first', 'x' => 164.5, 'y' => 34.9, 'size' => 17],
        ],
        [
            'name' => 'Petty Cash Voucher',
            'file' => 'Petty_Cash_Voucher.docx',
            'type' => 'Procurement Document',
            'placement' => ['page' => 'first', 'x' => 91.9, 'y' => 4.8, 'size' => 24],
        ],
    ];

    public function run(TemplateSetup $setup): void
    {
        $uploader = User::query()->orderBy('id')->value('id');

        foreach (self::TEMPLATES as $item) {
            if (PdtsDocumentTemplate::query()->where('name', $item['name'])->exists()) {
                $this->command?->line("  Template \"{$item['name']}\" already exists, skipped.");

                continue;
            }

            $source = database_path('seeders/templates/'.$item['file']);
            if (! is_file($source)) {
                $this->command?->warn("  {$item['file']} is missing from database/seeders/templates, skipped.");

                continue;
            }

            $stored = null;
            try {
                $stored = $setup->storeOriginal($source, pathinfo($item['file'], PATHINFO_EXTENSION));

                $template = PdtsDocumentTemplate::create([
                    'name' => $item['name'],
                    'kind' => $stored['kind'],
                    'document_type_id' => PdtsDocumentType::query()->firstOrCreate(['name' => $item['type']], ['is_active' => true])->id,
                    'original_path' => $stored['path'],
                    'original_filename' => $item['file'],
                    'page_count' => $stored['page_count'],
                    'is_active' => false,
                    'uploaded_by' => $uploader,
                ]);

                // Places the QR code, prepares the file and makes it available to authors.
                $setup->save($template, $item['placement']);

                $this->command?->info("  Template \"{$item['name']}\" installed.");
            } catch (Throwable $e) {
                if ($stored) {
                    Storage::disk('private')->delete($stored['path']);
                }
                PdtsDocumentTemplate::query()->where('name', $item['name'])->whereNull('configured_at')->delete();
                $this->command?->error("  Template \"{$item['name']}\" could not be installed: {$e->getMessage()}");
            }
        }
    }
}
