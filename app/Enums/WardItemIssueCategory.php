<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum WardItemIssueCategory: string implements HasColor, HasLabel
{
    case MEDICAL_EQUIPMENT = 'medical_equipment';
    case BED_FURNITURE = 'bed_furniture';
    case PLUMBING_ELECTRICAL = 'plumbing_electrical';
    case LINEN_SUPPLIES = 'linen_supplies';
    case IT_COMMS = 'it_comms';
    case OTHER = 'other';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::MEDICAL_EQUIPMENT => 'Medical Equipment',
            self::BED_FURNITURE => 'Bed & Ward Furniture',
            self::PLUMBING_ELECTRICAL => 'Plumbing / Electrical / HVAC',
            self::LINEN_SUPPLIES => 'Linen & General Supplies',
            self::IT_COMMS => 'IT / Call Bell / Comms',
            self::OTHER => 'Other Fixture',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::MEDICAL_EQUIPMENT => 'danger',
            self::BED_FURNITURE => 'warning',
            self::PLUMBING_ELECTRICAL => 'info',
            self::LINEN_SUPPLIES => 'gray',
            self::IT_COMMS => 'primary',
            self::OTHER => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
