<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Classes\Services\StockTakeService;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\StockTakeResource;
use Modules\Inventory\Models\Store;

class CreateStockTake extends CreateRecord
{
    protected static string $resource = StockTakeResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('Start stock-take');
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(StockTakeService::class)->start(
                Store::query()->findOrFail($data['store_id']),
                (bool) ($data['is_blind'] ?? false),
                $data['notes'] ?? null,
            );
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title(__('Could not start'))->body($e->getMessage())->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Snapshot taken. Start counting.');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
