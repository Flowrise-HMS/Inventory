<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Models\Branch;
use Modules\Inventory\Enums\StoreType;

/**
 * A physical store room (General Stores, Medical Stores, Linen Store, ...)
 * inside a branch. Central ("dispensary" location type) stock balances always
 * belong to exactly one store; every branch has one main store that receives
 * purchase orders and transfers and serves requisitions by default.
 */
class Store extends Model
{
    use HasFactory, HasUuids;

    public const MAIN_STORE_CODE = 'MAIN';

    protected $keyType = 'string';

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'type',
        'description',
        'is_main',
        'is_active',
    ];

    protected $casts = [
        'type' => StoreType::class,
        'is_main' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Store $store): void {
            if ($store->exists && $store->isDirty('is_main') && ! $store->is_main) {
                throw new \RuntimeException('A branch must keep a main store. Mark another store as main instead.');
            }

            if ($store->is_main && ! $store->is_active) {
                throw new \RuntimeException('The main store cannot be deactivated.');
            }
        });

        static::saved(function (Store $store): void {
            // Query-builder update: skips the saving guard above on purpose,
            // since demoting the previous main store is the point here.
            if ($store->is_main && ($store->wasRecentlyCreated || $store->wasChanged('is_main'))) {
                static::query()
                    ->where('branch_id', $store->branch_id)
                    ->whereKeyNot($store->getKey())
                    ->where('is_main', true)
                    ->update(['is_main' => false]);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function keepers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_keepers')->withTimestamps();
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Stores a user works with: the ones they keep, plus unkept stores; every
     * store for users with `manage_all_stores`.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('manage_all_stores')) {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner
            ->whereHas('keepers', fn (Builder $k) => $k->whereKey($user->getKey()))
            ->orWhereDoesntHave('keepers'));
    }

    public function scopeForBranch(Builder $query, string $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function isKeeper(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->keepers()->whereKey($user->getKey())->exists();
    }

    public function hasKeepers(): bool
    {
        return $this->keepers()->exists();
    }

    /**
     * The branch's main store id, or null when the branch has none yet.
     * Read paths use this so they never create rows.
     */
    public static function mainIdForBranch(string $branchId): ?string
    {
        $id = static::query()
            ->where('branch_id', $branchId)
            ->where('is_main', true)
            ->value('id');

        return $id !== null ? (string) $id : null;
    }

    /**
     * The branch's main store, created on first use so existing write paths
     * (purchase order receipts, transfers, opening stock) keep working for
     * branches created after the stores migration ran.
     */
    public static function mainForBranch(string $branchId): self
    {
        $existing = static::query()
            ->where('branch_id', $branchId)
            ->where('is_main', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return static::query()->create([
                'branch_id' => $branchId,
                'name' => 'Main Store',
                'code' => self::MAIN_STORE_CODE,
                'type' => StoreType::General,
                'is_main' => true,
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it, or a non-main store already
            // uses the MAIN code: promote that row rather than failing.
            $store = static::query()
                ->where('branch_id', $branchId)
                ->where(fn (Builder $q) => $q->where('is_main', true)->orWhere('code', self::MAIN_STORE_CODE))
                ->orderByDesc('is_main')
                ->firstOrFail();

            if (! $store->is_main) {
                $store->update(['is_main' => true, 'is_active' => true]);
            }

            return $store;
        }
    }
}
