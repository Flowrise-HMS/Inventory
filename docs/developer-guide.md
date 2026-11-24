# Inventory Developer Guide

> Use this guide to understand the implemented Inventory module, its internal architecture, services, and extension points.

For current rollout status, see [Module Status](../../../docs/shared/module-status.md).

## Canonical References

1. [Modules/Inventory/README.md](../README.md)
2. [Module Status](../../../docs/shared/module-status.md)

## Architectural Role

Inventory is the central stock management layer for the hospital's dispensary and supply chain. It handles:

- **Catalog management** — items, suppliers, units of measure
- **Procurement** — purchase orders with partial receipt tracking
- **Ward/department supply** — requisitions with approval workflows
- **Inter-branch logistics** — stock transfers with in-transit state
- **Stock control** — balance tracking, adjustments, full audit ledger

Key design rules:

- **Separate ledger from Pharmacy** — Inventory uses `StockBalance` + `InventoryTransaction`; Pharmacy uses `StockItem` + `StockMovement`. The two systems are linked via `InventoryItem.medication_id` and the `StockProviderContract` bridge for cross-ledger atomic operations.
- **Location types** — Stock exists at `dispensary`, `ward`, or `in_transit` locations. There is no separate `pharmacy` location type in v1 — pharmacy stock flows through the `StockProviderContract` bridge instead.
- **Document numbering** — All user-facing documents (POs, requisitions, transfers) get auto-generated numbers via `DocumentNumberingService`.

## Models (15 models including DocumentSequence and Store)

### InventoryItem
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID PK | |
| `name` | string | Display name |
| `sku` | string? | Unique stock-keeping unit code |
| `description` | text? | |
| `category` | InventoryItemCategory enum | `Supplies`, `Equipment`, `Consumables`, `General` |
| `medication_id` | UUID? FK→Medication | Only set when item is pharmacy-linked |
| `unit_id` | UUID FK→Unit | Core unit of measure |
| `is_active` | bool | Soft toggle |

**Relations:** `medication()`, `unit()`, `stockBalances()`

### Store
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID PK | |
| `branch_id` | UUID FK | |
| `name` / `code` | string | `code` unique per branch; the auto-created main store uses `MAIN` |
| `type` | StoreType enum | `General`, `Medical`, `Linen`, `Stationery`, `Maintenance`, `Other` |
| `is_main` | bool | Exactly one per branch. Setting it on a store demotes the previous main store; it cannot be unset directly |
| `is_active` | bool | The main store cannot be deactivated |

**Relations:** `branch()`, `keepers()` (users via `store_keepers` pivot), `stockBalances()`, `requisitions()`

**Rules:**
- Central stock (`location_type = Dispensary`) always has a `store_id`. `StockLedgerService` resolves a null store to `Store::mainForBranch()` (created lazily), and rejects a store from another branch. Ward and in-transit balances never carry a store.
- `StockBalance` and `Requisition` default `store_id` to the branch main store on create, so factories and legacy callers keep working.
- Purchase order receipts, inter-branch transfers and opening stock go to the main store (no store selector yet). `AutoReorderService` still aggregates per branch across stores.
- `RequisitionPolicy::issue`: once a store has keepers, only those keepers or users with `manage_all_stores` may issue. Stores with no keepers keep the plain `Issue Requisition` check.
- `StorePolicy::delete` refuses the main store and any store with balances or requisitions (the FKs restrict on delete).
- Migration `2026_11_23_000001_create_stores_and_assign_store_to_stock` creates a main store per existing branch and backfills `store_id` on central balances and all requisitions.

### StockBalance
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID PK | |
| `inventory_item_id` | UUID FK | |
| `branch_id` | UUID FK | |
| `store_id` | UUID? FK→Store | Set for central (`Dispensary`) stock only |
| `location_type` | StockLocationType enum | `Dispensary`, `Ward`, `InTransit` |
| `department_id` | UUID? FK | Only meaningful for ward location |
| `stock_transfer_id` | UUID? FK | Only set when `location_type = InTransit` |
| `quantity_on_hand` | int | Current stock level |
| `reorder_point` | int? | Minimum before reorder alert. For store stock, read as MAX across the item's rows in the store |
| `max_level` | int? | Par level for reorder suggestions; same MAX rule |
| `unit_id` | UUID? FK | Override unit if different from item default |

**Unique constraint:** `[inventory_item_id, branch_id, store_id, location_type, department_id, location_id, stock_transfer_id, lot_number, expiry_date]`

