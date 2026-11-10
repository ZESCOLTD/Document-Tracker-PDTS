<?php

namespace App\Enums;

use App\HasEnumHelpers;

enum MovementStatus: string
{
    use HasEnumHelpers;

    case AwaitingReceipt = 'awaiting_receipt';
    case Acknowledged = 'acknowledged';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingReceipt => 'Awaiting Receipt',
            self::Acknowledged => 'Acknowledged',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
        };
    }
}
