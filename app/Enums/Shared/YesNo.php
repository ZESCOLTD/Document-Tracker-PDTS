<?php

namespace App\Enums\Shared;

use App\HasEnumHelpers;

enum YesNo: int
{
    use HasEnumHelpers;

    case Yes = 1;
    case No = 2;

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Yes',
            self::No => 'No',
        };
    }
}
