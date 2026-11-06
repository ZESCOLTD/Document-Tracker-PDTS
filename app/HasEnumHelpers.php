<?php

namespace App;

trait HasEnumHelpers
{
    public function label(): string
    {
        return $this->name;
    }

    public function color(): string
    {
        return 'gray';
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [
                $case->value => $case->label(),
            ])
            ->toArray();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
