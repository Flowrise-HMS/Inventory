<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RequisitionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case PartiallyIssued = 'partially_issued';
    case Issued = 'issued';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::PartiallyIssued => 'Partially Issued',
            self::Issued => 'Issued',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Approved => 'success',
            self::PartiallyIssued => 'warning',
            self::Issued => 'primary',
            self::Declined => 'danger',
            self::Cancelled => 'danger',
            self::Closed => 'gray',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Issued, self::Declined, self::Cancelled, self::Closed], true);
    }
}
