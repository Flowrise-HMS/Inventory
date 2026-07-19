<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Core\Support\AppSettings;

class InventoryReorderAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{
     *     inventory_item_id: string,
     *     item_name: string,
     *     branch_id: string,
     *     branch_name: string,
     *     quantity_on_hand: int,
     *     reorder_point: int,
     *     quantity_to_order: int
     * }>  $suggestions
     */
    public function __construct(
        public array $suggestions,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        try {
            $settings = app(AppSettings::class)->notifications();
            $billing = app(AppSettings::class)->billing();

            if ($settings->inventory_reorder_alerts_enabled) {
                $channels[] = 'mail';

                if ($billing->sms_enabled && ($notifiable->phone ?? null)) {
                    $channels[] = 'sms';
                }
            }
        } catch (\Throwable) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = (new MailMessage)
            ->subject(__('FlowRise HMS - Inpatient Dispensary Low-Stock Reorder Alert'))
            ->greeting(__('Hello, Procurement Officer,'))
            ->line(__('This is an automated alert from the FlowRise HMS Inventory module. The following inpatient dispensary items have dropped below their designated reorder points:'))
            ->line('');

        foreach ($this->suggestions as $item) {
            $email->line(sprintf(
                '• %s (Branch: %s) — On Hand: %d, Reorder Point: %d (Suggested order: %d)',
                $item['item_name'],
                $item['branch_name'],
                $item['quantity_on_hand'],
                $item['reorder_point'],
                $item['quantity_to_order']
            ));
        }

        return $email
            ->line('')
            ->line(__('To review and automatically generate draft purchase orders, please click the button below to open the Purchase Orders dashboard.'))
            ->action(__('Generate Purchase Orders'), url('/admin/purchase-orders'))
            ->line(__('Thank you for using FlowRise HMS.'));
    }

    public function toSms(object $notifiable): string
    {
        return __('FlowRise Alert: :count items are below their reorder points. Please review the purchase orders dashboard to generate draft POs.', [
            'count' => count($this->suggestions),
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Dispensary Low-Stock Reorder Alert'),
            'message' => __('Multiple dispensary items are below their reorder points. Draft POs can be generated.'),
            'items_count' => count($this->suggestions),
            'suggestions' => $this->suggestions,
        ];
    }
}
