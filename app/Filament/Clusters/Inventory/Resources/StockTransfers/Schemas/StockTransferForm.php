<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class StockTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('from_branch_id')
                    ->relationship('fromBranch', 'name')
                    ->searchable()
                    ->required(),
                Select::make('to_branch_id')
                    ->relationship('toBranch', 'name')
                    ->searchable()
                    ->required()
                    ->different('from_branch_id'),
                Textarea::make('notes')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
