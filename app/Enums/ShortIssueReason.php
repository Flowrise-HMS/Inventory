<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a store issued less than the outstanding quantity on a requisition line.
 */
enum ShortIssueReason: string implements HasLabel
{
    case OutOfStock = 'out_of_stock';
    case Rationed = 'rationed';
    case Substituted = 'substituted';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::OutOfStock => 'Out of stock / not enough in store',
            self::Rationed => 'Rationed (sharing limited stock)',
            self::Substituted => 'Substituted with another item',
            self::Other => 'Other',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
