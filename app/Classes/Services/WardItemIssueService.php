<?php

namespace Modules\Inventory\Classes\Services;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Core\Classes\Services\BedStatusService;
use Modules\Core\Classes\Services\WardAccessService;
use Modules\Core\Enums\BedStatus;
use Modules\Core\Models\Location;
use Modules\Inventory\Enums\WardItemIssueCategory;
use Modules\Inventory\Enums\WardItemIssueSeverity;
use Modules\Inventory\Enums\WardItemIssueStatus;
use Modules\Inventory\Enums\WardItemIssueType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\WardItemIssue;

class WardItemIssueService
{
    public function __construct(
        protected DocumentNumberingService $numbering,
        protected WardAccessService $wardAccess,
        protected BedStatusService $bedStatus,
    ) {}

    /**
     * Report a broken/faulty ward item or fixture.
     *
     * @param  array<string, mixed>  $data
     */
    public function report(Location $ward, array $data, ?User $reporter = null): WardItemIssue
    {
        $reporter ??= Auth::user();

        return DB::transaction(function () use ($ward, $data, $reporter): WardItemIssue {
            $inventoryItem = filled($data['inventory_item_id'] ?? null)
                ? InventoryItem::find($data['inventory_item_id'])
                : null;

            $itemName = trim((string) ($data['item_name'] ?? $inventoryItem?->name ?? 'Ward Item'));
            $isIncharge = $reporter !== null && $this->wardAccess->isWardIncharge($reporter, $ward);
            $initialStatus = $isIncharge
                ? WardItemIssueStatus::INCHARGE_VERIFIED
                : WardItemIssueStatus::REPORTED;

            $blockBed = (bool) ($data['block_bed'] ?? false);
            $bedId = $data['bed_id'] ?? null;
            $bedWasBlocked = false;

            if ($blockBed && filled($bedId)) {
                $bed = Location::withoutGlobalScope('branch')
                    ->whereKey($bedId)
                    ->where('parent_id', $ward->id)
                    ->first();

                if ($bed !== null && $bed->bedStatus() !== BedStatus::OCCUPIED && $bed->bedStatus() !== BedStatus::BLOCKED) {
                    $this->bedStatus->transition(
                        bed: $bed,
                        to: BedStatus::BLOCKED,
                        reason: sprintf('Fault reported: %s', $itemName),
                    );
                    $bedWasBlocked = true;
                }
            }

            $issue = WardItemIssue::create([
                'issue_number' => $this->numbering->generate('WII', $ward->branch_id),
                'branch_id' => $ward->branch_id,
                'location_id' => $ward->id,
                'bed_id' => $bedId,
                'inventory_item_id' => $inventoryItem?->id,
                'item_name' => $itemName,
                'serial_or_asset_tag' => $data['serial_or_asset_tag'] ?? null,
                'category' => $data['category'] instanceof WardItemIssueCategory
                    ? $data['category']->value
                    : ($data['category'] ?? WardItemIssueCategory::MEDICAL_EQUIPMENT->value),
                'issue_type' => $data['issue_type'] instanceof WardItemIssueType
                    ? $data['issue_type']->value
                    : ($data['issue_type'] ?? WardItemIssueType::BROKEN->value),
                'severity' => $data['severity'] instanceof WardItemIssueSeverity
                    ? $data['severity']->value
                    : ($data['severity'] ?? WardItemIssueSeverity::MEDIUM->value),
                'quantity_affected' => max(1, (int) ($data['quantity_affected'] ?? 1)),
                'description' => trim((string) $data['description']),
                'status' => $initialStatus->value,
                'bed_blocked' => $bedWasBlocked,
                'reported_by' => $reporter?->id ?? Auth::id(),
                'reported_at' => now(),
                'verified_by' => $isIncharge ? $reporter?->id : null,
                'verified_at' => $isIncharge ? now() : null,
            ]);

            if (! $isIncharge) {
                $this->notifyWardIncharges($issue, $ward, $reporter);
            }

            return $issue;
        });
    }

    public function verify(WardItemIssue $issue, ?User $verifier = null): WardItemIssue
    {
        $verifier ??= Auth::user();

        $issue->update([
            'status' => WardItemIssueStatus::INCHARGE_VERIFIED,
            'verified_by' => $verifier?->id,
            'verified_at' => now(),
        ]);

        return $issue->fresh();
    }

    public function updateStatus(WardItemIssue $issue, WardItemIssueStatus $status, ?int $assignedTo = null): WardItemIssue
    {
        $updates = ['status' => $status];

        if ($assignedTo !== null) {
            $updates['assigned_to'] = $assignedTo;
        }

        $issue->update($updates);

        return $issue->fresh();
    }

    public function resolve(
        WardItemIssue $issue,
        ?string $resolutionNotes = null,
        bool $condemned = false,
        bool $unblockBed = false,
        ?User $resolver = null,
    ): WardItemIssue {
        $resolver ??= Auth::user();

        return DB::transaction(function () use ($issue, $resolutionNotes, $condemned, $unblockBed, $resolver): WardItemIssue {
            $issue->update([
                'status' => $condemned ? WardItemIssueStatus::CONDEMNED_REPLACED : WardItemIssueStatus::RESOLVED,
                'resolved_by' => $resolver?->id,
                'resolved_at' => now(),
                'resolution_notes' => $resolutionNotes,
            ]);

            if ($unblockBed && $issue->bed_blocked && $issue->bed_id !== null) {
                $bed = Location::withoutGlobalScope('branch')->find($issue->bed_id);
                if ($bed !== null && $bed->bedStatus() === BedStatus::BLOCKED) {
                    $this->bedStatus->transition(
                        bed: $bed,
                        to: BedStatus::CLEANING,
                        reason: sprintf('Issue %s resolved', $issue->issue_number),
                    );
                }
            }

            return $issue->fresh();
        });
    }

    protected function notifyWardIncharges(WardItemIssue $issue, Location $ward, ?User $reporter): void
    {
        $recipients = $this->wardAccess->inchargeUsersForWard($ward)
            ->reject(fn (User $u): bool => $reporter !== null && $u->id === $reporter->id);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('Broken item reported on :ward', ['ward' => $ward->name]))
            ->body(sprintf('%s (%s)', $issue->item_name, $issue->severity->getLabel()))
            ->warning()
            ->sendToDatabase($recipients);
    }
}
