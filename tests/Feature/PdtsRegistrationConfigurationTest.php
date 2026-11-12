<?php

use App\Livewire\Pdts\Documents\Registration;
use App\Livewire\Pdts\Organization\DocumentTypes;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use Database\Seeders\PdtsAccessSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('private');
    $this->seed(PdtsAccessSeeder::class);

    $this->admin = User::factory()->create()->assignRole('System Administrator');
    $this->employee = User::factory()->create()->assignRole('Originating Employee');

    $this->memo = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true]);
    $this->ict = PdtsDirectorate::create(['name' => 'ICT', 'is_active' => true]);
    $this->fid = PdtsDirectorate::create(['name' => 'Finance and Investments', 'is_active' => true]);
    $this->finance = PdtsDepartment::create(['name' => 'Finance', 'directorate_id' => $this->fid->id, 'is_active' => true]);
    $this->ops = PdtsDepartment::create(['name' => 'Operations', 'directorate_id' => $this->ict->id, 'is_active' => true]);
    $this->accounts = PdtsOffice::create(['name' => 'Accounts', 'department_id' => $this->finance->id, 'is_active' => true]);
    $this->depot = PdtsOffice::create(['name' => 'Depot', 'department_id' => $this->ops->id, 'is_active' => true]);
});

function fillRegistration($component, array $overrides = [])
{
    $values = [
        'title' => 'Budget request',
        'subject' => 'Q4 budget',
        'document_type_id' => (string) test()->memo->id,
        'directorate_id' => (string) test()->fid->id,
        'department_id' => (string) test()->finance->id,
        'office_id' => (string) test()->accounts->id,
        'attachment' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF"),
        ...$overrides,
    ];

    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

describe('registration form', function () {
    test('offers active directorates, then the chosen directorate\'s departments, then that department\'s offices', function () {
        PdtsDocumentType::create(['name' => 'Retired type', 'is_active' => false]);
        PdtsDirectorate::create(['name' => 'Closed directorate', 'is_active' => false]);
        PdtsDepartment::create(['name' => 'Insurance', 'directorate_id' => $this->fid->id, 'is_active' => true]);
        PdtsDepartment::create(['name' => 'Closed department', 'directorate_id' => $this->fid->id, 'is_active' => false]);
        PdtsOffice::create(['name' => 'Closed office', 'department_id' => $this->finance->id, 'is_active' => false]);

        Livewire::actingAs($this->employee)
            ->test(Registration::class)
            ->assertViewHas('documentTypes', fn ($types) => $types->pluck('name')->all() === ['Memorandum'])
            ->assertViewHas('directorates', fn ($directorates) => $directorates->pluck('name')->all() === ['Finance and Investments', 'ICT'])
            ->assertViewHas('departments', fn ($departments) => $departments->isEmpty())
            ->assertViewHas('offices', fn ($offices) => $offices->isEmpty())
            ->set('directorate_id', (string) $this->fid->id)
            ->assertViewHas('departments', fn ($departments) => $departments->pluck('name')->all() === ['Finance', 'Insurance'])
            ->set('department_id', (string) $this->finance->id)
            ->assertViewHas('offices', fn ($offices) => $offices->pluck('name')->all() === ['Accounts']);
    });

    test('saves the chosen directorate, department and office names on the document', function () {
        fillRegistration(Livewire::actingAs($this->employee)->test(Registration::class))
            ->call('save')
            ->assertHasNoErrors();

        $document = PdtsDocument::sole();
        expect($document->originating_directorate)->toBe('Finance and Investments')
            ->and($document->originating_department)->toBe('Finance')
            ->and($document->originating_office)->toBe('Accounts');
    });

    test('changing the directorate clears the department and office', function () {
        Livewire::actingAs($this->employee)
            ->test(Registration::class)
            ->set('directorate_id', (string) $this->fid->id)
            ->set('department_id', (string) $this->finance->id)
            ->set('office_id', (string) $this->accounts->id)
            ->set('directorate_id', (string) $this->ict->id)
            ->assertSet('department_id', '')
            ->assertSet('office_id', '');
    });

    test('refuses a department from another directorate', function () {
        fillRegistration(Livewire::actingAs($this->employee)->test(Registration::class), [
            'directorate_id' => (string) $this->ict->id,
            'department_id' => (string) $this->finance->id,
            'office_id' => (string) $this->accounts->id,
        ])->call('save')->assertHasErrors('department_id');

        expect(PdtsDocument::count())->toBe(0);
    });

    test('without a department only the directorate\'s own offices show; with one only the department\'s', function () {
        $director = PdtsOffice::create(['name' => 'Director', 'directorate_id' => $this->fid->id, 'is_head' => true, 'is_active' => true]);
        PdtsOffice::create(['name' => 'Senior Manager', 'department_id' => $this->finance->id, 'is_head' => true, 'is_active' => true]);

        Livewire::actingAs($this->employee)
            ->test(Registration::class)
            ->set('directorate_id', (string) $this->fid->id)
            ->assertViewHas('offices', fn ($offices) => $offices->pluck('name')->all() === ['Director'])
            ->set('department_id', (string) $this->finance->id)
            ->assertViewHas('offices', fn ($offices) => $offices->pluck('name')->all() === ['Senior Manager', 'Accounts'])
            ->set('department_id', '')
            ->assertViewHas('offices', fn ($offices) => $offices->pluck('id')->all() === [$director->id]);
    });

    test('registers a document from the Director\'s office with no department', function () {
        $director = PdtsOffice::create(['name' => 'Director', 'directorate_id' => $this->fid->id, 'is_head' => true, 'is_active' => true]);

        fillRegistration(Livewire::actingAs($this->employee)->test(Registration::class), [
            'department_id' => '',
            'office_id' => (string) $director->id,
        ])->call('save')->assertHasNoErrors();

        expect(PdtsDocument::sole())
            ->originating_directorate->toBe('Finance and Investments')
            ->originating_department->toBeNull()
            ->originating_office->toBe('Director');
    });

    test('refuses a department office when no department is chosen, and a directorate office when one is', function () {
        $director = PdtsOffice::create(['name' => 'Director', 'directorate_id' => $this->fid->id, 'is_active' => true]);

        fillRegistration(Livewire::actingAs($this->employee)->test(Registration::class), ['department_id' => ''])
            ->call('save')
            ->assertHasErrors('office_id');

        fillRegistration(Livewire::actingAs($this->employee)->test(Registration::class), ['office_id' => (string) $director->id])
            ->call('save')
            ->assertHasErrors('office_id');

        expect(PdtsDocument::count())->toBe(0);
    });

    test('starts with the registrar\'s directorate-level office selected', function () {
        $director = PdtsOffice::create(['name' => 'Director', 'directorate_id' => $this->fid->id, 'is_active' => true]);
        $this->employee->pdtsOfficeAssignments()->create([
            'office_id' => $director->id, 'starts_at' => now()->subMonth(), 'is_primary' => true,
        ]);

        Livewire::actingAs($this->employee)
            ->test(Registration::class)
            ->assertSet('directorate_id', (string) $this->fid->id)
            ->assertSet('department_id', '')
            ->assertSet('office_id', (string) $director->id);
    });

    test('changing the department clears the office', function () {
        Livewire::actingAs($this->employee)
            ->test(Registration::class)
            ->set('department_id', (string) $this->finance->id)
            ->set('office_id', (string) $this->accounts->id)
            ->set('department_id', (string) $this->ops->id)
            ->assertSet('office_id', '');
    });

    test('refuses an office from another department', function () {
        fillRegistration(Livewire::actingAs($this->employee)->test(Registration::class), ['office_id' => (string) $this->depot->id])
            ->call('save')
            ->assertHasErrors('office_id');

        expect(PdtsDocument::count())->toBe(0);
    });

    test('refuses an inactive department or type', function () {
        $this->finance->update(['is_active' => false]);
        $this->memo->update(['is_active' => false]);

        fillRegistration(Livewire::actingAs($this->employee)->test(Registration::class))
            ->call('save')
            ->assertHasErrors(['department_id', 'document_type_id']);
    });

    test('starts with the registrar\'s own office selected', function () {
        $this->employee->pdtsOfficeAssignments()->create([
            'office_id' => $this->depot->id, 'starts_at' => now()->subMonth(), 'is_primary' => true,
        ]);

        Livewire::actingAs($this->employee)
            ->test(Registration::class)
            ->assertSet('directorate_id', (string) $this->ict->id)
            ->assertSet('department_id', (string) $this->ops->id)
            ->assertSet('office_id', (string) $this->depot->id);
    });

    test('says what to set up when there are no directorates', function () {
        PdtsOffice::query()->delete();
        PdtsDepartment::query()->delete();
        PdtsDirectorate::query()->delete();

        Livewire::actingAs($this->admin)
            ->test(Registration::class)
            ->assertSee('be registered until there are active')
            ->assertSee(route('pdts.organization.directorates'));
    });
});

describe('configuration pop-ups', function () {
    test('the add form opens in a pop-up, closes on save and reopens for editing', function (string $component, array $fields, string $title) {
        $page = Livewire::actingAs($this->admin)->test($component)
            ->assertSet('showForm', false)
            ->assertDontSeeHtml('id="form-modal-title"')
            ->call('openCreate')
            ->assertSet('showForm', true)
            ->assertSeeHtml('id="form-modal-title">'.$title.'</h5>');

        foreach ($fields as $field => $value) {
            $page->set($field, is_callable($value) ? $value() : $value);
        }

        $page->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $page->call('openCreate')->call('cancelEdit')->assertSet('showForm', false);
    })->with([
        'directorates' => [\App\Livewire\Pdts\Organization\Directorates::class, ['name' => 'Legal Services'], 'Add directorate'],
        'departments' => [\App\Livewire\Pdts\Organization\Departments::class, ['name' => 'Legal', 'directorateId' => fn () => (string) test()->ict->id], 'Add department'],
        'offices' => [\App\Livewire\Pdts\Organization\Offices::class, ['name' => 'Registry', 'directorateId' => fn () => (string) test()->fid->id, 'departmentId' => fn () => (string) test()->finance->id], 'Add office'],
        'document types' => [DocumentTypes::class, ['name' => 'Invoice'], 'Add document type'],
    ]);

    test('assigning an employee happens in a pop-up', function () {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Pdts\Organization\EmployeeAssignments::class)
            ->assertSet('showForm', false)
            ->assertDontSeeHtml('id="form-modal-title"')
            ->call('openCreate')
            ->assertSeeHtml('id="form-modal-title">Assign employee to office</h5>')
            ->set('userId', (string) $this->employee->id)
            ->set('officeId', (string) $this->accounts->id)
            ->call('assign')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSet('userId', '')
            ->assertSee('End assignment');

        expect($this->employee->currentPdtsOfficeAssignments()->sole()->office_id)->toBe($this->accounts->id);
    });

    test('row actions are in a dropdown', function () {
        $this->ops->update(['is_active' => false]);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Pdts\Organization\Departments::class)
            ->assertSeeHtml('data-toggle="dropdown"')
            ->assertSeeHtml('class="dropdown-item"')
            ->assertSeeInOrder(['Finance', 'Edit', 'Deactivate', 'Operations', 'Edit', 'Activate']);

        // Delete is offered only for a type no document uses.
        PdtsDocument::factory()->create(['document_type_id' => $this->memo->id]);
        PdtsDocumentType::create(['name' => 'Unused', 'is_active' => true]);

        Livewire::actingAs($this->admin)->test(DocumentTypes::class)
            ->assertSeeInOrder(['Memorandum', 'Deactivate', 'Unused', 'Deactivate', 'Delete']);
    });

    test('editing opens the pop-up with the saved values', function () {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Pdts\Organization\Departments::class)
            ->call('edit', $this->finance->id)
            ->assertSet('showForm', true)
            ->assertSet('name', 'Finance')
            ->assertSee('Edit department');
    });

    test('a validation error keeps the pop-up open', function () {
        Livewire::actingAs($this->admin)->test(DocumentTypes::class)
            ->call('openCreate')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors('name')
            ->assertSet('showForm', true);
    });
});

