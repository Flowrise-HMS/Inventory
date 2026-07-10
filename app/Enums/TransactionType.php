<?php

namespace Modules\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransactionType: string implements HasColor, HasLabel
{
    case Receive = 'receive';
    case Issue = 'issue';
    case TransferShip = 'transfer_ship';
    case TransferReceive = 'transfer_receive';
    case Adjust = 'adjust';
    case Consume = 'consume';

    public function getLabel(): string
    {
        return match ($this) {
            self::Receive => 'Receive',
            self::Issue => 'Issue',
            self::TransferShip => 'Transfer Ship',
            self::TransferReceive => 'Transfer Receive',
            self::Adjust => 'Adjustment',
            self::Consume => 'Consume',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Receive => 'success',
            self::Issue => 'warning',
            self::TransferShip => 'info',
            self::TransferReceive => 'info',
            self::Adjust => 'danger',
            self::Consume => 'gray',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
