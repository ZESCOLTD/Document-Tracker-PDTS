<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Templates\RichText;
use App\Services\Pdts\Templates\TemplateDesigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
    Permission::firstOrCreate(['name' => 'documents.register', 'guard_name' => 'web']);
    $this->author = User::factory()->create(['man_no' => 'QT-01', 'name' => 'Teddy Sikazwe']);
    $this->author->givePermissionTo('documents.register');
    $this->grace = User::factory()->create(['man_no' => 'QT-02', 'name' => 'Grace Mwila', 'job_title' => 'Director ICT']);
    $this->john = User::factory()->create(['man_no' => 'QT-03', 'name' => 'John Phiri', 'job_title' => 'Manager Finance']);
    $this->mary = User::factory()->create(['man_no' => 'QT-04', 'name' => 'Mary Banda']);
    $this->banned = User::factory()->create(['man_no' => 'QT-05', 'name' => 'Left Company', 'is_banned' => 1]);

    $this->type = PdtsDocumentType::create(['name' => 'Request', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Corporate Services', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'ICT', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'ICT Office', 'department_id' => $this->department->id, 'is_active' => true]);

    // A purchase request with the four new question types, built as an administrator would.
    $design = TemplateDesigner::starter();
    $q = function (string $type, string $label, bool $required = false) {
        $item = TemplateDesigner::newItem('question', $type);
        $item['label'] = $label;
        $item['required'] = $required;

        return $item;
    };
    $design['items'][4] = $q('rich', 'Body', true);
    array_splice($design['items'], 4, 0, [$q('person', 'Supervisor', true), $q('people', 'Committee members'), $q('attachment', 'Quotation', true)]);
    $this->template = app(TemplateDesigner::class)->save(null, ['name' => 'Purchase Request', 'description' => null, 'document_type_id' => $this->type->id], $design, null);
});

function purchaseForm()
{
    return Livewire::actingAs(test()->author)->test(InternalDocument::class)
        ->set('template_id', (string) test()->template->id)
        ->set('to', 'Director ICT')->set('subject', 'Laptops for the ICT office')
        ->set('directorate_id', (string) test()->directorate->id)->set('department_id', (string) test()->department->id)->set('office_id', (string) test()->office->id);
}

test('the builder offers person, several people, attachment and formatted text questions', function () {
    $t = $this->template;
    expect($t->customFields())->toBe(['supervisor' => 'Supervisor', 'committee_members' => 'Committee members', 'quotation' => 'Quotation', 'text_body' => 'Body'])
        ->and($t->fieldSetting('supervisor')['type'])->toBe('person')
        ->and($t->fieldSetting('committee_members')['type'])->toBe('people')
        ->and($t->fieldSetting('quotation')['type'])->toBe('attachment')
        ->and($t->fieldSetting('text_body')['type'])->toBe('rich');
});

test('authors pick people, attach a file and format the text; the document shows them all', function () {
    $form = purchaseForm()
        ->assertSeeHtml('data-test="person-supervisor"')->assertSeeHtml('data-test="rich-text_body"')->assertSeeHtml('data-test="attachment-quotation"')
        ->set('personSearch.supervisor', 'Grac')->assertSee('Grace Mwila')
        ->call('choosePerson', 'supervisor', $this->grace->id)
        ->call('choosePerson', 'committee_members', $this->john->id)
        ->call('choosePerson', 'committee_members', $this->mary->id)
        ->call('choosePerson', 'committee_members', $this->grace->id)
        ->call('removePerson', 'committee_members', $this->grace->id)
        ->call('choosePerson', 'committee_members', $this->banned->id) // not active: ignored
        ->set('customFiles.quotation', UploadedFile::fake()->create('quotation.pdf', 120, 'application/pdf'))
        ->set('custom.text_body', '<p>We need <b>twelve</b> laptops:</p><ul><li>Eight for <i>engineers</i></li><li>Four for interns</li></ul><script>alert(1)</script>');

    expect($form->get('custom.supervisor'))->toBe((string) $this->grace->id)
        ->and($form->get('custom.committee_members'))->toBe($this->john->id.','.$this->mary->id);

    $form->call('save')->assertHasNoErrors();
    $document = PdtsDocument::sole();
    $custom = $document->template_data['custom'];

    expect($custom['text_body'])->toBe('<p>We need <strong>twelve</strong> laptops:</p><ul><li>Eight for <em>engineers</em></li><li>Four for interns</li></ul>')
        ->and($document->attachments)->toHaveCount(1)
        ->and($document->attachments->first()->original_filename)->toBe('quotation.pdf')
        ->and($document->attachments->first()->is_primary)->toBeFalse()
        ->and($custom['quotation'])->toBe((string) $document->attachments->first()->id)
        // A supporting file is never taken for the document itself.
        ->and($document->primaryAttachment)->toBeNull();

    $xml = pdtsZipPart(app(InternalDocuments::class)->wordFile($document)['contents'], 'word/document.xml');
    $text = strip_tags(str_replace(['<w:br/>', '</w:p>'], ["\n", "\n"], $xml));
    expect($text)->toContain('Grace Mwila, Director ICT')
        ->toContain("John Phiri, Manager Finance\nMary Banda")
        ->toContain('Attached: quotation.pdf')
        ->toContain('We need twelve laptops:')
        ->toContain('•')->toContain('Eight for engineers')
        ->not->toContain('${')->not->toContain('alert');
    // Real formatting: "twelve" in bold, "engineers" in italic, list items indented.
    expect($xml)->toMatch('#<w:b/><w:bCs/>(?:(?!</w:r>).)*?twelve#s')
        ->toMatch('#<w:i/><w:iCs/>(?:(?!</w:r>).)*?engineers#s')
        ->toContain('<w:ind w:left="720" w:hanging="360"/>');

    // Editing the draft keeps the file; its name is shown.
    Livewire::actingAs($this->author)->test(InternalDocument::class, ['document' => $document])
        ->assertSee('quotation.pdf')->assertSee('Grace Mwila')->assertSee('Mary Banda');
});

