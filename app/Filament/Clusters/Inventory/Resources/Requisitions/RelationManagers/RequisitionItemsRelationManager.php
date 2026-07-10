<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Enums\RequisitionStatus;
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
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->getOwnerRecord()->status === RequisitionStatus::Pending),
            ])
            ->recordActions([
                Action::make('issue')
                    ->label('Issue')
                    ->icon('heroicon-m-arrow-right-circle')
                    ->color('success')
                    ->visible(fn (RequisitionItem $record): bool => in_array($this->getOwnerRecord()->status, [
                        RequisitionStatus::Approved,
                        RequisitionStatus::PartiallyIssued,
                    ], true) && $record->quantity_issued < ($record->quantity_approved ?? $record->quantity_requested))
                    ->authorize(fn (): bool => auth()->user()->can('issue', $this->getOwnerRecord()))
                    ->schema(fn (RequisitionItem $record) => [
                        Placeholder::make('dispensary_on_hand')
                            ->label('Dispensary on hand')
                            ->content((string) StockBalance::dispensaryOnHand(
                                $record->inventory_item_id,
                                $this->getOwnerRecord()->branch_id,
                            )),
                        TextInput::make('qty')
                            ->label('Quantity to issue')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(($record->quantity_approved ?? $record->quantity_requested) - $record->quantity_issued)
                            ->default(($record->quantity_approved ?? $record->quantity_requested) - $record->quantity_issued)
                            ->required(),
                    ])
                    ->action(function (RequisitionItem $record, array $data): void {
                        app(RequisitionService::class)->issue($record, (int) $data['qty']);

                        Notification::make()
                            ->success()
                            ->title('Item issued')
                            ->send();
                    }),
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
