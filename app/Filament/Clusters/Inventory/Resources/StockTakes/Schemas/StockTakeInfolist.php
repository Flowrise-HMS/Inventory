<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Inventory\Classes\Services\StockTakeService;
use Modules\Inventory\Enums\StockTakeStatus;
use Modules\Inventory\Models\StockTake;

class StockTakeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Stock-take'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('store.name')->label(__('Store')),
                        IconEntry::make('is_blind')->label(__('Blind count'))->boolean(),
                        TextEntry::make('started_at')
                            ->label(__('Snapshot taken'))
                            ->dateTime()
                            ->helperText(fn (StockTake $record): ?string => $record->startedBy?->name),
                        TextEntry::make('submitted_at')
                            ->label(__('Submitted'))
                            ->dateTime()
                            ->placeholder('-')
                            ->helperText(fn (StockTake $record): ?string => $record->submittedBy?->name),
                        TextEntry::make('approved_at')
                            ->label(__('Approved'))
                            ->dateTime()
                            ->placeholder('-')
                            ->helperText(fn (StockTake $record): ?string => $record->approvedBy?->name),
                        TextEntry::make('status_reason')
                            ->label(fn (StockTake $record): string => $record->status === StockTakeStatus::Cancelled ? __('Cancelled because') : __('Sent back because'))
                            ->visible(fn (StockTake $record): bool => filled($record->status_reason))
                            ->color('danger'),
                        TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make(__('Progress'))
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('progress')
                            ->label(__('Lines counted'))
                            ->state(function (StockTake $record): string {
                                $s = app(StockTakeService::class)->summary($record);

                                return sprintf('%d of %d', $s['counted'], $s['lines']);
                            }),
                        TextEntry::make('variances')
                            ->label(__('Lines with a difference'))
                            ->state(fn (StockTake $record): int => app(StockTakeService::class)->summary($record)['with_variance']),
                        TextEntry::make('units')
                            ->label(__('Units over / short'))
                            ->state(function (StockTake $record): string {
                                $s = app(StockTakeService::class)->summary($record);

                                return sprintf('+%d / -%d', $s['units_over'], $s['units_short']);
                            }),
                    ]),
            ]);
    }
}
