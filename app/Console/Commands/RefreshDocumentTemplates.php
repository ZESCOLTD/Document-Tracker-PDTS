<?php

namespace App\Console\Commands;

use App\Models\PdtsDocumentTemplate;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prepares the Word templates again from their originals, with the QR code
 * where it already is. Run after an update that finds more in templates
 * (writing spaces, the signature spot), so existing templates get it too.
 * Each template keeps its QR position, name, type and active state.
 */
class RefreshDocumentTemplates extends Command
{
    protected $signature = 'pdts:templates:refresh';

    protected $description = 'Prepare the configured Word templates again from their originals, keeping their QR code position';

    public function handle(TemplateSetup $setup): int
    {
        $templates = PdtsDocumentTemplate::query()
            ->where('kind', 'docx')
            ->whereNotNull('configured_at')
            ->orderBy('name')
            ->get();

        if ($templates->isEmpty()) {
            $this->info('There are no configured Word templates.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($templates as $template) {
            $placement = $template->placement();
            if ($placement === null) {
                $this->warn("  {$template->name}: its QR code has not been placed on the setup page, skipped.");

                continue;
            }

            $active = $template->is_active;
            try {
                $setup->save($template, $placement);
                $template->forceFill(['is_active' => $active])->save();

                $fresh = $template->fresh();
                $writing = collect($fresh->placeholderNames())->filter(fn ($name) => PdtsDocumentTemplate::isWritingSpace($name))->count();
                $this->info(sprintf('  %s: refreshed (%d writing space%s, %s).', $template->name, $writing, $writing === 1 ? '' : 's',
                    $fresh->supportsElectronicSignature() ? 'can be signed in PDTS' : 'no signature spot found'));
            } catch (Throwable $e) {
                $failed++;
                $this->error("  {$template->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