### InventoryTransaction
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID PK | |
| `inventory_item_id` | UUID FK | |
| `delta` | int | Positive or negative |
| `quantity_after` | uint | Balance after this transaction |
| `transaction_type` | TransactionType enum | `Receive`, `Issue`, `TransferShip`, `TransferReceive`, `Adjust` |
| `from_location_type` | string? | |
| `from_location_id` | UUID? | Polymorphic location |
| `to_location_type` | string? | |
| `to_location_id` | UUID? | |
| `reference_type` / `reference_id` | nullable morphs | Links to PO receipt, requisition item, transfer item, etc. |
| `unit_label_snapshot` | string? | Unit description at time of transaction |
| `performed_by` | UUID FK→User | |
| `branch_id` | UUID FK | |

### DocumentSequence
Auto-increment PK (not UUID). Used exclusively by `DocumentNumberingService`. Unique composite: `[prefix, branch_id, date]`.

### Supplier
Simple model: `name`, `contact_person`, `email`, `phone`, `is_active`.

### PurchaseOrder
| Field | Type | Notes |
|-------|------|-------|
| `status` | PurchaseOrderStatus | State machine: Draft→Submitted→PartiallyReceived→Received→Closed (+Cancelled) |
| `po_number` | string(30) unique | Auto-generated `PO-YYYYMMDD-NNNN` |
| `supplier_id` | UUID FK | |
| `branch_id` | UUID FK | |
| `ordered_at` | datetime? | |
| `expected_delivery_at` | datetime? | |
| `submitted_by` | UUID? FK→User | |
| `notes` | text? | |

### PurchaseOrderItem
Line items on a PO. Key fields: `quantity_ordered`, `quantity_received` (cumulative across all receipts), `expected_unit_price`.

### PurchaseOrderReceipt
One receipt per receive action against a PO. Fields: `received_at`, `received_by`, `notes`.

### PurchaseOrderReceiptItem
Per-item line within a receipt. Fields: `quantity_received`, `lot_number`, `expiry_date`, `unit_price`. Links to `PurchaseOrderItem`.

### Requisition
| Field | Type | Notes |
|-------|------|-------|
| `status` | RequisitionStatus | Pending→Approved→PartiallyIssued→Issued (+Declined/Cancelled/Closed) |
| `requisition_number` | string(30) unique | Auto-generated `REQ-YYYYMMDD-NNNN` |
| `requestor_id` | UUID FK→User | |
| `department_id` | UUID FK | |
| `branch_id` | UUID FK | |
| `approved_by` / `approved_at` | Set on approve | |
| `declined_by` / `declined_at` / `decline_reason` | Set on decline | |
| `issued_by` / `issued_at` | Set on issue | |

### RequisitionItem
Fields: `quantity_requested`, `quantity_approved`, `quantity_issued` (cumulative), `quantity_closed` (what the store decided never to issue), `notes`.

Helpers: `targetQuantity()` (approved, else requested), `outstandingQuantity()` (target − issued − closed), `isBackordered()`.

### RequisitionIssue (hand-over log)
One row per line per counter hand-over. Lines handed over together share `handover_reference` and the collector's signature.

| Field | Notes |
|-------|-------|
| `quantity_issued`, `quantity_outstanding_before` | What was handed over, and what was owed at that moment |
| `short_issue_reason` | ShortIssueReason: `out_of_stock`, `rationed`, `substituted`, `other` (needs `short_issue_note`) |
| `remainder_action`, `quantity_closed` | RemainderAction `backorder` (line stays open) or `close` (rest written off on the line) |
| `issued_by`, `issued_at`, `store_id` | Storekeeper and serving store |
| `collected_by` / `collected_by_name` | Collector: a user, or a typed name for people without an account |
| `collector_signature_path` | Drawn signature PNG on the private `inventory.requisitions.signature_disk` (default `local`); served by `inventory.requisition-issues.signature` |
| `receipt_status` | ReceiptStatus `awaiting_confirmation` → `confirmed` or `disputed` (close-only lines start `confirmed`) |
| `quantity_received`, `receipt_confirmed_by/at`, `discrepancy_note` | Ward's confirmation; a shortfall needs a note and notifies the store's keepers (super admins if none) |

**Status rules (`RequisitionIssueService::refreshStatus`)**: every line settled with something issued → `Issued`; every line settled with nothing issued → `Closed`; otherwise `PartiallyIssued` (a backorder).

### StockTransfer
| Field | Type | Notes |
|-------|------|-------|
| `status` | StockTransferStatus | Draft→Shipped→PartiallyReceived→Received→Closed (+Cancelled) |
| `transfer_number` | string(30) unique | Auto-generated `TRF-YYYYMMDD-NNNN` |
| `from_branch_id` / `to_branch_id` | UUID FK | Source and destination |
| `from_location_type` / `to_location_type` | string | |
| `shipped_by` / `shipped_at` | Set on ship | |
| `received_by` / `received_at` | Set on receive | |

