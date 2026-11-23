<?php

namespace Modules\Inventory\Classes\Services;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Classes\Services\WardAccessService;
use Modules\Core\Enums\UserRole;
use Modules\Inventory\Enums\ReceiptStatus;
use Modules\Inventory\Enums\RemainderAction;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Enums\ShortIssueReason;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionIssue;
use Modules\Inventory\Models\RequisitionItem;

/**
 * Store-counter hand-overs of requisitioned items and the ward's
 * confirmation of what it actually received.
 */
class RequisitionIssueService
{
    /** Largest accepted signature image, in bytes (after base64 decoding). */
    public const MAX_SIGNATURE_BYTES = 512 * 1024;

    public function __construct(
        protected IssueToWardService $issueToWard,
        protected IssueToPharmacyService $issueToPharmacy,
        protected WardAccessService $wardAccess,
    ) {}

    /**
     * Issue one or more lines of a requisition in a single hand-over.
     *
     * Each line: requisition_item_id, quantity (0 allowed when closing),
     * and, when issuing less than outstanding, short_issue_reason,
     * short_issue_note (required for "other") and remainder_action.
     *
     * Collector: user_id and/or name, plus signature (PNG data URI). Pass null
     * only for programmatic issues with no counter hand-over.
     *
     * @param  list<array{requisition_item_id: string, quantity: int|string|null, short_issue_reason?: ?string, short_issue_note?: ?string, remainder_action?: ?string}>  $lines
     * @param  array{user_id?: int|string|null, name?: ?string, signature?: ?string}|null  $collector
     * @return Collection<int, RequisitionIssue>
     */
    public function issueHandover(
        Requisition $requisition,
        array $lines,
        ?array $collector = null,
        bool $requireShortIssueDetails = true,
    ): Collection {
        if (! in_array($requisition->status, [RequisitionStatus::Approved, RequisitionStatus::PartiallyIssued], true)) {
            throw new \RuntimeException('Only approved or partially issued requisitions can be issued.');
        }

        $planned = $this->planLines($requisition, $lines, $requireShortIssueDetails);

        if ($planned === []) {
            throw new \RuntimeException('Enter a quantity to issue, or close the remainder, for at least one item.');
        }

        $handsOverStock = collect($planned)->contains(fn (array $line): bool => $line['quantity'] > 0);
        [$collectorId, $collectorName] = $this->resolveCollector($collector, $handsOverStock);

        $signaturePath = null;

        if ($handsOverStock && $collector !== null) {
            $signature = $collector['signature'] ?? null;

            if (blank($signature)) {
                if ((bool) config('inventory.requisitions.require_collector_signature', true)) {
                    throw new \RuntimeException('The person collecting the items must sign.');
                }
            } else {
                $signaturePath = $this->storeSignature((string) $signature);
            }
        }

        try {
            $issues = DB::transaction(function () use ($requisition, $planned, $collectorId, $collectorName, $signaturePath): Collection {
                $reference = (string) Str::uuid();
                $now = now();
                $issues = collect();

                foreach ($planned as $line) {
                    /** @var RequisitionItem $item */
                    $item = RequisitionItem::query()->lockForUpdate()->findOrFail($line['item']->getKey());
                    $outstanding = $item->outstandingQuantity();

                    // Re-check under the row lock: another hand-over may have run since planning.
                    if ($line['quantity'] > $outstanding) {
                        throw new \RuntimeException(sprintf(
                            'Cannot issue %d of %s: only %d still outstanding.',
                            $line['quantity'],
                            $item->inventoryItem?->name ?? 'item',
                            $outstanding,
                        ));
                    }

                    if ($line['quantity'] > 0) {
                        $item->inventoryItem?->medication_id
                            ? $this->issueToPharmacy->issue($item, $line['quantity'])
                            : $this->issueToWard->issue($item, $line['quantity']);
                    }

                    $closed = $line['remainder_action'] === RemainderAction::Close
                        ? $outstanding - $line['quantity']
                        : 0;

                    if ($closed > 0) {
                        $item->increment('quantity_closed', $closed);
                    }

                    $issues->push(RequisitionIssue::query()->create([
                        'requisition_id' => $requisition->getKey(),
                        'requisition_item_id' => $item->getKey(),
                        'inventory_item_id' => $item->inventory_item_id,
                        'store_id' => $requisition->store_id,
                        'handover_reference' => $reference,
                        'quantity_issued' => $line['quantity'],
                        'quantity_outstanding_before' => $outstanding,
                        'short_issue_reason' => $line['short_issue_reason']?->value,
                        'short_issue_note' => $line['short_issue_note'],
                        'remainder_action' => $line['remainder_action']?->value,
                        'quantity_closed' => $closed,
                        'issued_by' => Auth::id(),
                        'issued_at' => $now,
                        'collected_by' => $line['quantity'] > 0 ? $collectorId : null,
                        'collected_by_name' => $line['quantity'] > 0 ? $collectorName : null,
                        'collector_signature_path' => $line['quantity'] > 0 ? $signaturePath : null,
                        // Nothing physical changed hands on a close-only line.
                        'receipt_status' => $line['quantity'] > 0
                            ? ReceiptStatus::AwaitingConfirmation->value
                            : ReceiptStatus::Confirmed->value,
                        'quantity_received' => $line['quantity'] > 0 ? null : 0,
                    ]));
                }

                $this->refreshStatus($requisition);

                return $issues;
            });
        } catch (\Throwable $e) {
            if ($signaturePath !== null) {
                $this->signatureDisk()->delete($signaturePath);
            }

            throw $e;
        }

        return $issues;
    }

