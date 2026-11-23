<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether the requesting ward/department has confirmed what it received.
 */
enum ReceiptStatus: string implements HasColor, HasLabel
{
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';

    public function getLabel(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'Awaiting ward confirmation',
            self::Confirmed => 'Received',
            self::Disputed => 'Disputed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'warning',
            self::Confirmed => 'success',
            self::Disputed => 'danger',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