### StockTransferItem
Fields: `quantity_requested`, `quantity_shipped`, `quantity_received` (cumulative).

## Enums & State Machines

### PurchaseOrderStatus
```
Draft → Submitted → PartiallyReceived → Received → Closed
  \──────────────────────────────→ Cancelled
```

- `Submitted`: ready for receiving
- `PartiallyReceived`: at least one receipt exists, items remain
- `Received`: all items fully received
- `Closed`: manually closed (e.g., abandon remaining)
- `Cancelled`: only from `Draft`/`Submitted`

### RequisitionStatus
```
Pending → Approved → PartiallyIssued → Issued → Closed
  \→ Declined
  \→ Cancelled
```

- `Approved` ≠ issued — must call `issue()` to decrement stock
- `PartiallyIssued`: some items partially issued
- `Issued`: all items fully issued
- `Closed`: manually closed

### StockTransferStatus
```
Draft → Shipped → PartiallyReceived → Received → Closed
  \→ Cancelled
```

- `Shipped`: source branch dispatched; in-transit balances created
- `PartiallyReceived`: some items received
- `Received`: all items received

### TransactionType
`Receive`, `Issue`, `TransferShip`, `TransferReceive`, `Adjust` — describes the business action that caused the ledger entry.

### StockLocationType
`Dispensary`, `Ward`, `InTransit` — where stock currently resides.

### InventoryItemCategory
`Supplies`, `Equipment`, `Consumables`, `General` — high-level categorization.

## Services

### Core workflow services (9)

| Service | Responsibility |
|---------|----------------|
| `StockLedgerService` | Pessimistic locking, increment/decrement, transaction audit |
| `StockTakeService` | Store stock-takes: `start` (one open count per store; snapshots central rows into `stock_take_lines`), `recordCount`, `recordReason`, `addFoundItem`, `submit`, `returnForRecount`, `approve`, `cancel`, `summary`. Approval posts **counted − snapshot** per line through `StockAdjustmentService::adjustStoreLot` (lot- and store-exact; uses `StockLedgerService::lockAndDecrementExact`), so stock issued during the count is not overwritten. Approval fails as a whole, naming the item, if stock moved too far to post a shortfall |
| `StoreStockLevelService` | Per store item levels (`levels`, `setLevels` writes every lot row), `suggestedOrderQuantity` (max level, else reorder point), `lowStock`, `expiring`/`expiringQuery`, and real-time alerts: the ledger calls `queueEvaluation` on every central stock change, which runs `evaluate` after commit (failures are reported, never thrown). Open alerts live in `store_stock_alerts` (one per store+item, level `low`/`out`) and are deleted when stock recovers |
| `RequisitionIssueService` | Counter hand-overs (`issueHandover`: multi-line, short-issue reason + backorder/close, collector + signature, all in one transaction; signature file removed on failure), ward `confirmReceipt` / `canConfirmReceipt` (requester, named collector or ward incharge; never the issuer), status refresh, signature storage. `RequisitionService::issue()` remains as a programmatic single-line issue that backorders any shortfall |
| `DocumentNumberingService` | Sequential PO/REQ/TRF numbers per branch/date |
| `PurchaseOrderService` | PO lifecycle: create, submit, receive, close, cancel |
| `RequisitionService` | Requisition lifecycle: create, approve, decline, cancel, issue, close |
| `IssueToWardService` | Dispensary → ward stock movement |
| `IssueToPharmacyService` | Dispensary → pharmacy via `StockProviderContract` |
| `InterBranchTransferService` | Transfer create, ship, receive, close, cancel |
| `StockAdjustmentService` | Set on-hand quantity to a new value via ledger deltas |
| `InventoryAnalyticsService` | Report page aggregations and CSV export data |

### PDF services (5)

`GoodsReceivedNotePdfService`, `RequisitionVoucherPdfService`, `TransferNotePdfService`, `AdjustmentVoucherPdfService`, `StockCardPdfService` — each with `render()` and `filename()` methods, invoked from single-action controllers.

### StockLedgerService
Core ledger engine with pessimistic locking.

```php
lockAndDecrement(
    inventoryItemId: string,
    branchId: string,
    locationType: StockLocationType,
    quantity: int,
    transactionType: TransactionType,
    ?array $locationIds = null,
    ?string $referenceType = null,
    ?string $referenceId = null,
    ?string $unitLabel = null,
    ?string $performedBy = null,
): void
```

