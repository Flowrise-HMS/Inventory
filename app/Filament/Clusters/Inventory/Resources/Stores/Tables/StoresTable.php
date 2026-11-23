<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Inventory\Enums\StoreType;

class StoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branch', 'keepers'])->withCount('keepers'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('branch.name')
                    ->label('Branch')
                    ->sortable(),
                TextColumn::make('type')
                    ->badge(),
                IconColumn::make('is_main')
                    ->label('Main')
                    ->boolean(),
                TextColumn::make('keepers.name')
                    ->label('Store keepers')
                    ->badge()
                    ->limitList(3)
                    ->placeholder('-'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name'),
                SelectFilter::make('type')
                    ->options(StoreType::class),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
