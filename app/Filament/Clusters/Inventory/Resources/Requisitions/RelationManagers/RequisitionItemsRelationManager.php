<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RelationManagers;

use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Filament\Actions\IssueHandoverAction;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionItem;
use Modules\Inventory\Models\StockBalance;

class RequisitionItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('inventory_item_id')
                    ->label('Item')
                    ->relationship('inventoryItem', 'name')
                    ->getOptionLabelFromRecordUsing(fn (InventoryItem $record) => $record->sku ? "{$record->name} ({$record->sku})" : $record->name)
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('quantity_requested')
                    ->label('Quantity requested')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('inventoryItem.name')
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('inventoryItem.name')
                    ->label('Item')
                    ->searchable(),
                TextColumn::make('inventoryItem.sku')
                    ->label('SKU')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quantity_requested')
                    ->label('Requested'),
                TextColumn::make('quantity_approved')
                    ->label('Approved')
                    ->placeholder('-'),
                TextColumn::make('quantity_issued')
                    ->label('Issued'),
                TextColumn::make('quantity_closed')
                    ->label('Closed short')
                    ->tooltip('Quantity the store decided not to issue')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : '-'),
                TextColumn::make('outstanding')
                    ->label('Still owed')
                    ->state(fn (RequisitionItem $record): int => $record->outstandingQuantity())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('in_store')
                    ->label('In store now')
                    ->state(fn (RequisitionItem $record): int => StockBalance::dispensaryOnHand(
                        $record->inventory_item_id,
                        $this->getOwnerRecord()->branch_id,
                        $this->getOwnerRecord()->store_id,
                    ))
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->getOwnerRecord()->status === RequisitionStatus::Pending),
                IssueHandoverAction::make(resolveRequisition: fn (): Requisition => $this->getOwnerRecord()),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->visible(fn (): bool => $this->getOwnerRecord()->status === RequisitionStatus::Pending),
                    DeleteAction::make()
                        ->visible(fn (): bool => $this->getOwnerRecord()->status === RequisitionStatus::Pending),
                ]),
            ]);
    }

    public function getOwnerRecord(): Requisition
    {
        return parent::getOwnerRecord();
    }
}