```php
lockAndIncrement(
    // same params as above
): void
```

Both methods lock the `StockBalance` row (or auto-create it) via `lockForUpdate`, apply the delta, write an `InventoryTransaction`, and release.

### DocumentNumberingService
Generates sequential document numbers.

```php
generate(string $prefix, string $branchId): string
```
Format: `{prefix}-{YYYYMMDD}-{NNNN}`. Uses `DocumentSequence` with `lockForUpdate` to prevent duplicates under concurrent requests.

### PurchaseOrderService
```php
create(array $data): PurchaseOrder              // generates PO number
submit(PurchaseOrder $po): void
receive(PurchaseOrder $po, array $receiptData): PurchaseOrderReceipt
closeRemaining(PurchaseOrder $po, ?string $reason): void
cancel(PurchaseOrder $po): void
```

`receive()` validates no overflow on `quantity_received`, updates items cumulatively, calls `StockLedgerService::lockAndIncrement` per item with `TransactionType::Receive`, and transitions PO status to `Received`/`PartiallyReceived`.

### RequisitionService
```php
create(array $data): Requisition
approve(Requisition $requisition, ?array $approvedQuantities = null): void
decline(Requisition $requisition, string $reason): void
cancel(Requisition $requisition): void
issue(RequisitionItem $item, int $qty): void
close(Requisition $requisition, string $reason): void
fetchForRequestor(User $user): Collection
```

`issue()` delegates to `IssueToWardService` or `IssueToPharmacyService` depending on whether `InventoryItem.medication_id` is set. Updates requisition status to `PartiallyIssued` or `Issued`.

### IssueToWardService
Issues stock from dispensary to ward location. Calls `StockLedgerService::lockAndDecrement` from `Dispensary` then `lockAndIncrement` to `Ward` with the same `department_id`.

### IssueToPharmacyService
Issues stock from dispensary to pharmacy system. Calls `StockLedgerService::lockAndDecrement` from `Dispensary` then `StockProviderContract::incrementWithReference()` for the pharmacy ledger. Handles unit conversion via `medication.units_per_stock_unit`.

### InterBranchTransferService
```php
create(array $data): StockTransfer
ship(StockTransfer $transfer): void
receive(StockTransfer $transfer, array $items): void
close(StockTransfer $transfer, ?string $reason): void
cancel(StockTransfer $transfer): void
```

`ship()` decrements source dispensary stock and creates `InTransit` balances. `receive()` destroys `InTransit` balances and increments destination dispensary stock. Both single-ship and cumulative multi-receive are supported.

### StockAdjustmentService
```php
adjust(
    inventoryItemId: string,
    branchId: string,
    locationType: StockLocationType,
    ?string $departmentId,
    int $newQty,
    ?string $reason = null,
): void
```

Calculates `delta = newQty - currentQty`, calls `lockAndIncrement` or `lockAndDecrement` with `TransactionType::Adjust`.

## Filament Resources

7 resources plus one report page under `InventoryCluster`:

| Resource / Page | Model | Pages | Workflow actions in Filament |
|-----------------|-------|-------|------------------------------|
| InventoryItems | InventoryItem | List, Create, Edit, View | — |
| Suppliers | Supplier | List, Create, Edit, View | — |
| StockBalances | StockBalance | List, View | Adjust stock |
| InventoryTransactions | InventoryTransaction | List, View | Read-only |
| Requisitions | Requisition | List, Create, Edit, View | Approve, decline, **Issue items** hand-over modal (list row and Items tab), close, print/download voucher; **Hand-overs** tab shows the issue log with signatures and ward receipt |
| PurchaseOrders | PurchaseOrder | List, Create, Edit, View | Submit, receive (partial modal), close, print/download GRN, generate from low stock |
| StockTransfers | StockTransfer | List, Create, Edit, View | Ship, receive (partial modal), close, print/download note |
| InventoryReport | — | Analytics page | 8 chart widgets, date/branch filters, CSV export |

`MyWardRequestsWidget` (dashboard) lists the current user's requisitions with view, create, and fulfill shortcuts.

Each resource follows the `Schemas/`, `Tables/`, `Pages/` directory pattern.

### PDF Document Printing

Five printable PDF document types are available:

| Document | Route | Source | Controller |
|----------|-------|--------|------------|
| Goods Received Note | `inventory.purchase-orders.grn` | PurchaseOrder with receipts | `GrnPdfController` |
| Requisition Voucher | `inventory.requisitions.voucher` | Requisition (issued) | `RequisitionVoucherPdfController` |
| Stock Transfer Note | `inventory.stock-transfers.note` | StockTransfer (shipped+) | `TransferNotePdfController` |
| Adjustment Voucher | `inventory.transactions.adjustment-voucher` | InventoryTransaction (Adjust type) | `AdjustmentVoucherPdfController` |
| Stock Card | `inventory.items.stock-card` | InventoryItem (all transactions) | `StockCardPdfController` |

