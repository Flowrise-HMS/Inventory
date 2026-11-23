<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Pages;

use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Filament\Actions\IssueHandoverAction;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionItem;
use Modules\Inventory\Models\Store;

/**
 * The storekeeper's queue: approved and partially issued (backordered)
 * requisitions for the stores they keep, issued from one screen.
 */
class IssueCounter extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $cluster = InventoryCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?int $navigationSort = -10;

    protected static ?string $navigationLabel = 'Issue counter';

    protected static ?string $title = 'Issue counter';

    protected string $view = 'inventory::filament.clusters.inventory.pages.issue-counter';

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $count = Requisition::query()->issuableBy($user)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getSubheading(): ?string
    {
        return __('Approved requests waiting at your store. Issue what is available; short issues need a reason and are either backordered or closed.');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->baseQuery())
            ->defaultSort('approved_at')
            ->emptyStateHeading(__('Nothing waiting at the counter'))
            ->emptyStateDescription(__('Approved requisitions for your stores will appear here.'))
            ->emptyStateIcon(Heroicon::OutlinedInboxArrowDown)
            ->columns([
                TextColumn::make('requisition_number')
                    ->label(__('Requisition #'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('store.name')
                    ->label(__('Store'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('destination')
                    ->label(__('For'))
                    ->state(fn (Requisition $record): string => $record->location?->name
                        ? sprintf('%s (%s)', $record->location->name, $record->department?->name ?? '-')
                        : ($record->department?->name ?? '-'))
                    ->wrap(),
                TextColumn::make('requestor.name')
                    ->label(__('Requested by')),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RequisitionStatus $state): string => $state === RequisitionStatus::PartiallyIssued
                        ? __('Backorder')
                        : $state->getLabel())
                    ->color(fn (RequisitionStatus $state): string => $state->getColor()),
                TextColumn::make('outstanding')
                    ->label(__('Still owed'))
                    ->state(fn (Requisition $record): array => $record->items
                        ->filter(fn (RequisitionItem $item): bool => $item->outstandingQuantity() > 0)
                        ->map(fn (RequisitionItem $item): string => sprintf(
                            '%s × %d',
                            $item->inventoryItem?->name ?? __('Item'),
                            $item->outstandingQuantity(),
                        ))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList(),
                TextColumn::make('approved_at')
                    ->label(__('Approved'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('store_id')
                    ->label(__('Store'))
                    ->options(fn (): array => $this->storeOptions()),
                SelectFilter::make('status')
                    ->options([
                        RequisitionStatus::Approved->value => RequisitionStatus::Approved->getLabel(),
                        RequisitionStatus::PartiallyIssued->value => __('Backorder (partially issued)'),
                    ]),
            ])
            ->recordActions([
                IssueHandoverAction::make(),
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->url(fn (Requisition $record): string => RequisitionResource::getUrl('view', ['record' => $record])),
            ])
            ->poll('60s');
    }

    protected function baseQuery(): Builder
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return Requisition::query()->whereRaw('1 = 0');
        }

        return Requisition::query()
            ->issuableBy($user)
            ->with(['items.inventoryItem', 'store', 'location', 'department', 'requestor']);
    }

    /**
     * @return array<string, string>
     */
    protected function storeOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return Store::query()
            ->active()
            ->visibleTo($user)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
