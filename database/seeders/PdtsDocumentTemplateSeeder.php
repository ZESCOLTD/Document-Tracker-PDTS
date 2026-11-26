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
 * Installs ZESCO's Internal Memo template, with its QR code placed, so
 * internal memos can be created and signed in PDTS straight away. Other
 * templates are added through Configurations as ZESCO supplies them.
 *
 * It goes through the same steps as an upload in Configurations (keep the
 * original, place the QR code, prepare the file). A template whose name
 * already exists is left alone, so the seeder can be run again safely.
 */
class PdtsDocumentTemplateSeeder extends Seeder
{
    /**
     * name, file, document type, QR placement (page, mm from left, mm from top,
     * size in mm), and the form's own labels and settings, as set up in
     * Configurations.
     */
    private const TEMPLATES = [
        [
            'name' => 'Internal Memo',
            'file' => 'ZESCO_Internal_Memo.docx',
            'type' => 'Memorandum',
            'placement' => ['page' => 'first', 'x' => 14.2, 'y' => 23.2, 'size' => 15],
            'labels' => ['to' => 'To', 'from' => 'From', 'cc' => 'Cc', 'office_reference' => 'Ref', 'date' => 'Date', 'text_body' => 'Body'],
            'settings' => [
                'to' => ['auto' => 'none', 'type' => 'text'],
                'from' => ['auto' => 'none', 'type' => 'text'],
                'cc' => ['auto' => 'none', 'type' => 'text'],
                'office_reference' => ['auto' => 'none', 'type' => 'text'],
                'date' => ['auto' => 'none', 'type' => 'text'],
                'text_body' => ['auto' => 'none', 'type' => 'long'],
            ],
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
                    'field_settings' => $item['settings'] ?? null,
                    'is_active' => false,
                    'uploaded_by' => $uploader,
                ]);

                // Places the QR code, prepares the file and makes it available to authors.
                $setup->save($template, $item['placement']);

                // The form's labels as set up in Configurations, over those read from the file.
                if (! empty($item['labels'])) {
                    $template->forceFill(['field_labels' => array_merge((array) $template->fresh()->field_labels, $item['labels'])])->save();
                }

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
