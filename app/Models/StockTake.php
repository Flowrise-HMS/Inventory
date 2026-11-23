<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Branch;
use Modules\Inventory\Enums\StockTakeStatus;

/**
 * A physical count of one store against a snapshot of its central stock.
 *
 * @property string $id
 * @property string $reference
 * @property string $store_id
 * @property string $branch_id
 * @property StockTakeStatus $status
 * @property bool $is_blind
 * @property string|null $notes
 * @property int|null $started_by
 * @property \Illuminate\Support\Carbon $started_at
 * @property int|null $submitted_by
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $cancelled_by
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $status_reason
 */
class StockTake extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    protected $fillable = [
        'reference',
        'store_id',
        'branch_id',
        'status',
        'is_blind',
        'notes',
        'started_by',
        'started_at',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'status_reason',
    ];

    protected $casts = [
        'status' => StockTakeStatus::class,
        'is_blind' => 'boolean',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTakeLine::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [StockTakeStatus::InProgress->value, StockTakeStatus::Submitted->value]);
    }

    public function isCounting(): bool
    {
        return $this->status === StockTakeStatus::InProgress;
    }
}
