<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Store Details')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code'),
                        TextEntry::make('branch.name')
                            ->label('Branch'),
                        TextEntry::make('type')
                            ->badge(),
                        IconEntry::make('is_main')
                            ->label('Main store')
                            ->boolean(),
                        TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                        TextEntry::make('keepers.name')
                            ->label('Store keepers')
                            ->badge()
                            ->placeholder('None assigned: anyone with "Issue Requisition" can issue')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Metadata')
                    ->columnSpanFull()
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