    /**
     * Ward confirms (or disputes) what it received for each awaiting line.
     *
     * @param  array<string, array{quantity_received: int|string|null, discrepancy_note?: ?string}>  $confirmations  keyed by requisition issue id
     * @return Collection<int, RequisitionIssue>
     */
    public function confirmReceipt(Requisition $requisition, array $confirmations, ?User $user = null): Collection
    {
        $user ??= Auth::user();

        if (! $user instanceof User) {
            throw new \RuntimeException('A signed-in user must confirm receipt.');
        }

        $disputed = collect();

        $confirmed = DB::transaction(function () use ($requisition, $confirmations, $user, $disputed): Collection {
            $result = collect();

            foreach ($confirmations as $issueId => $data) {
                /** @var RequisitionIssue $issue */
                $issue = RequisitionIssue::query()
                    ->where('requisition_id', $requisition->getKey())
                    ->lockForUpdate()
                    ->findOrFail($issueId);

                if (! $issue->isAwaitingConfirmation()) {
                    throw new \RuntimeException('This hand-over has already been confirmed or disputed.');
                }

                if (! $this->canConfirmReceipt($user, $issue)) {
                    throw new \RuntimeException('You cannot confirm receipt for this requisition.');
                }

                $received = $data['quantity_received'] ?? null;

                if ($received === null || $received === '' || ! is_numeric($received) || (int) $received != $received) {
                    throw new \RuntimeException('Enter the quantity actually received.');
                }

                $received = (int) $received;

                if ($received < 0 || $received > $issue->quantity_issued) {
                    throw new \RuntimeException(sprintf('Quantity received must be between 0 and %d.', $issue->quantity_issued));
                }

                $note = trim((string) ($data['discrepancy_note'] ?? ''));
                $isShort = $received < $issue->quantity_issued;

                if ($isShort && $note === '') {
                    throw new \RuntimeException('Explain the difference when less was received than issued.');
                }

                $issue->update([
                    'quantity_received' => $received,
                    'receipt_status' => $isShort ? ReceiptStatus::Disputed->value : ReceiptStatus::Confirmed->value,
                    'receipt_confirmed_by' => $user->getKey(),
                    'receipt_confirmed_at' => now(),
                    'discrepancy_note' => $note !== '' ? $note : null,
                ]);

                if ($isShort) {
                    $disputed->push($issue);
                }

                $result->push($issue);
            }

            return $result;
        });

        if ($disputed->isNotEmpty()) {
            $this->notifyDispute($requisition, $disputed);
        }

        return $confirmed;
    }

