<?php

namespace App\Enums;

use App\HasEnumHelpers;

enum DocumentStatus: string
{
    use HasEnumHelpers;

    case Registered = 'registered';
    case AwaitingReceipt = 'awaiting_receipt';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::AwaitingReceipt => 'Awaiting Receipt',
            self::Received => 'Received / In Progress',
        };
    }

    /**
     * Everyday wording shown to users on the tracking pages.
     * label() keeps the SRS names for reports and other modules.
     */
    public function plainLabel(): string
    {
        return match ($this) {
            self::Registered => 'Not sent',
            self::AwaitingReceipt => 'Sent, not received yet',
            self::Received => 'Received',
        };
    }
}
