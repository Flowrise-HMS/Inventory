<?php

namespace Modules\Inventory\Listeners;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Events\RequisitionCreated;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;
use Spatie\Permission\Models\Permission;

class NotifyApproversOfRequisition implements ShouldQueue
{
    public function handle(RequisitionCreated $event): void
    {
        if (! Permission::query()->where('name', 'Approve Requisition')->where('guard_name', 'web')->exists()) {
            return;
        }

        $requisition = $event->requisition->loadMissing(['department', 'requestor']);

        $recipients = User::query()
            ->permission('Approve Requisition')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $title = __('New requisition :number', [
            'number' => $requisition->requisition_number,
        ]);

        $body = __(':department requested :count item(s).', [
            'department' => $requisition->department?->name ?? __('Unknown department'),
            'count' => $requisition->items()->count(),
        ]);

        $url = RequisitionResource::getUrl('view', ['record' => $requisition->id]);

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title($title)
                ->body($body)
                ->icon('heroicon-o-clipboard-document-list')
                ->actions([
                    Action::make('view')
                        ->label(__('View'))
                        ->url($url)
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }
    }
}
