<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockTakeStatus: string implements HasColor, HasLabel
{
    /** Snapshot taken; counts being entered. */
    case InProgress = 'in_progress';

    /** Counting finished; waiting for a supervisor to approve the variances. */
    case Submitted = 'submitted';

    /** Variances posted to the ledger as adjustments. */
    case Approved = 'approved';

    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::InProgress => 'Counting',
            self::Submitted => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InProgress => 'info',
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::InProgress, self::Submitted], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
