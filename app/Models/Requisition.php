<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Enums\ReceiptStatus;
use Modules\Inventory\Enums\RequisitionStatus;

class Requisition extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    protected $fillable = [
        'requisition_number',
        'requestor_id',
        'department_id',
        'location_id',
        'branch_id',
        'store_id',
        'status',
        'endorsed_by',
        'endorsed_at',
        'approved_by',
        'approved_at',
        'declined_by',
        'declined_at',
        'decline_reason',
        'issued_by',
        'issued_at',
        'closed_reason',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'status' => RequisitionStatus::class,
        'endorsed_at' => 'datetime',
        'approved_at' => 'datetime',
        'declined_at' => 'datetime',
        'issued_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Requisitions are served by the branch's main store unless the
        // requester picked a specific store.
        static::creating(function (Requisition $requisition): void {
            if ($requisition->store_id === null && filled($requisition->branch_id)) {
                $requisition->store_id = Store::mainForBranch((string) $requisition->branch_id)->getKey();
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(RequisitionIssue::class);
    }

    public function issuesAwaitingConfirmation(): HasMany
    {
        return $this->issues()->where('receipt_status', ReceiptStatus::AwaitingConfirmation->value);
    }

    /**
     * Approved or partially issued requisitions this user may issue from:
     * stores they keep, stores with no keepers, or every store for users
     * with `manage_all_stores`.
     */
    public function scopeIssuableBy(Builder $query, User $user): Builder
    {
        $query->whereIn('status', [RequisitionStatus::Approved->value, RequisitionStatus::PartiallyIssued->value]);

        if ($user->can('manage_all_stores')) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user): void {
            $inner->whereHas('store.keepers', fn (Builder $k) => $k->whereKey($user->getKey()))
                ->orWhereDoesntHave('store.keepers');
        });
    }

    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function endorsedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function declinedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declined_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
