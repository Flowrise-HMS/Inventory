<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum WardItemIssueStatus: string implements HasColor, HasLabel
{
    case REPORTED = 'reported';
    case INCHARGE_VERIFIED = 'incharge_verified';
    case ACKNOWLEDGED = 'acknowledged';
    case IN_REPAIR = 'in_repair';
    case AWAITING_PARTS = 'awaiting_parts';
    case RESOLVED = 'resolved';
    case CONDEMNED_REPLACED = 'condemned_replaced';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::REPORTED => 'Reported',
            self::INCHARGE_VERIFIED => 'Verified by Incharge',
            self::ACKNOWLEDGED => 'Acknowledged by Maintenance',
            self::IN_REPAIR => 'In Repair',
            self::AWAITING_PARTS => 'Awaiting Parts',
            self::RESOLVED => 'Resolved / Repaired',
            self::CONDEMNED_REPLACED => 'Condemned / Replaced',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::REPORTED => 'danger',
            self::INCHARGE_VERIFIED => 'warning',
            self::ACKNOWLEDGED, self::IN_REPAIR => 'info',
            self::AWAITING_PARTS => 'gray',
            self::RESOLVED, self::CONDEMNED_REPLACED => 'success',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::RESOLVED, self::CONDEMNED_REPLACED], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [
            self::REPORTED->value,
            self::INCHARGE_VERIFIED->value,
            self::ACKNOWLEDGED->value,
            self::IN_REPAIR->value,
            self::AWAITING_PARTS->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
