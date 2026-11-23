<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Enums\ReceiptStatus;
use Modules\Inventory\Enums\RemainderAction;
use Modules\Inventory\Enums\ShortIssueReason;

/**
 * One line of one hand-over at the store counter. Lines handed over together
 * share a handover_reference and the collector's signature.
 *
 * @property string $id
 * @property string $requisition_id
 * @property string $requisition_item_id
 * @property string $inventory_item_id
 * @property string|null $store_id
 * @property string $handover_reference
 * @property int $quantity_issued
 * @property int $quantity_outstanding_before
 * @property ShortIssueReason|null $short_issue_reason
 * @property string|null $short_issue_note
 * @property RemainderAction|null $remainder_action
 * @property int $quantity_closed
 * @property int|null $issued_by
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property int|null $collected_by
 * @property string|null $collected_by_name
 * @property string|null $collector_signature_path
 * @property ReceiptStatus $receipt_status
 * @property int|null $quantity_received
 * @property int|null $receipt_confirmed_by
 * @property \Illuminate\Support\Carbon|null $receipt_confirmed_at
 * @property string|null $discrepancy_note
 */
class RequisitionIssue extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    protected $fillable = [
        'requisition_id',
        'requisition_item_id',
        'inventory_item_id',
        'store_id',
        'handover_reference',
        'quantity_issued',
        'quantity_outstanding_before',
        'short_issue_reason',
        'short_issue_note',
        'remainder_action',
        'quantity_closed',
        'issued_by',
        'issued_at',
        'collected_by',
        'collected_by_name',
        'collector_signature_path',
        'receipt_status',
        'quantity_received',
        'receipt_confirmed_by',
        'receipt_confirmed_at',
        'discrepancy_note',
    ];

    protected $casts = [
        'quantity_issued' => 'integer',
        'quantity_outstanding_before' => 'integer',
        'quantity_closed' => 'integer',
        'quantity_received' => 'integer',
        'short_issue_reason' => ShortIssueReason::class,
        'remainder_action' => RemainderAction::class,
        'receipt_status' => ReceiptStatus::class,
        'issued_at' => 'datetime',
        'receipt_confirmed_at' => 'datetime',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(RequisitionItem::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function receiptConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receipt_confirmed_by');
    }

    public function collectorName(): ?string
    {
        return $this->collectedBy?->name ?? $this->collected_by_name;
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->receipt_status === ReceiptStatus::AwaitingConfirmation;
    }

    public function hasSignature(): bool
    {
        return filled($this->collector_signature_path);
    }
}