describe('offices configuration', function () {
    test('adds the Director\'s office directly under a directorate', function () {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Pdts\Organization\Offices::class)
            ->set('name', 'Director')
            ->set('directorateId', (string) $this->fid->id)
            ->set('isHead', true)
            ->call('save')
            ->assertHasNoErrors();

        expect(PdtsOffice::where('name', 'Director')->sole())
            ->department_id->toBeNull()
            ->directorate_id->toBe($this->fid->id)
            ->is_head->toBeTrue();
    });

    test('a department office does not also store a directorate', function () {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Pdts\Organization\Offices::class)
            ->set('name', 'Senior Manager')
            ->set('directorateId', (string) $this->fid->id)
            ->set('departmentId', (string) $this->finance->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(PdtsOffice::where('name', 'Senior Manager')->sole())
            ->department_id->toBe($this->finance->id)
            ->directorate_id->toBeNull();
    });

    test('allows one head per directorate and one per department', function () {
        PdtsOffice::create(['name' => 'Director', 'directorate_id' => $this->fid->id, 'is_head' => true, 'is_active' => true]);
        PdtsOffice::create(['name' => 'Senior Manager', 'department_id' => $this->finance->id, 'is_head' => true, 'is_active' => true]);

        $page = Livewire::actingAs($this->admin)->test(\App\Livewire\Pdts\Organization\Offices::class);

        $page->set('name', 'Deputy Director')->set('directorateId', (string) $this->fid->id)->set('isHead', true)
            ->call('save')->assertHasErrors('isHead');

        $page->call('cancelEdit')
            ->set('name', 'Manager')->set('directorateId', (string) $this->fid->id)->set('departmentId', (string) $this->finance->id)->set('isHead', true)
            ->call('save')->assertHasErrors('isHead');

        // A different department can have its own head.
        $page->call('cancelEdit')
            ->set('name', 'Senior Manager')->set('directorateId', (string) $this->ict->id)->set('departmentId', (string) $this->ops->id)->set('isHead', true)
            ->call('save')->assertHasNoErrors();
    });

    test('refuses a department from another directorate', function () {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Pdts\Organization\Offices::class)
            ->set('name', 'Registry')
            ->set('directorateId', (string) $this->ict->id)
            ->set('departmentId', (string) $this->finance->id)
            ->call('save')
            ->assertHasErrors('departmentId');
    });

    test('changing the directorate clears the department', function () {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Pdts\Organization\Offices::class)
            ->set('directorateId', (string) $this->fid->id)
            ->set('departmentId', (string) $this->finance->id)
            ->set('directorateId', (string) $this->ict->id)
            ->assertSet('departmentId', '');
    });
});