    /**
     * The requester, the named collector, or the ward's incharge may confirm;
     * never the storekeeper who issued it.
     */
    public function canConfirmReceipt(User $user, RequisitionIssue $issue): bool
    {
        if (! $issue->isAwaitingConfirmation() || (int) $issue->issued_by === (int) $user->getKey()) {
            return false;
        }

        $requisition = $issue->requisition;

        if ((int) $requisition->requestor_id === (int) $user->getKey()) {
            return true;
        }

        if ($issue->collected_by !== null && (int) $issue->collected_by === (int) $user->getKey()) {
            return true;
        }

        return filled($requisition->location_id)
            && $this->wardAccess->isWardIncharge($user, $requisition->location_id);
    }

    /**
     * @return Collection<int, RequisitionIssue>
     */
    public function confirmableIssues(Requisition $requisition, User $user): Collection
    {
        return $requisition->issuesAwaitingConfirmation()
            ->with(['inventoryItem', 'collectedBy', 'requisition'])
            ->orderBy('issued_at')
            ->get()
            ->filter(fn (RequisitionIssue $issue): bool => $this->canConfirmReceipt($user, $issue))
            ->values();
    }

    /**
     * Recompute a requisition's status from its lines.
     */
    public function refreshStatus(Requisition $requisition): void
    {
        $requisition->load('items');

        $allSettled = $requisition->items->every(fn (RequisitionItem $i): bool => $i->outstandingQuantity() === 0);
        $totalIssued = (int) $requisition->items->sum('quantity_issued');
        $totalClosed = (int) $requisition->items->sum('quantity_closed');

        if ($allSettled && $totalIssued === 0) {
            $requisition->update([
                'status' => RequisitionStatus::Closed,
                'closed_reason' => $requisition->closed_reason ?? __('Closed at the store: nothing could be issued.'),
                'closed_at' => now(),
            ]);

            return;
        }

        if ($allSettled) {
            $requisition->update([
                'status' => RequisitionStatus::Issued,
                'issued_by' => Auth::id() ?? $requisition->issued_by,
                'issued_at' => now(),
            ]);

            return;
        }

        if ($totalIssued > 0 || $totalClosed > 0) {
            $requisition->update(['status' => RequisitionStatus::PartiallyIssued]);
        }
    }

    public function signatureDataUri(RequisitionIssue $issue): ?string
    {
        if (! $issue->hasSignature() || ! $this->signatureDisk()->exists($issue->collector_signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) $this->signatureDisk()->get($issue->collector_signature_path));
    }

