<?php

namespace Modules\Inventory\Filament\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Classes\Services\RequisitionIssueService;
use Modules\Inventory\Enums\RemainderAction;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Enums\ShortIssueReason;
use Modules\Inventory\Filament\Forms\Components\SignaturePad;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionItem;
use Modules\Inventory\Models\StockBalance;

/**
 * "Issue items" at the store counter: every outstanding line of a requisition
 * in one form, short-issue reasons with backorder or close, and the
 * collector's name and drawn signature for the whole hand-over.
 */
class IssueHandoverAction
{
    public static function make(string $name = 'issue_handover', ?\Closure $resolveRequisition = null): Action
    {
        $requisitionFor = $resolveRequisition ?? fn (?Requisition $record): ?Requisition => $record;

        return Action::make($name)
            ->label(__('Issue items'))
            ->icon('heroicon-m-arrow-right-circle')
            ->color('success')
            ->modalHeading(fn (?Requisition $record): string => __('Issue items for :number', [
                'number' => $requisitionFor($record)?->requisition_number ?? '',
            ]))
            ->modalDescription(fn (?Requisition $record): ?string => ($requisition = $requisitionFor($record))
                ? __('From :store to :destination. Enter what you are handing over now; the person collecting signs below.', [
                    'store' => $requisition->store?->name ?? __('store'),
                    'destination' => $requisition->location?->name ?? $requisition->department?->name ?? __('the requester'),
                ])
                : null)
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel(__('Issue and record signature'))
            ->visible(fn (?Requisition $record): bool => ($requisition = $requisitionFor($record)) !== null
                && in_array($requisition->status, [RequisitionStatus::Approved, RequisitionStatus::PartiallyIssued], true))
            ->authorize(fn (?Requisition $record): bool => ($requisition = $requisitionFor($record)) !== null
                && (Auth::user()?->can('issue', $requisition) ?? false))
            ->fillForm(fn (?Requisition $record): array => [
                'lines' => static::defaultLines($requisitionFor($record)),
            ])
            ->schema([
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->itemLabel(fn (array $state): ?string => $state['item_name'] ?? null)
                    ->schema([
                        Hidden::make('requisition_item_id'),
                        Hidden::make('item_name'),
                        Hidden::make('outstanding'),
                        Hidden::make('on_hand'),
                        Hidden::make('summary'),
                        Grid::make(2)->schema([
                            Placeholder::make('line_summary')
                                ->hiddenLabel()
                                ->content(fn (Get $get): string => (string) $get('summary')),
                            TextInput::make('quantity')
                                ->label(__('Quantity to issue now'))
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->maxValue(fn (Get $get): int => (int) $get('outstanding'))
                                ->required()
                                ->live(onBlur: true),
                        ]),
                        Grid::make(2)
                            ->visible(fn (Get $get): bool => static::isShort($get))
                            ->schema([
                                Select::make('short_issue_reason')
                                    ->label(__('Why less than outstanding?'))
                                    ->options(ShortIssueReason::class)
                                    ->required(fn (Get $get): bool => static::isShort($get))
                                    ->live(),
                                Radio::make('remainder_action')
                                    ->label(__('The rest'))
                                    ->options(RemainderAction::class)
                                    ->required(fn (Get $get): bool => static::isShort($get)),
                                Textarea::make('short_issue_note')
                                    ->label(__('Note'))
                                    ->rows(2)
                                    ->columnSpanFull()
                                    ->required(fn (Get $get): bool => static::isShort($get)
                                        && $get('short_issue_reason') === ShortIssueReason::Other->value),
                            ]),
                    ]),
                Section::make(__('Collected by'))
                    ->description(__('The person physically taking the items signs for them, like the paper register.'))
                    ->columns(2)
                    ->schema([
                        Select::make('collected_by_user_id')
                            ->label(__('Staff member'))
                            ->helperText(__('Pick them if they have an account.'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(25)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('name'))
                            ->live(),
                        TextInput::make('collected_by_name')
                            ->label(__('Or their name'))
                            ->helperText(__('For porters or visitors without an account.'))
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => blank($get('collected_by_user_id')) && static::handsOverStock($get))
                            ->disabled(fn (Get $get): bool => filled($get('collected_by_user_id'))),
                        SignaturePad::make('signature')
                            ->label(__('Collector signature'))
                            ->columnSpanFull()
                            ->required(fn (Get $get): bool => static::handsOverStock($get)
                                && (bool) config('inventory.requisitions.require_collector_signature', true)),
                    ]),
            ])
            ->action(function (array $data, Action $action, ?Requisition $record) use ($requisitionFor): void {
                $requisition = $requisitionFor($record);

                if (! $requisition) {
                    return;
                }

                try {
                    $issues = app(RequisitionIssueService::class)->issueHandover(
                        requisition: $requisition,
                        lines: array_values(array_map(fn (array $line): array => [
                            'requisition_item_id' => $line['requisition_item_id'] ?? null,
                            'quantity' => $line['quantity'] ?? 0,
                            'short_issue_reason' => $line['short_issue_reason'] ?? null,
                            'short_issue_note' => $line['short_issue_note'] ?? null,
                            'remainder_action' => $line['remainder_action'] ?? null,
                        ], $data['lines'] ?? [])),
                        collector: [
                            'user_id' => $data['collected_by_user_id'] ?? null,
                            'name' => $data['collected_by_name'] ?? null,
                            'signature' => $data['signature'] ?? null,
                        ],
                    );
                } catch (\RuntimeException $e) {
                    Notification::make()->danger()->title(__('Could not issue'))->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                $requisition->refresh();

                Notification::make()
                    ->success()
                    ->title(__('Items issued'))
                    ->body(__(':count line(s) recorded. Status: :status.', [
                        'count' => $issues->count(),
                        'status' => $requisition->status->getLabel(),
                    ]))
                    ->send();
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultLines(?Requisition $requisition): array
    {
        if (! $requisition) {
            return [];
        }

        $requisition->loadMissing('items.inventoryItem', 'store');

        return $requisition->items
            ->filter(fn (RequisitionItem $item): bool => $item->outstandingQuantity() > 0)
            ->map(function (RequisitionItem $item) use ($requisition): array {
                $outstanding = $item->outstandingQuantity();
                $onHand = StockBalance::dispensaryOnHand($item->inventory_item_id, $requisition->branch_id, $requisition->store_id);
                $default = max(0, min($outstanding, $onHand));
                $short = $default < $outstanding;

                return [
                    'requisition_item_id' => (string) $item->getKey(),
                    'item_name' => $item->inventoryItem?->name ?? __('Item'),
                    'outstanding' => $outstanding,
                    'on_hand' => $onHand,
                    'summary' => __('Requested :requested · approved :approved · already issued :issued · still owed :outstanding · in store now :on_hand', [
                        'requested' => $item->quantity_requested,
                        'approved' => $item->quantity_approved ?? '-',
                        'issued' => $item->quantity_issued,
                        'outstanding' => $outstanding,
                        'on_hand' => $onHand,
                    ]),
                    'quantity' => $default,
                    'short_issue_reason' => $short ? ShortIssueReason::OutOfStock->value : null,
                    'short_issue_note' => null,
                    'remainder_action' => $short ? RemainderAction::Backorder->value : null,
                ];
            })
            ->values()
            ->all();
    }

    protected static function isShort(Get $get): bool
    {
        $qty = $get('quantity');

        return is_numeric($qty) && (int) $qty < (int) $get('outstanding');
    }

    protected static function handsOverStock(Get $get): bool
    {
        foreach ((array) $get('lines') as $line) {
            if (is_numeric($line['quantity'] ?? null) && (int) $line['quantity'] > 0) {
                return true;
            }
        }

        return false;
    }
}
