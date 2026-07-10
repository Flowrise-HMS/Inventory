<?php

namespace Modules\Inventory\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Filament\Concerns\InteractsWithWidgetShield;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Schemas\RequisitionInfolist;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;

class MyWardRequestsWidget extends BaseWidget
{
    use InteractsWithWidgetShield;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'My ward requests';

    protected function getTableQuery(): Builder
    {
        return Requisition::query()
            ->with(['items.inventoryItem', 'department', 'branch', 'requestor', 'approvedBy', 'issuedBy'])
            ->where('requestor_id', Auth::id())
            ->latest();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('requisition_number')
                ->label(__('Requisition #'))
                ->searchable(),
            TextColumn::make('department.name')
                ->label(__('Department')),
            TextColumn::make('status')
                ->badge()
                ->color(fn (RequisitionStatus $state): string => $state->getColor()),
            TextColumn::make('items')
                ->label(__('Items requested'))
                ->state(fn (Requisition $record) => $record->items->map(
                    fn ($item) => sprintf(
                        '%s — Req: %d, Appr: %s, Issued: %d',
                        $item->inventoryItem?->name ?? __('Unknown item'),
                        $item->quantity_requested,
                        $item->quantity_approved ?? '-',
                        $item->quantity_issued,
                    )
                )->all())
                ->listWithLineBreaks()
                ->bulleted()
                ->limitList(3)
                ->expandableLimitedList()
                ->wrap(),
            TextColumn::make('created_at')
                ->label(__('Created'))
                ->dateTime()
                ->sortable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('view')
                ->label(__('View'))
                ->icon('heroicon-m-eye')
                ->slideOver()
                ->modalHeading(fn (Requisition $record) => $record->requisition_number)
                ->modalWidth('4xl')
                ->modalSubmitAction(false)
                ->schema(fn (Schema $schema): Schema => RequisitionInfolist::configure($schema)),
            Action::make('fulfill')
                ->label(__('Fulfill items'))
                ->icon('heroicon-m-arrow-right-circle')
                ->color('primary')
                ->url(fn (Requisition $record): string => RequisitionResource::getUrl('view', ['record' => $record]).'#relationManagerItems')
                ->visible(fn (Requisition $record): bool => in_array($record->status, [
                    RequisitionStatus::Approved,
                    RequisitionStatus::PartiallyIssued,
                ], true)),
            Action::make('cancel')
                ->label(__('Cancel'))
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Cancel requisition'))
                ->modalDescription(fn (Requisition $record) => __('Are you sure you want to cancel :number? This cannot be undone.', ['number' => $record->requisition_number]))
                ->visible(fn (Requisition $record): bool => $record->status === RequisitionStatus::Pending)
                ->authorize(fn (Requisition $record): bool => Auth::id() === $record->requestor_id)
                ->action(function (Requisition $record): void {
                    app(RequisitionService::class)->cancel($record);

                    Notification::make()
                        ->success()
                        ->title(__('Requisition cancelled'))
                        ->send();
                }),
        ];
    }

    protected function getTableHeaderActions(): array
    {
        return [
            Action::make('new_request')
                ->label(__('New Request'))
                ->icon('heroicon-m-plus')
                ->slideOver()
                ->modalWidth('3xl')
                ->modalHeading(__('New ward request'))
                ->authorize(fn (): bool => Auth::user()?->can('create', Requisition::class) ?? false)
                ->schema([
                    Select::make('branch_id')
                        ->label(__('Branch'))
                        ->options(fn (): array => Branch::query()->active()->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->afterStateUpdated(fn (Set $set) => $set('department_id', null)),
                    Select::make('department_id')
                        ->label(__('Department'))
                        ->options(fn (Get $get): array => $get('branch_id')
                            ? Department::byBranch($get('branch_id'))->active()->pluck('name', 'id')->all()
                            : [])
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled(fn (Get $get): bool => blank($get('branch_id')))
                        ->helperText(fn (Get $get) => blank($get('branch_id')) ? __('Select a branch first.') : null),
                    Textarea::make('notes')
                        ->label(__('Notes'))
                        ->nullable()
                        ->columnSpanFull(),
                    Repeater::make('items')
                        ->label(__('Items requested'))
                        ->columnSpanFull()
                        ->columns(2)
                        ->addActionLabel(__('Add item'))
                        ->defaultItems(0)
                        ->minItems(1)
                        ->reorderable(false)
                        ->schema([
                            Select::make('inventory_item_id')
                                ->label(__('Item'))
                                ->searchable()
                                ->preload()
                                ->options(fn (): array => InventoryItem::query()
                                    ->active()
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (InventoryItem $item) => [$item->id => static::inventoryItemLabel($item)])
                                    ->all())
                                ->getSearchResultsUsing(fn (string $search): array => InventoryItem::query()
                                    ->active()
                                    ->where(fn ($query) => $query
                                        ->where('name', 'like', "%{$search}%")
                                        ->orWhere('sku', 'like', "%{$search}%"))
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (InventoryItem $item) => [$item->id => static::inventoryItemLabel($item)])
                                    ->all())
                                ->getOptionLabelUsing(fn ($value): ?string => ($item = InventoryItem::find($value)) ? static::inventoryItemLabel($item) : null)
                                ->required(),
                            TextInput::make('quantity_requested')
                                ->label(__('Quantity requested'))
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                        ]),
                ])
                ->action(function (array $data): void {
                    app(RequisitionService::class)->create([
                        'branch_id' => $data['branch_id'],
                        'department_id' => $data['department_id'],
                        'notes' => $data['notes'] ?? null,
                        'items' => $data['items'],
                    ]);

                    Notification::make()
                        ->success()
                        ->title(__('Requisition submitted'))
                        ->send();
                }),
        ];
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected static function inventoryItemLabel(InventoryItem $item): string
    {
        return $item->sku ? "{$item->name} ({$item->sku})" : $item->name;
    }
}
