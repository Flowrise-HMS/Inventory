<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Core\Models\Unit;
use Modules\Inventory\Enums\StockLocationType;

class StockBalance extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    protected $fillable = [
        'inventory_item_id',
        'branch_id',
        'store_id',
        'location_type',
        'department_id',
        'location_id',
        'stock_transfer_id',
        'lot_number',
        'expiry_date',
        'quantity_on_hand',
        'reorder_point',
        'max_level',
        'unit_id',
    ];

    protected $casts = [
        'quantity_on_hand' => 'integer',
        'reorder_point' => 'integer',
        'max_level' => 'integer',
        'location_type' => StockLocationType::class,
        'expiry_date' => 'date',
    ];

    protected static function booted(): void
    {
        // Central (dispensary) stock must always belong to a store. Rows created
        // without one (factories, legacy callers) land in the branch's main store.
        static::creating(function (StockBalance $balance): void {
            $locationType = enum_try_from(StockLocationType::class, $balance->location_type);

            if ($locationType === StockLocationType::Dispensary) {
                $balance->store_id ??= Store::mainForBranch((string) $balance->branch_id)->getKey();
            } else {
                $balance->store_id = null;
            }
        });
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Central stock on hand for an item in one store. Defaults to the
     * branch's main store when no store is given.
     */
    public static function dispensaryOnHand(string $inventoryItemId, string $branchId, ?string $storeId = null): int
    {
        $storeId ??= Store::mainIdForBranch($branchId);

        if ($storeId === null) {
            return 0;
        }

        return (int) static::query()
            ->where('inventory_item_id', $inventoryItemId)
            ->where('branch_id', $branchId)
            ->where('store_id', $storeId)
            ->where('location_type', StockLocationType::Dispensary)
            ->whereNull('department_id')
            ->whereNull('stock_transfer_id')
            ->sum('quantity_on_hand');
    }
}
