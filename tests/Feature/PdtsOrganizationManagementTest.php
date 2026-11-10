<?php

use App\Livewire\Pdts\Organization\Departments;
use App\Livewire\Pdts\Organization\EmployeeAssignments;
use App\Models\PdtsDepartment;
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

test('departments can be created with a hierarchy and cycles are rejected', function () {
    Livewire::test(Departments::class)
        ->set('code', 'OPS')
        ->set('name', 'Operations')
        ->call('save')
        ->assertHasNoErrors();

    $parent = PdtsDepartment::where('name', 'Operations')->sole();

    Livewire::test(Departments::class)
        ->set('code', 'OPS-HR')
        ->set('name', 'Human Resources')
        ->set('parentDepartmentId', (string) $parent->id)
        ->call('save')
        ->assertHasNoErrors();

    $child = PdtsDepartment::where('name', 'Human Resources')->sole();

    Livewire::test(Departments::class)
        ->call('edit', $parent->id)
        ->set('parentDepartmentId', (string) $child->id)
        ->call('save')
        ->assertHasErrors('parentDepartmentId');

    expect($child->parent_department_id)->toBe($parent->id);
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

    $employee = User::factory()->create(['man_no' => 'ORG-USER-2']);
    $this->actingAs($employee)
        ->get(route('pdts.organization.departments'))
        ->assertForbidden();
});
