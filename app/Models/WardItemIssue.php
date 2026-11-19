<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Location;
use Modules\Inventory\Enums\WardItemIssueCategory;
use Modules\Inventory\Enums\WardItemIssueSeverity;
use Modules\Inventory\Enums\WardItemIssueStatus;
use Modules\Inventory\Enums\WardItemIssueType;

/**
 * Broken, faulty, missing, or maintenance-requiring item reported from a ward.
 * Supports both cataloged InventoryItems and general facility fixtures (beds, taps, ACs).
 *
 * @property string $id
 * @property string $issue_number
 * @property string $branch_id
 * @property string $location_id
 * @property ?string $bed_id
 * @property ?string $inventory_item_id
 * @property string $item_name
 * @property ?string $serial_or_asset_tag
 * @property WardItemIssueCategory $category
 * @property WardItemIssueType $issue_type
 * @property WardItemIssueSeverity $severity
 * @property int $quantity_affected
 * @property string $description
 * @property WardItemIssueStatus $status
 * @property bool $bed_blocked
 * @property int $reported_by
 * @property Carbon $reported_at
 * @property ?int $verified_by
 * @property ?Carbon $verified_at
 * @property ?int $assigned_to
 * @property ?int $resolved_by
 * @property ?Carbon $resolved_at
 * @property ?string $resolution_notes
 */
class WardItemIssue extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ward_item_issues';

    protected $keyType = 'string';

    protected $fillable = [
        'issue_number',
        'branch_id',
        'location_id',
        'bed_id',
        'inventory_item_id',
        'item_name',
        'serial_or_asset_tag',
        'category',
        'issue_type',
        'severity',
        'quantity_affected',
        'description',
        'status',
        'bed_blocked',
        'reported_by',
        'reported_at',
        'verified_by',
        'verified_at',
        'assigned_to',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'category' => WardItemIssueCategory::class,
        'issue_type' => WardItemIssueType::class,
        'severity' => WardItemIssueSeverity::class,
        'status' => WardItemIssueStatus::class,
        'quantity_affected' => 'integer',
        'bed_blocked' => 'boolean',
        'reported_at' => 'datetime',
        'verified_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'bed_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', WardItemIssueStatus::openValues());
    }

    public function scopeForWard($query, string $wardId)
    {
        return $query->where('location_id', $wardId);
    }
}
