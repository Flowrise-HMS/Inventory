<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Models\Requisition;

class RequisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('requisition_number')
                    ->searchable(),
                TextColumn::make('requestor.name')
                    ->label('Requestor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('department.name')
                    ->label('Department'),
                TextColumn::make('branch.name')
                    ->label('Branch'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (RequisitionStatus $state): string => $state->getColor()),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(RequisitionStatus::class),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->visible(fn (Requisition $record): bool => $record->status === RequisitionStatus::Pending)
                    ->authorize(fn (Requisition $record): bool => auth()->user()->can('approve', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Approve Requisition')
                    ->modalDescription(fn (Requisition $record) => "Approve requisition {$record->requisition_number}?")
                    ->action(function (Requisition $record): void {
                        app(RequisitionService::class)->approve($record);
                        Notification::make()->success()->title('Requisition approved')->send();
                    }),
                Action::make('decline')
                    ->label('Decline')
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->visible(fn (Requisition $record): bool => $record->status === RequisitionStatus::Pending)
                    ->authorize(fn (Requisition $record): bool => auth()->user()->can('approve', $record))
                    ->form([
                        Textarea::make('decline_reason')->required(),
                    ])
                    ->action(function (Requisition $record, array $data): void {
                        app(RequisitionService::class)->decline($record, $data['decline_reason']);
                        Notification::make()->success()->title('Requisition declined')->send();
                    }),
                Action::make('print_voucher')
                    ->label('Print Voucher')
                    ->icon('heroicon-m-printer')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.requisitions.voucher', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Requisition $record): bool => in_array($record->status, [
                        RequisitionStatus::PartiallyIssued,
                        RequisitionStatus::Issued,
                    ])),
                Action::make('download_voucher')
                    ->label('Download Voucher')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.requisitions.voucher', ['requisition' => $record, 'download' => 1]))
                    ->openUrlInNewTab()
                    ->visible(fn (Requisition $record): bool => in_array($record->status, [
                        RequisitionStatus::PartiallyIssued,
                        RequisitionStatus::Issued,
                    ])),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
