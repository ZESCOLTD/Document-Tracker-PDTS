<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyFeature(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    $user = User::factory()->withTwoFactor()->create([
        'man_no' => 'M2001',
    ]);

    $this->post(route('login.store'), [
        'man_no' => $user->man_no,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));
});