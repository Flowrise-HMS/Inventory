<?php

namespace Modules\Inventory\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Billing\Settings\BillingSettings;
use Modules\Core\Settings\NotificationSettings;
use Modules\Inventory\Notifications\InventoryReorderAlertNotification;
use Tests\TestCase;

class InventoryReorderAlertNotificationChannelsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_only_in_app_when_alerts_are_disabled(): void
    {
        NotificationSettings::fake(['inventory_reorder_alerts_enabled' => false]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->assertSame(['database'], (new InventoryReorderAlertNotification([]))->via($admin));
    }

    public function test_in_app_and_mail_when_enabled_and_no_sms_without_a_phone_or_when_billing_sms_is_off(): void
    {
        NotificationSettings::fake(['inventory_reorder_alerts_enabled' => true]);
        BillingSettings::fake(['sms_enabled' => true]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->assertSame(['database', 'mail'], (new InventoryReorderAlertNotification([]))->via($admin));

        BillingSettings::fake(['sms_enabled' => false]);
        $this->assertSame(['database', 'mail'], (new InventoryReorderAlertNotification([]))->via($admin));
    }
}