describe('document types configuration', function () {
    test('is only open to people who manage document types', function () {
        $this->actingAs($this->admin)->get(route('pdts.organization.document-types'))->assertOk();
        $this->actingAs($this->employee)->get(route('pdts.organization.document-types'))->assertForbidden();
    });

    test('adds, renames and deactivates a type', function () {
        $page = Livewire::actingAs($this->admin)->test(DocumentTypes::class)
            ->set('name', 'Invoice')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = PdtsDocumentType::where('name', 'Invoice')->sole();

        $page->call('edit', $invoice->id)->set('name', 'Supplier Invoice')->call('save')->assertHasNoErrors();
        $page->call('toggleActive', $invoice->id);

        expect($invoice->fresh())->name->toBe('Supplier Invoice')->is_active->toBeFalse();
    });

    test('refuses a duplicate name', function () {
        Livewire::actingAs($this->admin)->test(DocumentTypes::class)
            ->set('name', 'Memorandum')
            ->call('save')
            ->assertHasErrors('name');
    });

    test('deletes an unused type but keeps one that documents use', function () {
        $unused = PdtsDocumentType::create(['name' => 'Unused', 'is_active' => true]);
        PdtsDocument::factory()->create(['document_type_id' => $this->memo->id]);

        Livewire::actingAs($this->admin)->test(DocumentTypes::class)
            ->call('delete', $unused->id)
            ->call('delete', $this->memo->id);

        expect(PdtsDocumentType::find($unused->id))->toBeNull()
            ->and(PdtsDocumentType::find($this->memo->id))->not->toBeNull();
    });
});
