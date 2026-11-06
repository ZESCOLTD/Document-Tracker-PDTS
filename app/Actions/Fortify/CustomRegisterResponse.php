<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Contracts\RegisterResponse;

class CustomRegisterResponse implements RegisterResponse
{
    public function toResponse($request)
    {
        return redirect()->route('login')
            ->with('status', 'Account created successfully. Please log in.');
    }
}
