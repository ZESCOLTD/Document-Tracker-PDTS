<?php

use App\Livewire\Pdts\Organization\Departments;
use App\Livewire\Pdts\Organization\Directorates;
use App\Livewire\Pdts\Organization\EmployeeAssignments;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsEmployeeOfficeAssignment;
use App\Models\PdtsOffice;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    foreach (['departments.manage', 'offices.manage', 'employee_assignments.manage'] as $permissionName) {
        Permission::firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
    }

    $this->admin = User::factory()->create(['man_no' => 'ORG-ADMIN']);
    $this->admin->givePermissionTo([
        'departments.manage',
        'offices.manage',
        'employee_assignments.manage',
    ]);
    $this->actingAs($this->admin);
});

test('directorates hold departments, which hold offices', function () {
    Livewire::test(Directorates::class)
        ->set('code', 'ICT')
        ->set('name', 'ICT')
        ->call('save')
        ->assertHasNoErrors();

    $ict = PdtsDirectorate::where('name', 'ICT')->sole();

    foreach (['ERP', 'AI and ML'] as $name) {
        Livewire::test(Departments::class)
            ->set('name', $name)
            ->set('directorateId', (string) $ict->id)
            ->call('save')
            ->assertHasNoErrors();
    }

    expect($ict->departments()->orderBy('name')->pluck('name')->all())->toBe(['AI and ML', 'ERP']);
});

test('the directorate seeder adds each directorate once', function () {
    $this->seed(\Database\Seeders\PdtsDirectorateSeeder::class);
    $this->seed(\Database\Seeders\PdtsDirectorateSeeder::class);

    expect(PdtsDirectorate::pluck('name')->sort()->values()->all())
        ->toBe(collect(\Database\Seeders\PdtsDirectorateSeeder::DIRECTORATES)->sort()->values()->all())
        ->and(PdtsDirectorate::where('is_active', false)->exists())->toBeFalse()
        ->and(PdtsOffice::count())->toBe(count(\Database\Seeders\PdtsDirectorateSeeder::DIRECTORATES))
        ->and(PdtsOffice::where(['name' => 'Director', 'is_head' => true])->whereNull('department_id')->count())
        ->toBe(count(\Database\Seeders\PdtsDirectorateSeeder::DIRECTORATES));
});

test('a department must belong to an active directorate', function () {
    $closed = PdtsDirectorate::create(['name' => 'Closed', 'is_active' => false]);

    Livewire::test(Departments::class)
        ->set('name', 'Insurance')
        ->call('save')
        ->assertHasErrors(['directorateId' => 'required']);

    Livewire::test(Departments::class)
        ->set('name', 'Insurance')
        ->set('directorateId', (string) $closed->id)
        ->call('save')
        ->assertHasErrors('directorateId');

    expect(PdtsDepartment::count())->toBe(0);
});

test('a directorate with active departments cannot be deactivated', function () {
    $this->admin->givePermissionTo(Permission::firstOrCreate(['name' => 'directorates.manage', 'guard_name' => 'web']));
    $directorate = PdtsDirectorate::create(['name' => 'Finance and Investments', 'is_active' => true]);
    $insurance = PdtsDepartment::create(['name' => 'Insurance', 'directorate_id' => $directorate->id, 'is_active' => true]);

    Livewire::test(Directorates::class)->call('toggleActive', $directorate->id);
    expect($directorate->fresh()->is_active)->toBeTrue();

    $insurance->update(['is_active' => false]);
    Livewire::test(Directorates::class)->call('toggleActive', $directorate->id);
    expect($directorate->fresh()->is_active)->toBeFalse();

    // And its department cannot be reactivated while it is inactive.
    Livewire::test(Departments::class)->call('toggleActive', $insurance->id);
    expect($insurance->fresh()->is_active)->toBeFalse();
});

test('a primary office reassignment ends the previous assignment without deleting it', function () {
    $department = PdtsDepartment::create([
        'name' => 'Operations',
        'is_active' => true,
    ]);
    $firstOffice = PdtsOffice::create([
        'name' => 'Lusaka Main Office',
        'department_id' => $department->id,
        'is_active' => true,
    ]);
    $secondOffice = PdtsOffice::create([
        'name' => 'Northern Office',
        'department_id' => $department->id,
        'is_active' => true,
    ]);
    $employee = User::factory()->create(['man_no' => 'ORG-USER-1']);

    Livewire::test(EmployeeAssignments::class)
        ->set('userId', (string) $employee->id)
        ->set('officeId', (string) $firstOffice->id)
        ->set('position', 'Officer')
        ->set('startsAt', now()->format('Y-m-d\TH:i'))
        ->call('assign')
        ->assertHasNoErrors();

    Livewire::test(EmployeeAssignments::class)
        ->set('userId', (string) $employee->id)
        ->set('officeId', (string) $secondOffice->id)
        ->set('position', 'Senior Officer')
        ->set('startsAt', now()->addMinute()->format('Y-m-d\TH:i'))
        ->call('assign')
        ->assertHasNoErrors();

    $assignments = PdtsEmployeeOfficeAssignment::where('user_id', $employee->id)
        ->orderBy('id')
        ->get();

    expect($assignments)->toHaveCount(2)
        ->and($assignments[0]->ends_at)->not->toBeNull()
        ->and($assignments[0]->is_primary)->toBeFalse()
        ->and($assignments[1]->ends_at)->toBeNull()
        ->and($assignments[1]->is_primary)->toBeTrue()
        ->and($assignments[1]->office_id)->toBe($secondOffice->id);
});

test('organization screens require their specific permissions', function () {
    $this->get(route('pdts.organization.departments'))->assertOk();
    $this->get(route('pdts.organization.directorates'))->assertForbidden();

    $this->admin->givePermissionTo(Permission::firstOrCreate(['name' => 'directorates.manage', 'guard_name' => 'web']));
    $this->get(route('pdts.organization.directorates'))->assertOk();

    $employee = User::factory()->create(['man_no' => 'ORG-USER-2']);
    $this->actingAs($employee)
        ->get(route('pdts.organization.departments'))
        ->assertForbidden();
});
