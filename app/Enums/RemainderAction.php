<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What happens to the quantity a store could not issue.
 */
enum RemainderAction: string implements HasLabel
{
    /** Keep the line open so the rest can be issued later. */
    case Backorder = 'backorder';

    /** Stop owing the rest: the line is treated as fulfilled. */
    case Close = 'close';

    public function getLabel(): string
    {
        return match ($this) {
            self::Backorder => 'Backorder: issue the rest later',
            self::Close => 'Close: the rest will not be issued',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
