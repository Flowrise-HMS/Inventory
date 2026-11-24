<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lot of one item in a stock-take.
 *
 * @property string $id
 * @property string $stock_take_id
 * @property string $inventory_item_id
 * @property string|null $lot_number
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property int $system_quantity on hand when the stock-take started
 * @property int|null $book_quantity on hand when this line was counted
 * @property bool $is_found_item
 * @property int|null $counted_quantity
 * @property string|null $variance_reason
 * @property int|null $counted_by
 * @property \Illuminate\Support\Carbon|null $counted_at
 * @property string|null $adjustment_transaction_id
 */
class StockTakeLine extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    protected $fillable = [
        'stock_take_id',
        'inventory_item_id',
        'lot_number',
        'expiry_date',
        'system_quantity',
        'book_quantity',
        'is_found_item',
        'counted_quantity',
        'variance_reason',
        'counted_by',
        'counted_at',
        'adjustment_transaction_id',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'system_quantity' => 'integer',
        'book_quantity' => 'integer',
        'is_found_item' => 'boolean',
        'counted_quantity' => 'integer',
        'counted_at' => 'datetime',
    ];

    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function adjustmentTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'adjustment_transaction_id');
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /**
     * Counted minus what the ledger said at the moment of counting, so stock
     * issued earlier in the stock-take is not mistaken for a loss. Null until
     * counted.
     */
    public function variance(): ?int
    {
        return $this->counted_quantity === null ? null : $this->counted_quantity - (int) $this->book_quantity;
    }
}