test('required people, files and text are checked, and only active people can be chosen', function () {
    purchaseForm()->set('custom.text_body', '<p>   </p><ul><li> </li></ul>')
        ->call('save')
        ->assertHasErrors(['custom.supervisor', 'customFiles.quotation', 'custom.text_body']);

    purchaseForm()->set('custom.supervisor', (string) $this->banned->id)->call('save')->assertHasErrors(['custom.supervisor']);
    expect(PdtsDocument::count())->toBe(0);
});

test('formatted text keeps only paragraphs, bold, italic and lists', function () {
    expect(RichText::clean('<h2>Title</h2><p style="font-weight:700">Bold by style</p><table><tr><td>A</td></tr></table><img src=x onerror=alert(1)>Text'))
        ->toBe('<p><strong>Title</strong></p><p><strong>Bold by style</strong></p><p>A</p><p>Text</p>')
        ->and(RichText::clean("Line one\n\nLine two"))->toBe('<p>Line one</p><p>Line two</p>')
        ->and(RichText::plain('<ol><li>First</li><li>Second</li></ol>'))->toBe("1. First\n2. Second");
});

test('a table question: the administrator sets the columns, the author adds rows, the document gets a real table', function () {
    $design = TemplateDesigner::starter();
    $table = TemplateDesigner::newItem('question', 'table');
    $table['label'] = 'Items requested';
    $table['columns'] = 'Item, Quantity, Unit cost';
    $table['required'] = true;
    array_splice($design['items'], 4, 0, [$table]);
    $template = app(TemplateDesigner::class)->save(null, ['name' => 'Requisition', 'description' => null, 'document_type_id' => $this->type->id], $design, null);

    expect($template->fieldSetting('text_items_requested'))->toMatchArray(['type' => 'table', 'required' => true, 'columns' => ['Item', 'Quantity', 'Unit cost']]);

    $form = Livewire::actingAs($this->author)->test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->assertSeeHtml('data-test="table-text_items_requested"')->assertSee('Unit cost')
        ->assertSet('tableRows.text_items_requested', [['', '', '']])
        ->set('to', 'Director ICT')->set('subject', 'Laptops')->set('custom.text_body', 'Please approve.')
        ->set('directorate_id', (string) $this->directorate->id)->set('department_id', (string) $this->department->id)->set('office_id', (string) $this->office->id);

    // Required: an empty table is refused.
    $form->call('save')->assertHasErrors('custom.text_items_requested');

    $form->set('tableRows.text_items_requested.0', ['Laptop', '12', 'K 15,000'])
        ->call('addTableRow', 'text_items_requested')->call('addTableRow', 'text_items_requested')
        ->set('tableRows.text_items_requested.1', ['Mouse', '12', 'K 150'])
        ->call('removeTableRow', 'text_items_requested', 2)            // the empty third row
        ->call('save')->assertHasNoErrors();

    $document = PdtsDocument::sole();
    expect(json_decode($document->template_data['custom']['text_items_requested'], true))->toBe([['Laptop', '12', 'K 15,000'], ['Mouse', '12', 'K 150']]);

    $xml = pdtsZipPart(app(InternalDocuments::class)->wordFile($document)['contents'], 'word/document.xml');
    expect($xml)->toContain('<w:tblHeader/>')->not->toContain('${')
        ->and(substr_count($xml, '<w:tr>') + substr_count($xml, '<w:tr '))->toBeGreaterThanOrEqual(3);
    $text = strip_tags(str_replace('</w:tc>', ' | ', $xml));
    expect($text)->toContain('Item | Quantity | Unit cost |')->toContain('Laptop | 12 | K 15,000 |')->toContain('Mouse | 12 | K 150 |');

    // Editing the draft brings the rows back.
    Livewire::actingAs($this->author)->test(InternalDocument::class, ['document' => $document])
        ->assertSet('tableRows.text_items_requested', [['Laptop', '12', 'K 15,000'], ['Mouse', '12', 'K 150']]);
});
