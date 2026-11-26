<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Services\Pdts\Templates\TemplateDesigner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The Template Builder: an administrator designs a template like a form.
 * Items are dragged from the palette onto the page, dragged to reorder,
 * duplicated, removed, and set up on the right. Saving writes the Word
 * template and opens the review page ("Try it" shows the exact PDF).
 */
class TemplateBuilder extends Component
{
    #[Locked]
    public ?int $templateId = null;

    public string $name = '';

    public string $description = '';

    public string $documentTypeId = '';

    /** ['layout' => ..., 'title' => ..., 'items' => [...]] */
    public array $design = [];

    /** The item being set up on the right. */
    public ?int $selected = null;

    /** The QR code is being set up on the right (it was clicked on the page). */
    public bool $qrSelected = false;

    /** Undo keeps this many steps. */
    private const HISTORY = 40;

    /** The design as this request found it, to remember for undo when the request changes it. */
    private ?array $designBefore = null;

    /** The design field typed in during this request: typing in one field is one step. */
    private ?string $typedKey = null;

    private bool $restoring = false;

    public function mount(?PdtsDocumentTemplate $template = null): void
    {
        if ($template?->exists) {
            abort_unless($template->isBuilt(), 404);
            $this->templateId = $template->id;
            $this->name = $template->name;
            $this->description = (string) $template->description;
            $this->documentTypeId = (string) ($template->document_type_id ?? '');
            $this->design = $template->design;
        } else {
            $this->design = TemplateDesigner::starter();
            // New templates look like the ZESCO memo: on its letterhead.
            $this->design['letterhead'] = TemplateDesigner::defaultLetterhead();
        }
        $this->design['letterhead'] ??= null;
        // The title under the logo: shown unless turned off (templates saved before the setting show it).
        $this->design['show_title'] = (bool) ($this->design['show_title'] ?? true);
        // Where the QR code goes: as set in the builder, else where it is now, else the letterhead's standard spot.
        $this->design['qr'] = TemplateDesigner::cleanQr($this->design['qr'] ?? null)
            ?? TemplateDesigner::cleanQr($template?->exists ? $template->placement() : null)
            ?? app(TemplateDesigner::class)->standardQr($this->design['letterhead']);
        // Templates saved before styles existed: every item gets its kind's default look.
        foreach ($this->design['items'] as $n => $item) {
            $defaults = TemplateDesigner::newItem($item['kind'], $item['kind'] === 'standard' ? $item['name'] : $item['type'])['style'];
            $this->design['items'][$n]['style'] = TemplateDesigner::cleanStyle((array) ($item['style'] ?? []), $defaults);
        }
        if ($this->isCustom()) {
            $this->syncGrid();
        }

        $this->selected = null;
    }

    /*
     * Undo and redo. Each request that changes the design adds the design it
     * started from to the undo list (kept in the session, not sent to the
     * browser). Typing in the same field within a few seconds is one step.
     */

    public function hydrate(): void
    {
        $this->designBefore = $this->design;
    }

    public function updatingDesign($value, $key): void
    {
        $this->typedKey = (string) $key;
        if ($key === 'layout') {
            $this->layoutBefore = (string) ($this->design['layout'] ?? 'memo');
        }
    }

    /** The layout before this request switched it (to keep that layout's page). */
    private string $layoutBefore = 'memo';

    public function dehydrate(): void
    {
        if ($this->restoring || $this->designBefore === null || $this->designBefore === $this->design) {
            return;
        }

        $history = $this->history();
        $last = end($history['undo']) ?: null;
        $sameTyping = $this->typedKey !== null && $last && ($last['key'] ?? null) === $this->typedKey && time() - $last['at'] < 4;
        if ($sameTyping) {
            $history['undo'][array_key_last($history['undo'])]['at'] = time();
        } else {
            $history['undo'][] = ['design' => $this->designBefore, 'key' => $this->typedKey, 'at' => time()];
            $history['undo'] = array_slice($history['undo'], -self::HISTORY);
        }
        $history['redo'] = [];
        $this->keepHistory($history);
    }

    public function undo(): void
    {
        $history = $this->history();
        $step = array_pop($history['undo']);
        if ($step === null) {
            return;
        }
        $history['redo'][] = ['design' => $this->design, 'key' => null, 'at' => time()];
        $this->restore($step['design'], $history);
    }

    public function redo(): void
    {
        $history = $this->history();
        $step = array_pop($history['redo']);
        if ($step === null) {
            return;
        }
        $history['undo'][] = ['design' => $this->design, 'key' => null, 'at' => time()];
        $this->restore($step['design'], $history);
    }

