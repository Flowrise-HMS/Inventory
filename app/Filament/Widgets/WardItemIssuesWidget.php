<?php

namespace Modules\Inventory\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Modules\Core\Classes\Services\WardAccessService;
use Modules\Core\Enums\LocationType;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\WardItemIssueService;
use Modules\Inventory\Enums\WardItemIssueCategory;
use Modules\Inventory\Enums\WardItemIssueSeverity;
use Modules\Inventory\Enums\WardItemIssueStatus;
use Modules\Inventory\Enums\WardItemIssueType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\WardItemIssue;

/**
 * Displays broken items & ward equipment fault reports on Ward Board (injected via PageWidgetsRegistry).
 */
class WardItemIssuesWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Broken Items & Ward Equipment Faults';

    #[Reactive]
    public ?string $wardId = null;

    #[Reactive]
    public ?string $branchId = null;

    protected function getTableQuery(): Builder
    {
        return WardItemIssue::query()
            ->with(['bed', 'inventoryItem', 'reportedBy', 'verifiedBy'])
            ->when(
                filled($this->wardId),
                fn (Builder $q) => $q->where('location_id', $this->wardId),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            )
            ->orderByRaw("CASE WHEN status IN ('resolved', 'condemned_replaced') THEN 1 ELSE 0 END")
            ->orderByDesc('reported_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('issue_number')
                ->label(__('Ref #'))
                ->searchable()
                ->weight('bold'),
            TextColumn::make('item_name')
                ->label(__('Item / Fixture'))
                ->description(fn (WardItemIssue $record): string => collect([
                    $record->bed?->name ? 'Bed: '.$record->bed->name : null,
                    $record->serial_or_asset_tag ? 'Tag: '.$record->serial_or_asset_tag : null,
                ])->filter()->implode(' · '))
                ->searchable()
                ->wrap(),
            TextColumn::make('category')
                ->badge(),
            TextColumn::make('issue_type')
                ->label(__('Fault'))
                ->badge(),
            TextColumn::make('severity')
                ->badge(),
            TextColumn::make('status')
                ->badge(),
            TextColumn::make('reportedBy.name')
                ->label(__('Reported By'))
                ->description(fn (WardItemIssue $record): ?string => $record->reported_at?->format('d M, H:i')),
        ];
    }

    protected function getTableHeaderActions(): array
    {
        return [
            Action::make('report_broken_item')
                ->label(__('Report Broken Item / Fault'))
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('danger')
                ->slideOver()
                ->modalWidth('2xl')
                ->visible(fn (): bool => filled($this->wardId))
                ->schema([
                    Select::make('inventory_item_id')
                        ->label(__('Cataloged Inventory Item (Optional)'))
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->live()
                        ->options(fn (): array => InventoryItem::query()->active()->limit(50)->pluck('name', 'id')->all())
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state && ($item = InventoryItem::find($state))) {
                                $set('item_name', $item->name);
                            }
                        }),
                    TextInput::make('item_name')
                        ->label(__('Item / Fixture Name'))
                        ->placeholder(__('e.g., BP Apparatus, Bed 4 Side Rail, Sluice Room Tap'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('serial_or_asset_tag')
                        ->label(__('Asset Tag / Serial # (Optional)'))
                        ->maxLength(100),
                    Select::make('category')
                        ->label(__('Category'))
                        ->options(WardItemIssueCategory::class)
                        ->default(WardItemIssueCategory::MEDICAL_EQUIPMENT->value)
                        ->required(),
                    Select::make('issue_type')
                        ->label(__('Issue Type'))
                        ->options(WardItemIssueType::class)
                        ->default(WardItemIssueType::BROKEN->value)
                        ->required(),
                    Select::make('severity')
                        ->label(__('Severity'))
                        ->options(WardItemIssueSeverity::class)
                        ->default(WardItemIssueSeverity::MEDIUM->value)
                        ->required(),
                    TextInput::make('quantity_affected')
                        ->label(__('Quantity Affected'))
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    Select::make('bed_id')
                        ->label(__('Related Bed (Optional)'))
                        ->nullable()
                        ->live()
                        ->options(fn (): array => filled($this->wardId)
                            ? Location::withoutGlobalScope('branch')
                                ->where('parent_id', $this->wardId)
                                ->where('type', LocationType::BED->value)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()
                            : []),
                    Toggle::make('block_bed')
                        ->label(__('Mark bed as Blocked on Ward Board'))
                        ->default(false)
                        ->visible(fn (Get $get): bool => filled($get('bed_id'))),
                    Textarea::make('description')
                        ->label(__('Fault Description & Impact'))
                        ->rows(3)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $ward = Location::withoutGlobalScope('branch')->findOrFail($this->wardId);

                    app(WardItemIssueService::class)->report($ward, $data);

                    Notification::make()
                        ->success()
                        ->title(__('Broken item report logged'))
                        ->send();
                }),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('verify')
                ->label(__('Verify'))
                ->icon('heroicon-m-check-badge')
                ->color('warning')
                ->visible(function (WardItemIssue $record): bool {
                    $user = Auth::user();

                    return $record->status === WardItemIssueStatus::REPORTED
                        && $user !== null
                        && app(WardAccessService::class)->isWardIncharge($user, $record->location_id);
                })
                ->action(function (WardItemIssue $record): void {
                    app(WardItemIssueService::class)->verify($record);

                    Notification::make()
                        ->success()
                        ->title(__('Fault verified by Ward Incharge'))
                        ->send();
                }),
            Action::make('in_repair')
                ->label(__('In Repair'))
                ->icon('heroicon-m-wrench')
                ->color('info')
                ->visible(fn (WardItemIssue $record): bool => in_array($record->status, [
                    WardItemIssueStatus::INCHARGE_VERIFIED,
                    WardItemIssueStatus::ACKNOWLEDGED,
                ], true))
                ->action(function (WardItemIssue $record): void {
                    app(WardItemIssueService::class)->updateStatus($record, WardItemIssueStatus::IN_REPAIR);

                    Notification::make()
                        ->info()
                        ->title(__('Status updated to In Repair'))
                        ->send();
                }),
            Action::make('resolve')
                ->label(__('Resolve'))
                ->icon('heroicon-m-check-circle')
                ->color('success')
                ->visible(fn (WardItemIssue $record): bool => $record->status->isOpen())
                ->schema(fn (WardItemIssue $record): array => [
                    Textarea::make('resolution_notes')
                        ->label(__('Resolution / Repair Notes'))
                        ->rows(2),
                    Toggle::make('condemned')
                        ->label(__('Condemned / Replaced instead of repaired'))
                        ->default(false),
                    Toggle::make('unblock_bed')
                        ->label(__('Release blocked bed to Cleaning'))
                        ->default($record->bed_blocked)
                        ->visible($record->bed_blocked && filled($record->bed_id)),
                ])
                ->action(function (WardItemIssue $record, array $data): void {
                    app(WardItemIssueService::class)->resolve(
                        issue: $record,
                        resolutionNotes: $data['resolution_notes'] ?? null,
                        condemned: (bool) ($data['condemned'] ?? false),
                        unblockBed: (bool) ($data['unblock_bed'] ?? false),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('Ward item issue closed'))
                        ->send();
                }),
        ];
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        return __('No broken items or equipment faults reported');
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        return __('Use "Report Broken Item / Fault" to log faulty medical equipment, broken beds, or ward fixtures.');
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->can('ViewAny WardItemIssue')
            || app(WardAccessService::class)->isAnyWardIncharge($user);
    }
}