All follow the same pattern established by Billing's `InvoicePdfService`: a service class with `render()` and `filename()` methods, a single-action `__invoke` controller, and a Blade template in `resources/views/pdf/`. Controllers check `print_inventory_document` permission (or `download_inventory_document` when `?download=1` is set).

**Permissions:** `print_inventory_document`, `download_inventory_document` — defined in config and registered via Shield.

**PDF library:** `barryvdh/laravel-dompdf` (DomPDF). Uses Core print partials (`pdf-brand-header`, `client-identity`).

### Feature Toggles

Defined in `Modules\Core\Settings\FeatureSettings` and editable via Core **Manage Feature Settings**:

- `inventory_pharmacy_procurement` — pharmacy-linked items and issue-to-pharmacy
- `inventory_ward_requisitions` — requisition workflow
- `inventory_inter_branch_transfers` — inter-branch transfers

Read via `Modules\Inventory\Classes\Support\Feature`:

```php
Feature::pharmacyProcurementEnabled()
Feature::wardRequisitionsEnabled()
Feature::interBranchTransfersEnabled()
```

**Enforcement:** toggles are enforced in services (throw when disabled) and Filament navigation/resources via `Feature::`.

## Testing

21 test files (Feature + Unit):

| Test file | What it tests |
|-----------|---------------|
| `StockLedgerServiceTest` | LockAndIncrement, lockAndDecrement, auto-creates balances, writes transactions |
| `StockLotFefoTest` | Lot balances on PO receive, FEFO decrement on ward issue |
| `DocumentNumberingServiceTest` | Sequence generation, prefix/date/branch locking |
| `PurchaseOrderServiceTest` | CRUD, submit, partial/full receive, close remaining, cancel |
| `RequisitionServiceTest` | Create, approve, decline, issue, close |
| `InterBranchTransferServiceTest` | CRUD, ship, partial/full receive, close, cancel |
| `StockAdjustmentServiceTest` | Adjust stock up/down with audit trail |
| `IssueToPharmacyServiceTest` | Pharmacy bridge via `StockProviderContract` |
| `IssueToWardServiceTest` | Ward issue from approved requisitions |
| `FeatureToggleTest` | Services blocked when toggles disabled |
| `AutoReorderServiceTest` | Draft PO from low stock |
| `CheckStockAlertsCommandTest` | Scheduler registration, settings gates, notification on low stock |
| `PdfGenerationTest` | All five PDF document types |
| `InventoryReportTest` | Analytics report page and CSV export |
| `MyWardRequestsWidgetTest` | Requestor dashboard widget |
| `PharmacyStockItemTableIntegrationTest` | Central store request from Pharmacy |
| `RequisitionNotificationTest` | Requisition status notifications |
| `StockConsumptionServiceTest` | MAR ward consumption integration |
| `CreatePurchaseOrderFormTest` | Filament PO create form |
| `InventoryItemResourceRelationManagersTest` | Item relation managers |
| `FhirInventoryItemTransformerTest` | InventoryItem FHIR transformer (unit) |

Run with:
```bash
php artisan test Modules/Inventory/tests/ --compact
```

## Extension Points

- **New stock location types** — Add to `StockLocationType` enum, update `StockBalance` unique constraint migration, handle in service logic.
- **New transaction types** — Add to `TransactionType` enum, ensure existing `lockAndIncrement`/`lockAndDecrement` callers work.
- **Custom document prefixes** — `DocumentNumberingService` accepts any prefix string; create additional `DocumentSequence` usage in new services.
- **Additional Filament resources** — Follow the existing pattern: `Schemas/`, `Tables/`, `Pages/` directories per resource, register in `InventoryPlugin`.
- **Cross-module stock integration** — Implement `StockProviderContract` to bridge Inventory ledger with another module's stock system.
- **New feature toggles** — Add boolean to `FeatureSettings`, gate Filament resources via `canView()`, gate service methods.

## Remaining scope

- **FHIR SupplyDelivery** — Deferred to Phase 11 interoperability work (InventoryItem read/search is already exposed via the FHIR module).

Scheduled reorder alerts are built: `inventory:check-stock-alerts`, scheduler registration in `InventoryServiceProvider`, and Core `NotificationSettings` (`inventory_reorder_alerts_enabled`, `inventory_reorder_alerts_frequency`).
