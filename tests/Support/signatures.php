<?php

use App\Models\User;
use App\Services\Pdts\Signatures\SignatureVerification;
use Spatie\Permission\Models\Permission;

/** A member of ICT who may verify signatures. */
function ictVerifier(): User
{
    Permission::firstOrCreate(['name' => SignatureVerification::PERMISSION, 'guard_name' => 'web']);

    $verifier = User::query()->where('man_no', 'ICT-VERIFY')->first()
        ?? User::factory()->create(['man_no' => 'ICT-VERIFY', 'name' => 'Ivy Tembo']);
    $verifier->givePermissionTo(SignatureVerification::PERMISSION);

    return $verifier;
}

/** ICT approves the signature this person is waiting on. */
function ictApprove(User $user): void
{
    $pending = $user->fresh()->pendingSignatureImage();
    app(SignatureVerification::class)->approve($pending->load('user'), ictVerifier());
    $user->refresh(); // the logged-in copy sees the approved signature
}
