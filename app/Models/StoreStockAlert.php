<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open low/out-of-stock alert for one item in one store. Deleted once
 * stock climbs back above the reorder point, so the next drop alerts again.
 *
 * @property string $id
 * @property string $store_id
 * @property string $inventory_item_id
 * @property string $level
 * @property int $quantity_at_alert
 * @property \Illuminate\Support\Carbon $alerted_at
 */
class StoreStockAlert extends Model
{
    use HasUuids;

    public const LEVEL_LOW = 'low';

    public const LEVEL_OUT = 'out';

    protected $keyType = 'string';

    protected $fillable = [
        'store_id',
        'inventory_item_id',
        'level',
        'quantity_at_alert',
        'alerted_at',
    ];

    protected $casts = [
        'quantity_at_alert' => 'integer',
        'alerted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
