<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum WardItemIssueType: string implements HasColor, HasLabel
{
    case BROKEN = 'broken';
    case MALFUNCTIONING = 'malfunctioning';
    case MISSING = 'missing';
    case DAMAGED = 'damaged';
    case NEEDS_CALIBRATION = 'needs_calibration';
    case CONDEMNED = 'condemned';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::BROKEN => 'Broken / Non-functional',
            self::MALFUNCTIONING => 'Malfunctioning / Faulty',
            self::MISSING => 'Missing / Lost',
            self::DAMAGED => 'Damaged',
            self::NEEDS_CALIBRATION => 'Needs Calibration / Servicing',
            self::CONDEMNED => 'Condemned / Beyond Repair',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::BROKEN, self::CONDEMNED => 'danger',
            self::MALFUNCTIONING, self::DAMAGED => 'warning',
            self::MISSING => 'info',
            self::NEEDS_CALIBRATION => 'gray',
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
