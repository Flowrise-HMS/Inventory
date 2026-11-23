<?php

namespace Modules\Inventory\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
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
use Modules\Core\Classes\Services\WardAccessService;
use Modules\Core\Filament\Concerns\InteractsWithWidgetShield;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\RequisitionIssueService;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Enums\ReceiptStatus;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Schemas\RequisitionInfolist;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionIssue;

class MyWardRequestsWidget extends BaseWidget
{
    use InteractsWithWidgetShield;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Ward requisitions';

    protected function getTableQuery(): Builder
    {
        $user = Auth::user();
        $inchargeWardIds = $user !== null
            ? app(WardAccessService::class)->wardsForIncharge($user)->pluck('id')->all()
            : [];

        return Requisition::query()
            ->with(['items.inventoryItem', 'department', 'location', 'branch', 'requestor', 'endorsedBy', 'approvedBy', 'issuedBy', 'issues.inventoryItem', 'issues.collectedBy'])
            ->where(function (Builder $query) use ($inchargeWardIds): void {
                $query->where('requestor_id', Auth::id());

                if ($inchargeWardIds !== []) {
                    $query->orWhereIn('location_id', $inchargeWardIds);
                }
            })
            ->latest();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('requisition_number')
                ->label(__('Requisition #'))
                ->searchable(),
            TextColumn::make('location.name')
                ->label(__('Ward'))
                ->placeholder('—'),
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
            TextColumn::make('receipt')
                ->label(__('Receipt'))
                ->badge()
                ->state(function (Requisition $record): ?string {
                    $awaiting = $record->issues->where('receipt_status', ReceiptStatus::AwaitingConfirmation)->count();

                    return match (true) {
                        $awaiting > 0 => trans_choice(':count hand-over to confirm|:count hand-overs to confirm', $awaiting),
                        $record->issues->contains('receipt_status', ReceiptStatus::Disputed) => __('Disputed'),
                        $record->issues->isNotEmpty() => __('Confirmed'),
                        default => null,
                    };
                })
                ->color(fn (?string $state): string => match (true) {
                    $state === __('Disputed') => 'danger',
                    $state === __('Confirmed') => 'success',
                    default => 'warning',
                })
                ->placeholder('—'),
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
            Action::make('confirm_receipt')
                ->label(__('Confirm receipt'))
                ->icon('heroicon-m-clipboard-document-check')
                ->color('warning')
                ->slideOver()
                ->modalWidth('3xl')
                ->modalHeading(fn (Requisition $record) => __('Confirm what you received: :number', ['number' => $record->requisition_number]))
                ->modalDescription(__('Check the items against what the store issued. If you got less, enter the real quantity and explain; the store is notified.'))
                ->modalSubmitActionLabel(__('Confirm receipt'))
                ->visible(fn (Requisition $record): bool => static::confirmableIssues($record) !== [])
                ->fillForm(fn (Requisition $record): array => [
                    'receipts' => array_map(fn (RequisitionIssue $issue): array => [
                        'issue_id' => (string) $issue->getKey(),
                        'quantity_issued' => $issue->quantity_issued,
                        'summary' => __(':item: :qty issued on :date, collected by :collector', [
                            'item' => $issue->inventoryItem?->name ?? __('Item'),
                            'qty' => $issue->quantity_issued,
                            'date' => $issue->issued_at?->format('Y-m-d H:i'),
                            'collector' => $issue->collectorName() ?? __('not recorded'),
                        ]),
                        'quantity_received' => $issue->quantity_issued,
                        'discrepancy_note' => null,
                    ], static::confirmableIssues($record)),
                ])
                ->schema([
                    Repeater::make('receipts')
                        ->hiddenLabel()
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columns(2)
                        ->schema([
                            Hidden::make('issue_id'),
                            Hidden::make('quantity_issued'),
                            Hidden::make('summary'),
                            Placeholder::make('line')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->content(fn (Get $get): string => (string) $get('summary')),
                            TextInput::make('quantity_received')
                                ->label(__('Quantity received'))
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->maxValue(fn (Get $get): int => (int) $get('quantity_issued'))
                                ->required()
                                ->live(onBlur: true),
                            Textarea::make('discrepancy_note')
                                ->label(__('What happened?'))
                                ->rows(2)
                                ->visible(fn (Get $get): bool => is_numeric($get('quantity_received')) && (int) $get('quantity_received') < (int) $get('quantity_issued'))
                                ->required(fn (Get $get): bool => is_numeric($get('quantity_received')) && (int) $get('quantity_received') < (int) $get('quantity_issued')),
                        ]),
                ])
                ->action(function (Requisition $record, array $data, Action $action): void {
                    $confirmations = [];

                    foreach ($data['receipts'] ?? [] as $receipt) {
                        $confirmations[(string) $receipt['issue_id']] = [
                            'quantity_received' => $receipt['quantity_received'] ?? null,
                            'discrepancy_note' => $receipt['discrepancy_note'] ?? null,
                        ];
                    }

                    try {
                        $issues = app(RequisitionIssueService::class)->confirmReceipt($record, $confirmations);
                    } catch (\RuntimeException $e) {
                        Notification::make()->danger()->title(__('Could not confirm'))->body($e->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    $disputed = $issues->where('receipt_status', ReceiptStatus::Disputed)->count();

                    $notification = Notification::make();

                    $disputed > 0
                        ? $notification->warning()
                            ->title(__('Receipt recorded with a dispute'))
                            ->body(__('The store has been notified about the difference.'))
                        : $notification->success()->title(__('Receipt confirmed'));

                    $notification->send();
                }),
            Action::make('endorse')
                ->label(__('Endorse'))
                ->icon('heroicon-m-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(__('Endorse ward requisition'))
                ->modalDescription(fn (Requisition $record) => __('Endorse :number and forward it to Store / Inventory for approval?', ['number' => $record->requisition_number]))
                ->visible(function (Requisition $record): bool {
                    $user = Auth::user();

                    return $record->status === RequisitionStatus::PendingIncharge
                        && $user !== null
                        && filled($record->location_id)
                        && app(WardAccessService::class)->isWardIncharge($user, $record->location_id);
                })
                ->action(function (Requisition $record): void {
                    app(RequisitionService::class)->endorse($record);

                    Notification::make()
                        ->success()
                        ->title(__('Requisition endorsed and forwarded'))
                        ->send();
                }),
            Action::make('cancel')
                ->label(__('Cancel'))
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Cancel requisition'))
                ->modalDescription(fn (Requisition $record) => __('Are you sure you want to cancel :number? This cannot be undone.', ['number' => $record->requisition_number]))
                ->visible(fn (Requisition $record): bool => in_array($record->status, [
                    RequisitionStatus::Pending,
                    RequisitionStatus::PendingIncharge,
                ], true))
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
                    Select::make('location_id')
                        ->label(__('Ward (Optional)'))
                        ->options(fn (Get $get): array => $get('branch_id')
                            ? Location::withoutGlobalScope('branch')->wards()->active()->where('branch_id', $get('branch_id'))->orderBy('name')->pluck('name', 'id')->all()
                            : [])
                        ->default(fn (): ?string => Auth::user() ? app(WardAccessService::class)->defaultWardIdFor(Auth::user()) : null)
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->disabled(fn (Get $get): bool => blank($get('branch_id'))),
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
                        'location_id' => $data['location_id'] ?? null,
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

    /**
     * @return list<RequisitionIssue>
     */
    protected static function confirmableIssues(Requisition $record): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        $service = app(RequisitionIssueService::class);

        return $record->issues
            ->filter(function (RequisitionIssue $issue) use ($record, $service, $user): bool {
                $issue->setRelation('requisition', $record);

                return $service->canConfirmReceipt($user, $issue);
            })
            ->sortBy('issued_at')
            ->values()
            ->all();
    }

    protected static function inventoryItemLabel(InventoryItem $item): string
    {
        return $item->sku ? "{$item->name} ({$item->sku})" : $item->name;
    }
}
