<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StoreType: string implements HasColor, HasLabel
{
    case General = 'general';
    case Medical = 'medical';
    case Linen = 'linen';
    case Stationery = 'stationery';
    case Maintenance = 'maintenance';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::General => 'General Stores',
            self::Medical => 'Medical Stores',
            self::Linen => 'Linen Store',
            self::Stationery => 'Stationery Store',
            self::Maintenance => 'Maintenance Store',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::General => 'primary',
            self::Medical => 'success',
            self::Linen => 'info',
            self::Stationery => 'warning',
            self::Maintenance => 'gray',
            self::Other => 'gray',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