    private function restore(array $design, array $history): void
    {
        $this->design = $design;
        $this->restoring = true;
        $this->selected = null;
        $this->resetErrorBag();
        $this->keepHistory($history);
    }

    /** @return array{undo: list<array>, redo: list<array>} */
    private function history(): array
    {
        return session('pdts.builder-history.'.$this->getId(), ['undo' => [], 'redo' => []]);
    }

    private function keepHistory(array $history): void
    {
        // Only the builders open lately are kept.
        $all = array_slice(array_merge(array_diff_key(session('pdts.builder-history', []), [$this->getId() => 1]), [$this->getId() => $history]), -3, null, true);
        session(['pdts.builder-history' => $all]);
    }

    private function isCustom(): bool
    {
        return ($this->design['layout'] ?? 'memo') === 'custom';
    }

    /** The Custom layout's rows, kept valid (each item in one place; empty rows kept while building). */
    private function syncGrid(): void
    {
        $rows = $this->design['rows'] ?? null;
        $this->design['rows'] = TemplateDesigner::cleanGrid($rows ?: TemplateDesigner::defaultGrid($this->design['items']), $this->design['items'], true);
    }

    /** Where an item sits in the rows: [row, column, position], or null. */
    private function place(string $id): ?array
    {
        foreach ($this->design['rows'] ?? [] as $r => $row) {
            foreach ($row['cells'] as $c => $cell) {
                $p = array_search($id, $cell, true);
                if ($p !== false) {
                    return [$r, $c, $p];
                }
            }
        }

        return null;
    }

    private function takeOut(string $id): void
    {
        foreach ($this->design['rows'] as $r => $row) {
            foreach ($row['cells'] as $c => $cell) {
                $this->design['rows'][$r]['cells'][$c] = array_values(array_filter($cell, fn ($x) => $x !== $id));
            }
        }
    }

    private function putIn(string $id, int $row, int $column, ?int $position = null): void
    {
        $row = max(0, min($row, count($this->design['rows']) - 1));
        $column = max(0, min($column, $this->design['rows'][$row]['columns'] - 1));
        $cell = $this->design['rows'][$row]['cells'][$column] ?? [];
        $position = $position === null ? count($cell) : max(0, min($position, count($cell)));
        array_splice($cell, $position, 0, [$id]);
        $this->design['rows'][$row]['cells'][$column] = array_values($cell);
    }

    /** The QR code clicked on the page: its settings show on the right. */
    public function selectQr(): void
    {
        $this->selected = null;
        $this->qrSelected = true;
    }

    /** The QR code to a corner of the page. */
    public function qrCorner(string $corner, TemplateDesigner $designer): void
    {
        $this->selectQr();
        if (! in_array($corner, ['top-left', 'top-right', 'bottom-left', 'bottom-right'], true)) {
            return;
        }
        $qr = $this->qr();
        $this->design['qr'] = array_merge($qr, $designer->qrInCorner($corner, $qr['size'], $this->design['letterhead'] ?? null));
    }

    /** The QR code back to the letterhead's standard spot (top left, as on the ZESCO memo). */
    public function qrStandard(TemplateDesigner $designer): void
    {
        $this->design['qr'] = $designer->standardQr($this->design['letterhead'] ?? null);
    }

    /** The QR code dragged on the page, in mm from the left and top. */
    public function placeQr(float $x, float $y): void
    {
        $this->selectQr();
        $this->design['qr'] = TemplateDesigner::cleanQr(array_merge($this->qr(), ['x' => $x, 'y' => $y])) ?? $this->qr();
    }

    /** @return array{page: string, x: float, y: float, size: float} */
    private function qr(): array
    {
        return TemplateDesigner::cleanQr($this->design['qr'] ?? null) ?? app(TemplateDesigner::class)->standardQr($this->design['letterhead'] ?? null);
    }

