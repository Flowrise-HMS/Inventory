<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockTransferStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Shipped = 'shipped';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Shipped => 'Shipped',
            self::PartiallyReceived => 'Partially Received',
            self::Received => 'Received',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Shipped => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Closed => 'primary',
            self::Cancelled => 'danger',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Received, self::Closed, self::Cancelled], true);
    }
}
