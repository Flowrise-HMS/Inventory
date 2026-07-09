<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InventoryItemCategory: string implements HasColor, HasLabel
{
    case Supplies = 'supplies';
    case Equipment = 'equipment';
    case Consumables = 'consumables';
    case General = 'general';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Supplies => 'gray',
            self::Equipment => 'info',
            self::Consumables => 'warning',
            self::General => 'primary',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
