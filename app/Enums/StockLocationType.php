<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockLocationType: string implements HasColor, HasLabel
{
    case Dispensary = 'dispensary';
    case Ward = 'ward';
    case InTransit = 'in_transit';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Dispensary => 'success',
            self::Ward => 'info',
            self::InTransit => 'warning',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
