<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Models\Branch;
use Modules\Inventory\Enums\TransactionType;

class InventoryTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    protected $fillable = [
        'inventory_item_id',
        'delta',
        'quantity_after',
        'transaction_type',
        'lot_number',
        'expiry_date',
        'from_location_type',
        'from_location_id',
        'to_location_type',
        'to_location_id',
        'reference_type',
        'reference_id',
        'unit_label_snapshot',
        'performed_by',
        'branch_id',
    ];

    protected $casts = [
        'delta' => 'integer',
        'quantity_after' => 'integer',
        'transaction_type' => TransactionType::class,
        'expiry_date' => 'date',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
