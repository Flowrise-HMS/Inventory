<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $requisition_id
 * @property string $inventory_item_id
 * @property int $quantity_requested
 * @property int|null $quantity_approved
 * @property int $quantity_issued
 * @property int $quantity_closed
 * @property string|null $notes
 */
class RequisitionItem extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    protected $fillable = [
        'requisition_id',
        'inventory_item_id',
        'quantity_requested',
        'quantity_approved',
        'quantity_issued',
        'quantity_closed',
        'notes',
    ];

    protected $casts = [
        'quantity_requested' => 'integer',
        'quantity_approved' => 'integer',
        'quantity_issued' => 'integer',
        'quantity_closed' => 'integer',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(RequisitionIssue::class);
    }

    /** The quantity the store owes: approved if set, otherwise requested. */
    public function targetQuantity(): int
    {
        return (int) ($this->quantity_approved ?? $this->quantity_requested);
    }

    /** Still to be issued: target minus what was issued or closed. */
    public function outstandingQuantity(): int
    {
        return max(0, $this->targetQuantity() - (int) $this->quantity_issued - (int) $this->quantity_closed);
    }

    /** Some was issued and the rest is still owed. */
    public function isBackordered(): bool
    {
        return (int) $this->quantity_issued > 0 && $this->outstandingQuantity() > 0;
    }
}
