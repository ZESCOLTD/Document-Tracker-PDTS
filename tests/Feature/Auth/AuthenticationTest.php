<?php

use App\Models\User;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create([
        'man_no' => 'M001',
    ]);

    $response = $this->post(route('login.store'), [
        'man_no' => $user->man_no,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create([
        'man_no' => 'M002',
    ]);

    $response = $this->post(route('login.store'), [
        'man_no' => $user->man_no,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('man_no');

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyFeature(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->create([
        'man_no' => 'M003',
    ]);

    $response = $this->post(route('login.store'), [
        'man_no' => $user->man_no,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect('/');

    $this->assertGuest();
});