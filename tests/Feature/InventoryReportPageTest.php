<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Modules\Inventory\Filament\Clusters\Inventory\Pages\InventoryReport;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryReportPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_inventory_report_page_renders_widgets_without_cramped_grid_column_wrapper(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $component = Livewire::actingAs($user)->test(InventoryReport::class);

        $component->assertSuccessful();
        $component->assertDontSee('grid gap-4 md:grid-cols-2 mt-6');
        $component->assertSee('fi-wi');
    }
}
