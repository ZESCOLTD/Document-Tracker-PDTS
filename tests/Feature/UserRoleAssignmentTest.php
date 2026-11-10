<?php

use App\Livewire\Users\UserEdit;
use App\Livewire\Users\UserIndex;
use App\Models\User;
use Database\Seeders\PdtsAccessSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PdtsAccessSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('System Administrator');
    $this->staff = User::factory()->create(['name' => 'Sam Staff', 'man_no' => 'M2001']);
});

test('the edit page shows the role the user has', function () {
    $this->staff->assignRole('Supervisor');

    Livewire::actingAs($this->admin)
        ->test(UserEdit::class, ['user' => $this->staff])
        ->assertSet('role', 'Supervisor');
});

test('assigning a role on the edit page grants its permissions', function () {
    Livewire::actingAs($this->admin)
        ->test(UserEdit::class, ['user' => $this->staff])
        ->set('role', 'Originating Employee')
        ->call('update')
        ->assertHasNoErrors()
        ->assertRedirect(route('users.index'));

    $staff = $this->staff->fresh();
    expect($staff->getRoleNames()->all())->toBe(['Originating Employee'])
        ->and($staff->can('documents.forward'))->toBeTrue();
});

test('changing the role replaces the old one', function () {
    $this->staff->assignRole('Originating Employee');

    Livewire::actingAs($this->admin)
        ->test(UserEdit::class, ['user' => $this->staff])
        ->set('role', 'Management / Auditor')
        ->call('update')
        ->assertHasNoErrors();

    $staff = $this->staff->fresh();
    expect($staff->getRoleNames()->all())->toBe(['Management / Auditor'])
        ->and($staff->can('documents.forward'))->toBeFalse()
        ->and($staff->can('reports.view'))->toBeTrue();
});

test('choosing no role removes access', function () {
    $this->staff->assignRole('Supervisor');

    Livewire::actingAs($this->admin)
        ->test(UserEdit::class, ['user' => $this->staff])
        ->set('role', '')
        ->call('update')
        ->assertHasNoErrors();

    expect($this->staff->fresh()->roles)->toBeEmpty();
});

test('an unknown role is refused', function () {
    Livewire::actingAs($this->admin)
        ->test(UserEdit::class, ['user' => $this->staff])
        ->set('role', 'Emperor')
        ->call('update')
        ->assertHasErrors('role');

    expect($this->staff->fresh()->roles)->toBeEmpty();
});

test('admins cannot remove their own access to user management', function () {
    Livewire::actingAs($this->admin)
        ->test(UserEdit::class, ['user' => $this->admin])
        ->set('name', 'Ada Renamed')
        ->set('role', 'Originating Employee')
        ->call('update')
        ->assertHasErrors('role')
        ->assertSee('You cannot remove your own access to user management.');

    $admin = $this->admin->fresh();
    expect($admin->hasRole('System Administrator'))->toBeTrue()
        // Nothing else was saved either.
        ->and($admin->name)->toBe('Ada Admin');
});

test('another admin can change an admin role', function () {
    $other = User::factory()->create()->assignRole('System Administrator');

    Livewire::actingAs($other)
        ->test(UserEdit::class, ['user' => $this->admin])
        ->set('role', 'Manager')
        ->call('update')
        ->assertHasNoErrors();

    expect($this->admin->fresh()->getRoleNames()->all())->toBe(['Manager']);
});

test('the users list shows roles, filters by role and counts admins', function () {
    $this->staff->assignRole('Supervisor');
    User::factory()->create(['name' => 'Nora Norole']);

    Livewire::actingAs($this->admin)
        ->test(UserIndex::class)
        ->assertViewHas('adminUsers', 1)
        ->assertSee('Supervisor')
        ->assertSee('No role')
        ->set('roleFilter', 'Supervisor')
        ->assertSee('Sam Staff')
        ->assertDontSee('Nora Norole')
        ->assertDontSee('Ada Admin');
});

test('adding a user from the pop-up can give them a role', function () {
    Livewire::actingAs($this->admin)
        ->test(UserIndex::class)
        ->set('modal_man_no', 'M3001')
        ->set('modal_email', 'new.person@example.com')
        ->set('phrisData', ['con_per_no' => 'M3001', 'name' => 'New Person', 'staff_email' => 'new.person@example.com'])
        ->set('modal_role', 'Supervisor')
        ->call('saveFromModal')
        ->assertHasNoErrors();

    $user = User::where('man_no', 'M3001')->firstOrFail();
    expect($user->getRoleNames()->all())->toBe(['Supervisor'])
        ->and($user->can('documents.acknowledge'))->toBeTrue();
});

test('the users pages load for an admin', function (string $route) {
    $this->actingAs($this->admin)
        ->get(route($route, $route === 'users.index' ? [] : ['user' => $this->staff]))
        ->assertOk();
})->with(['users.index', 'users.edit', 'users.show']);