    public function signatureDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk((string) config('inventory.requisitions.signature_disk', 'local'));
    }

    /**
     * Validate a PNG data URI from the signature pad and store it privately.
     */
    public function storeSignature(string $dataUri): string
    {
        $prefix = 'data:image/png;base64,';

        if (! str_starts_with($dataUri, $prefix)) {
            throw new \RuntimeException('The signature must be a PNG image.');
        }

        $binary = base64_decode(substr($dataUri, strlen($prefix)), true);

        if ($binary === false || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new \RuntimeException('The signature image is not valid.');
        }

        if (strlen($binary) > self::MAX_SIGNATURE_BYTES) {
            throw new \RuntimeException('The signature image is too large.');
        }

        $path = sprintf('inventory/signatures/%s/%s.png', now()->format('Y/m'), Str::uuid());
        $this->signatureDisk()->put($path, $binary);

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{item: RequisitionItem, quantity: int, short_issue_reason: ?ShortIssueReason, short_issue_note: ?string, remainder_action: ?RemainderAction}>
     */
    protected function planLines(Requisition $requisition, array $lines, bool $requireShortIssueDetails): array
    {
        $items = $requisition->items()->with('inventoryItem')->get()->keyBy(fn (RequisitionItem $i) => (string) $i->getKey());
        $planned = [];

        foreach ($lines as $line) {
            $item = $items->get((string) ($line['requisition_item_id'] ?? ''));

            if (! $item) {
                throw new \RuntimeException('An item does not belong to this requisition.');
            }

            $rawQty = $line['quantity'] ?? 0;

            if ($rawQty === null || $rawQty === '') {
                $rawQty = 0;
            }

            if (! is_numeric($rawQty) || (int) $rawQty != $rawQty || (int) $rawQty < 0) {
                throw new \RuntimeException('Quantities to issue must be whole numbers of zero or more.');
            }

            $qty = (int) $rawQty;
            $outstanding = $item->outstandingQuantity();
            $name = $item->inventoryItem?->name ?? 'item';

            if ($qty > $outstanding) {
                throw new \RuntimeException(sprintf('Cannot issue %d of %s: only %d still outstanding.', $qty, $name, $outstanding));
            }

            // Filament enum-backed fields hand over enum instances; API callers send strings.
            $reason = $this->enumFrom(ShortIssueReason::class, $line['short_issue_reason'] ?? null);
            $note = filled($line['short_issue_note'] ?? null) ? trim((string) $line['short_issue_note']) : null;
            $action = $this->enumFrom(RemainderAction::class, $line['remainder_action'] ?? null);
            $isShort = $qty < $outstanding;

            if (! $isShort) {
                $reason = null;
                $action = null;
                $note = null;
            } elseif ($requireShortIssueDetails) {
                if ($reason === null) {
                    throw new \RuntimeException(sprintf('Give a reason for issuing less %s than outstanding.', $name));
                }

                if ($reason === ShortIssueReason::Other && $note === null) {
                    throw new \RuntimeException(sprintf('Describe the reason for the short issue of %s.', $name));
                }

                if ($action === null) {
                    throw new \RuntimeException(sprintf('Choose whether to backorder or close the rest of %s.', $name));
                }
            } else {
                $action ??= RemainderAction::Backorder;
            }

            // Zero issued and nothing closed changes nothing: skip the line.
            if ($qty === 0 && $action !== RemainderAction::Close) {
                continue;
            }

            $planned[] = [
                'item' => $item,
                'quantity' => $qty,
                'short_issue_reason' => $reason,
                'short_issue_note' => $note,
                'remainder_action' => $action,
            ];
        }

        return $planned;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    protected function enumFrom(string $enum, mixed $value): ?\BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        return is_string($value) || is_int($value) ? $enum::tryFrom($value) : null;
    }

    /**
     * @param  array<string, mixed>|null  $collector
     * @return array{0: ?int, 1: ?string}
     */
    protected function resolveCollector(?array $collector, bool $handsOverStock): array
    {
        if ($collector === null || ! $handsOverStock) {
            return [null, null];
        }

        $userId = filled($collector['user_id'] ?? null) ? (int) $collector['user_id'] : null;
        $name = filled($collector['name'] ?? null) ? trim((string) $collector['name']) : null;

        if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
            throw new \RuntimeException('The selected collector does not exist.');
        }

        if ($userId === null && $name === null) {
            throw new \RuntimeException('Record who is collecting the items.');
        }

        return [$userId, $userId !== null ? null : $name];
    }

    /**
     * @param  Collection<int, RequisitionIssue>  $disputed
     */
    protected function notifyDispute(Requisition $requisition, Collection $disputed): void
    {
        $store = $requisition->store;
        $recipients = $store?->keepers()->get() ?? collect();

        if ($recipients->isEmpty()) {
            $recipients = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', UserRole::SUPER_ADMIN->value))
                ->get();
        }

        if ($recipients->isEmpty()) {
            return;
        }

        $lines = $disputed->map(fn (RequisitionIssue $issue): string => sprintf(
            '%s: issued %d, received %d',
            $issue->inventoryItem?->name ?? __('Item'),
            $issue->quantity_issued,
            $issue->quantity_received,
        ))->implode('; ');

        Notification::make()
            ->title(__('Ward disputed items received'))
            ->body(sprintf('%s (%s): %s', $requisition->requisition_number, $store?->name ?? __('store'), $lines))
            ->danger()
            ->sendToDatabase($recipients);
    }
}
