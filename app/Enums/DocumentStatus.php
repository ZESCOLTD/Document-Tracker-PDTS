<?php

namespace App\Enums;

use App\HasEnumHelpers;

enum DocumentStatus: string
{
    use HasEnumHelpers;

    // An internal document generated from a template, not yet printed, signed and scanned.
    case Draft = 'draft';
    case Registered = 'registered';
    case AwaitingReceipt = 'awaiting_receipt';
    case Received = 'received';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Registered => 'Registered',
            self::AwaitingReceipt => 'Awaiting Receipt',
            self::Received => 'Received / In Progress',
            self::Completed => 'Completed',
        };
    }

    /**
     * Everyday wording shown to users on the tracking pages.
     * label() keeps the SRS names for reports and other modules.
     */
    public function plainLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft, signed scan not uploaded',
            self::Registered => 'Not sent',
            self::AwaitingReceipt => 'Sent, not received yet',
            self::Received => 'Received',
            self::Completed => 'Completed',
        };
    }
}
