<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;
use Modules\Inventory\Models\Requisition;

class ViewRequisition extends ViewRecord
{
    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label('Close requisition')
                ->icon('heroicon-m-lock-closed')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->status === RequisitionStatus::PartiallyIssued)
                ->authorize(fn (): bool => auth()->user()->can('issue', $this->getRecord()))
                ->form([
                    Textarea::make('closed_reason')->label('Reason')->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Requisition $record */
                    $record = $this->getRecord();
                    app(RequisitionService::class)->close($record, $data['closed_reason']);
                    Notification::make()->success()->title('Requisition closed')->send();
                }),
            EditAction::make(),
        ];
    }
}
