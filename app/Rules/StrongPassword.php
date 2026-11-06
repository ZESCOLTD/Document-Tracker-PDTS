<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class StrongPassword implements Rule
{
    public function passes($attribute, $value)
    {
        // Check if passwords match
        if (request()->password !== $value) {
            return false;
        }

        // Check password complexity
        $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';
        return preg_match($passwordRegex, $value);
    }

    public function message()
    {
        return 'Password must have at least 8 characters, including at least one uppercase letter, one lowercase letter, one digit, and one special character, and must match the confirmation.';
    }
}
