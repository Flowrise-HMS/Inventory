<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;
use Modules\Inventory\Enums\StoreType;
use Modules\Inventory\Models\Store;

class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Medical Stores'),
                TextInput::make('code')
                    ->required()
                    ->maxLength(30)
                    ->placeholder('e.g. MED')
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper(trim($state)) : null)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('branch_id', $get('branch_id')),
                    ),
                Select::make('branch_id')
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    // Moving a store between branches would orphan its stock.
                    ->disabled(fn (?Store $record): bool => $record !== null)
                    ->dehydrated(fn (?Store $record): bool => $record === null),
                Select::make('type')
                    ->options(StoreType::class)
                    ->default(StoreType::General)
                    ->required(),
                Select::make('keepers')
                    ->label('Store keepers')
                    ->relationship('keepers', 'name')
                    ->multiple()
                    ->searchable()
                    ->helperText('Once keepers are assigned, only they (or users with "Manage All Stores") can issue requisitions from this store.')
                    ->columnSpanFull(),
                Toggle::make('is_main')
                    ->label('Main store for this branch')
                    ->helperText('Receives purchase orders and transfers, and serves requisitions by default. Marking a store as main replaces the current one.')
                    ->default(false)
                    ->disabled(fn (?Store $record): bool => (bool) $record?->is_main),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->disabled(fn (?Store $record): bool => (bool) $record?->is_main),
                Textarea::make('description')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