    public function updatedDesign($value, $key): void
    {
        // Size and page typed or picked in the settings: kept to what prints well.
        if (str_starts_with((string) $key, 'qr')) {
            $this->design['qr'] = TemplateDesigner::cleanQr($this->design['qr'] ?? null) ?? $this->qr();

            return;
        }
        if ($key !== 'layout') {
            return;
        }
        // Memo, Letter and Blank are separate pages: each keeps its own lines while another is shown,
        // and comes back as it was left. The first time: the memo's lines, the letter's lines, or an empty page.
        $from = $this->layoutBefore;
        $to = (string) $this->design['layout'];
        if ($from === $to) {
            return;
        }
        $pages = ['memo', 'letter', 'custom'];
        if (in_array($from, $pages, true)) {
            $this->otherPage[$from] = ['items' => $this->design['items'], 'rows' => $this->design['rows'] ?? null];
        }
        if (in_array($to, $pages, true) && (in_array($from, $pages, true) || $to === 'custom' || isset($this->otherPage[$to]))) {
            $page = $this->otherPage[$to] ?? match ($to) {
                'custom' => ['items' => [], 'rows' => [TemplateDesigner::newRow(1)]],
                'letter' => ['items' => TemplateDesigner::letterItems(), 'rows' => null],
                default => ['items' => TemplateDesigner::starter()['items'], 'rows' => null],
            };
            $this->design['items'] = $page['items'];
            $this->design['rows'] = $to === 'custom' ? ($page['rows'] ?: [TemplateDesigner::newRow(1)]) : null;
        } elseif (! empty($this->design['rows'])) {
            // An older Form or Report template: the same lines, in the order the rows gave them.
            $order = array_flip(array_merge(...array_map(fn ($row) => array_merge(...$row['cells']), $this->design['rows'])));
            $items = $this->design['items'];
            usort($items, fn ($a, $b) => ($order[$a['id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? PHP_INT_MAX));
            $this->design['items'] = array_values($items);
            $this->design['rows'] = null;
        }
        $this->selected = null;
        if ($this->isCustom()) {
            $this->syncGrid();
        }
    }

    /**
     * The pages of the layouts not shown (Memo, Letter, Blank), kept while another is built.
     * Only the layout chosen when saving is saved.
     *
     * @var array<string, array{items: array, rows: ?array}>
     */
    public array $otherPage = [];

    /** Custom layout: a new row of 1, 2 or 3 columns at the end. */
    public function addRow(int $columns = 1): void
    {
        $this->syncGrid();
        $this->design['rows'][] = TemplateDesigner::newRow($columns);
    }

    /** Custom layout: a row's number of columns; items in columns taken away move to the last one. */
    public function setRowColumns(int $row, int $columns): void
    {
        $this->syncGrid();
        if (! isset($this->design['rows'][$row])) {
            return;
        }
        $this->design['rows'][$row]['columns'] = max(1, min(3, $columns));
        $this->syncGrid();
    }

    public function toggleRowLine(int $row): void
    {
        if (isset($this->design['rows'][$row])) {
            $this->design['rows'][$row]['line'] = ! ($this->design['rows'][$row]['line'] ?? false);
        }
    }

    public function moveRow(int $row, int $direction): void
    {
        $this->syncGrid();
        $to = $row + ($direction < 0 ? -1 : 1);
        if (! isset($this->design['rows'][$row], $this->design['rows'][$to])) {
            return;
        }
        [$this->design['rows'][$row], $this->design['rows'][$to]] = [$this->design['rows'][$to], $this->design['rows'][$row]];
    }

    /** Custom layout: takes away a row and the items in it. */
    public function removeRow(int $row): void
    {
        $this->syncGrid();
        if (! isset($this->design['rows'][$row])) {
            return;
        }
        $ids = array_merge(...$this->design['rows'][$row]['cells']);
        $this->design['items'] = array_values(array_filter($this->design['items'], fn ($item) => ! in_array($item['id'], $ids, true)));
        unset($this->design['rows'][$row]);
        $this->design['rows'] = array_values($this->design['rows']);
        $this->selected = null;
        $this->resetErrorBag();
    }

    /** Custom layout: an item from the palette dropped into a column of a row. */
    public function addItemTo(string $kind, ?string $type, int $row, int $column, int $position): void
    {
        $this->addItem($kind, $type, $position, $row, $column);
    }

    /** Custom layout: an item dragged into a column of a row. */
    public function moveItemTo(int $index, int $row, int $column, int $position): void
    {
        $this->syncGrid();
        $id = $this->design['items'][$index]['id'] ?? null;
        if ($id === null || ! isset($this->design['rows'][$row])) {
            return;
        }
        $from = $this->place($id);
        if ($from && $from[0] === $row && $from[1] === $column && $from[2] < $position) {
            $position--;
        }
        $this->takeOut($id);
        $this->putIn($id, $row, $column, $position);
        $this->selected = $index;
    }

    /** Adds an item from the palette, at a place on the page (the end when not given). */
    public function addItem(string $kind, ?string $type = null, ?int $at = null, ?int $row = null, ?int $column = null): void
    {
        if (! $this->canAdd($kind, $type)) {
            return;
        }

        $item = TemplateDesigner::newItem($kind, $type);

        // Custom layout: into the column it was dropped in, or a new row at the end.
        if ($this->isCustom()) {
            $this->syncGrid();
            $this->design['items'][] = $item;
            if ($row !== null && isset($this->design['rows'][$row])) {
                $this->putIn($item['id'], $row, (int) $column, $at);
            } else {
                $this->design['rows'][] = TemplateDesigner::newRow(1, [$item['id']]);
            }
            $this->selected = count($this->design['items']) - 1;
            $this->resetErrorBag();

            return;
        }

        $items = $this->design['items'];
        $at = $at === null ? count($items) : max(0, min($at, count($items)));
        array_splice($items, $at, 0, [$item]);
        $this->design['items'] = array_values($items);
        $this->selected = $at;
        $this->resetErrorBag();
    }

    /** Moves an item to a place on the page (the place before the move). */
    public function moveItem(int $from, int $to): void
    {
        $items = $this->design['items'];
        if (! isset($items[$from])) {
            return;
        }

        $to = max(0, min($to, count($items)));
        $item = $items[$from];
        array_splice($items, $from, 1);
        if ($to > $from) {
            $to--;
        }
        array_splice($items, $to, 0, [$item]);
        $this->design['items'] = array_values($items);
        $this->selected = $to;
    }

    public function moveUp(int $index): void
    {
        if ($this->isCustom()) {
            $this->stepInGrid($index, -1);

            return;
        }
        if ($index > 0) {
            $this->moveItem($index, $index - 1);
        }
    }

    public function moveDown(int $index): void
    {
        if ($this->isCustom()) {
            $this->stepInGrid($index, 1);

            return;
        }
        if ($index < count($this->design['items']) - 1) {
            $this->moveItem($index, $index + 2);
        }
    }

    /** Custom layout ↑/↓: within its column, or into the row above or below. */
    private function stepInGrid(int $index, int $direction): void
    {
        $this->syncGrid();
        $id = $this->design['items'][$index]['id'] ?? null;
        $at = $id ? $this->place($id) : null;
        if (! $at) {
            return;
        }
        [$r, $c, $p] = $at;
        $cell = $this->design['rows'][$r]['cells'][$c];

        if (isset($cell[$p + $direction])) {
            [$cell[$p], $cell[$p + $direction]] = [$cell[$p + $direction], $cell[$p]];
            $this->design['rows'][$r]['cells'][$c] = $cell;
        } elseif (isset($this->design['rows'][$r + $direction])) {
            $this->takeOut($id);
            $this->putIn($id, $r + $direction, $c, $direction < 0 ? null : 0);
        }
        $this->selected = $index;
    }

    public function duplicate(int $index): void
    {
        $item = $this->design['items'][$index] ?? null;
        if (! $item || ! in_array($item['kind'], ['question', 'section', 'note', 'text', 'line', 'space', 'signline'], true)) {
            return;
        }

        $copy = array_merge($item, ['id' => TemplateDesigner::newItem($item['kind'], $item['type'])['id'], 'name' => null]);
        if (in_array($item['kind'], ['question', 'section'], true)) {
            $copy['label'] = trim($item['label'].' (copy)');
        }

        // Custom layout: the copy goes just under the original, in the same column.
        if ($this->isCustom()) {
            $this->syncGrid();
            $this->design['items'][] = $copy;
            [$r, $c, $p] = $this->place($item['id']) ?? [count($this->design['rows']) - 1, 0, null];
            $this->putIn($copy['id'], $r, $c, $p === null ? null : $p + 1);
            $this->selected = count($this->design['items']) - 1;

            return;
        }
        $items = $this->design['items'];
        array_splice($items, $index + 1, 0, [$copy]);
        $this->design['items'] = array_values($items);
        $this->selected = $index + 1;
    }

    public function remove(int $index): void
    {
        $items = $this->design['items'];
        $id = $items[$index]['id'] ?? null;
        unset($items[$index]);
        $this->design['items'] = array_values($items);
        if ($id && $this->isCustom() && ! empty($this->design['rows'])) {
            $this->takeOut($id);
        }
        $this->selected = null;
        $this->resetErrorBag();
    }

    public function select(?int $index): void
    {
        $this->qrSelected = false;
        $this->selected = $index !== null && isset($this->design['items'][$index]) ? $index : null;
    }

    /** Standard lines and the signature block go on a template once. */
    public function canAdd(string $kind, ?string $type = null): bool
    {
        $items = collect($this->design['items'] ?? []);

        return match ($kind) {
            'standard' => array_key_exists((string) $type, TemplateDesigner::STANDARD) && ! $items->contains(fn ($i) => $i['kind'] === 'standard' && $i['name'] === $type),
            'signature' => ! $items->contains('kind', 'signature'),
            'question' => array_key_exists((string) $type, TemplateDesigner::QUESTION_TYPES),
            'section', 'note', 'text', 'line', 'space', 'pagebreak', 'signline' => true,
            default => false,
        } && $items->count() < TemplateDesigner::MAX_ITEMS;
    }

    /**
     * "Preview": the design as the exact PDF, with sample details, without
     * saving. The browser shows it in a window over the builder.
     */
    public function preview(TemplateDesigner $designer): void
    {
        $this->resetErrorBag();
        $template = $this->templateId ? PdtsDocumentTemplate::find($this->templateId) : null;

        try {
            $pdf = $designer->previewPdf($this->design, trim($this->name) !== '' ? trim($this->name) : 'Template', $template);
        } catch (ValidationException $e) {
            $first = array_key_first($e->errors());
            if (preg_match('/^design\.items\.(\d+)/', (string) $first, $m)) {
                $this->selected = (int) $m[1];
            }
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->addError('design', 'The preview could not be made. Try again, or save and use "Try it".');

            return;
        }

        if ($pdf === null) {
            $this->addError('design', 'This server cannot make PDFs yet (LibreOffice is not installed), so there is no preview.');

            return;
        }

        $token = Str::random(40);
        Cache::put('pdts.builder-preview.'.$token, ['user' => Auth::id(), 'pdf' => base64_encode($pdf)], now()->addMinutes(10));
        $this->dispatch('builder-preview', url: route('pdts.organization.document-templates.builder-preview', $token));
    }

    public function save(TemplateDesigner $designer)
    {
        $this->resetErrorBag();
        try {
            $details = $this->validate([
                'name' => ['required', 'string', 'max:150'],
                'description' => ['nullable', 'string', 'max:500'],
                'documentTypeId' => ['nullable', 'exists:pdts_document_types,id'],
            ], ['name.required' => 'Give the template a name.']);
        } catch (ValidationException $e) {
            // These are template settings: show them on the right.
            $this->selected = null;
            throw $e;
        }

        $template = $this->templateId ? PdtsDocumentTemplate::findOrFail($this->templateId) : null;

        try {
            $saved = $designer->save($template, [
                'name' => trim($details['name']),
                'description' => trim((string) $details['description']),
                'document_type_id' => $details['documentTypeId'] !== '' ? (int) $details['documentTypeId'] : null,
            ], $this->design, Auth::id());
        } catch (ValidationException $e) {
            // Show the first problem on its item.
            $first = array_key_first($e->errors());
            if (preg_match('/^design\.items\.(\d+)/', (string) $first, $m)) {
                $this->selected = (int) $m[1];
            }
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->addError('design', 'The template could not be saved. Try again, or contact ICT.');

            return null;
        }

        $inUse = $template && $saved->id !== $template->id;
        session()->flash('success', $inUse
            ? "Saved as version {$saved->version}. Documents already made keep version {$template->version}."
            : 'Template saved. Check it and try it with sample details below.');

        return $this->redirectRoute('pdts.organization.document-templates.review', $saved);
    }

    public function render()
    {
        $template = $this->templateId ? PdtsDocumentTemplate::withCount('documents')->find($this->templateId) : null;
        $designer = app(TemplateDesigner::class);
        $letterhead = $designer->letterheadPreview($this->design['letterhead'] ?? null);
        $items = $this->design['items'] ?? [];

        if ($this->isCustom()) {
            $this->syncGrid();
        }

        // This request's change is recorded after the page is drawn: count it already.
        $history = $this->history();
        $changing = ! $this->restoring && $this->designBefore !== null && $this->designBefore !== $this->design;

        return view('livewire.pdts.organization.template-builder', [
            'canUndo' => $history['undo'] !== [] || $changing,
            'canRedo' => $history['redo'] !== [] && ! $changing,
            'grid' => $this->isCustom() ? $this->design['rows'] : [],
            'indexById' => array_flip(array_column($items, 'id')),
            // Only letterheads really different from the standard ZESCO one; none: the choice is not shown.
            'letterheads' => TemplateDesigner::otherLetterheads(),
            'letterhead' => $letterhead,
            // On a letterhead in memo style, TO sits beside FROM and REF beside DATE, as in the ZESCO memo.
            'rows' => $letterhead && ($this->design['layout'] ?? 'memo') === 'memo'
                ? TemplateDesigner::memoRows($items)
                : array_map(fn ($i) => [$i], array_keys($items)),
            'template' => $template,
            'types' => PdtsDocumentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'organisation' => (string) config('pdts.builder.organisation', 'ZESCO Limited'),
        ])->layout('layouts.master', ['title' => $template ? 'Edit '.$template->name : 'Build a template']);
    }
}
