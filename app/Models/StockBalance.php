<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Unit;
use Modules\Inventory\Enums\StockLocationType;

class StockBalance extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    protected $fillable = [
        'inventory_item_id',
        'branch_id',
        'location_type',
        'department_id',
        'stock_transfer_id',
        'quantity_on_hand',
        'reorder_point',
        'unit_id',
    ];

    protected $casts = [
        'quantity_on_hand' => 'integer',
        'reorder_point' => 'integer',
        'location_type' => StockLocationType::class,
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
