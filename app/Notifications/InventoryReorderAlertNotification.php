<?php

namespace Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Core\Notifications\Concerns\ResolvesNotificationChannels;
use Modules\Core\Support\AppSettings;

class InventoryReorderAlertNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationChannels;

    /**
     * @param  list<array{
     *     inventory_item_id: string,
     *     item_name: string,
     *     branch_id: string,
     *     branch_name: string,
     *     quantity_on_hand: int,
     *     reorder_point: int,
     *     quantity_to_order: int,
     *     store_name?: string
     * }>  $suggestions
     * @param  list<array{
     *     item_name: string,
     *     store_name: string,
     *     lot_number: ?string,
     *     expiry_date: string,
     *     quantity_on_hand: int,
     *     is_expired: bool
     * }>  $expiring  lots expiring soon or already expired on the shelf
     */
    public function __construct(
        public array $suggestions,
        public array $expiring = [],
    ) {}

    public function via(object $notifiable): array
    {
        try {
            $enabled = (bool) app(AppSettings::class)->notifications()->inventory_reorder_alerts_enabled;
        } catch (\Throwable) {
            $enabled = true;
        }

        if (! $enabled) {
            return $this->channelsFor($notifiable, ['database']);
        }

        return $this->settingsChannels($notifiable, 'inventory_reorder_alerts_enabled', 'inventory_reorder_alerts_enabled', wanted: ['database', 'mail', 'sms']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = (new MailMessage)
            ->subject(__('FlowRise HMS - Stores Low-Stock and Expiry Alert'))
            ->greeting(__('Hello,'))
            ->line(__('This is an automated alert from the FlowRise HMS Inventory module.'));

        if ($this->suggestions !== []) {
            $email->line(__('These store items are at or below their reorder points:'));

            foreach ($this->suggestions as $item) {
                $email->line(sprintf(
                    '• %s (%s, %s) — On Hand: %d, Reorder Point: %d (Suggested order: %d)',
                    $item['item_name'],
                    $item['store_name'] ?? __('Main Store'),
                    $item['branch_name'],
                    $item['quantity_on_hand'],
                    $item['reorder_point'],
                    $item['quantity_to_order']
                ));
            }
        }

        if ($this->expiring !== []) {
            $email->line('')->line(__('These lots are expired or expiring soon:'));

            foreach ($this->expiring as $lot) {
                $email->line(sprintf(
                    '• %s (%s) — Lot %s, %s %s, Qty %d',
                    $lot['item_name'],
                    $lot['store_name'],
                    $lot['lot_number'] ?? '-',
                    $lot['is_expired'] ? __('expired') : __('expires'),
                    $lot['expiry_date'],
                    $lot['quantity_on_hand'],
                ));
            }
        }

        return $email
            ->line('')
            ->line(__('To review and automatically generate draft purchase orders, please click the button below to open the Purchase Orders dashboard.'))
            ->action(__('Generate Purchase Orders'), url('/admin/purchase-orders'))
            ->line(__('Thank you for using FlowRise HMS.'));
    }

    public function toSms(object $notifiable): string
    {
        return __('FlowRise Alert: :count store items are below their reorder points and :expiring lots are expired or expiring soon.', [
            'count' => count($this->suggestions),
            'expiring' => count($this->expiring),
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Stores Low-Stock and Expiry Alert'),
            'message' => __(':count item(s) at or below reorder point; :expiring lot(s) expired or expiring soon.', [
                'count' => count($this->suggestions),
                'expiring' => count($this->expiring),
            ]),
            'items_count' => count($this->suggestions),
            'suggestions' => $this->suggestions,
            'expiring' => $this->expiring,
        ];
    }
}
