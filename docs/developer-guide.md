# Inventory Developer Guide

> Use this guide to understand the implemented Inventory module, its internal architecture, services, and extension points.

For current rollout status, see [Module Status](../../docs/shared/module-status.md).

## Canonical References

1. [Modules/Inventory/README.md](../README.md)
2. [Inventory design spec](../../docs/superpowers/specs/2026-07-09-inventory-module-design.md)
3. [Inventory implementation plan](../../docs/superpowers/plans/2026-07-09-inventory-module-implementation.md)

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

## Models (13 models)

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

### StockBalance
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID PK | |
| `inventory_item_id` | UUID FK | |
| `branch_id` | UUID FK | |
| `location_type` | StockLocationType enum | `Dispensary`, `Ward`, `InTransit` |
| `department_id` | UUID? FK | Only meaningful for ward location |
| `stock_transfer_id` | UUID? FK | Only set when `location_type = InTransit` |
| `quantity_on_hand` | int | Current stock level |
| `reorder_point` | int? | Minimum before reorder alert |
| `unit_id` | UUID? FK | Override unit if different from item default |

**Unique constraint:** `[inventory_item_id, branch_id, location_type, department_id, stock_transfer_id]`

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
Fields: `quantity_requested`, `quantity_approved`, `quantity_issued` (cumulative), `notes`.

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

## Services (8 services)

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
approve(Requisition $requisition): void
decline(Requisition $requisition, string $reason): void
cancel(Requisition $requisition): void
issue(Requisition $requisition, array $items): void
close(Requisition $requisition, ?string $reason): void
fetchForRequestor(User $user): LengthAwarePaginator
```

`issue()` delegates to `IssueToWardService` or `IssueToPharmacyService` depending on whether `InventoryItem.medication_id` is set.

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

7 resources under `InventoryCluster` (navigation group: `Clinical`):

| Resource | Model | Pages | Read-only? |
|----------|-------|-------|------------|
| InventoryItems | InventoryItem | List, Create, Edit, View | No |
| Suppliers | Supplier | List, Create, Edit, View | No |
| StockBalances | StockBalance | List, View | Yes |
| InventoryTransactions | InventoryTransaction | List, View | Yes |
| Requisitions | Requisition | List, Create, Edit, View | No |
| PurchaseOrders | PurchaseOrder | List, Create, Edit, View | No |
| StockTransfers | StockTransfer | List, Create, Edit, View | No |

Each resource follows the same directory structure:
- `Schemas/InventoryItemForm.php` / `InventoryItemInfolist.php`
- `Tables/InventoryItemsTable.php`
- `Pages/ListInventoryItems.php` / `CreateInventoryItem.php` / `EditInventoryItem.php` / `ViewInventoryItem.php`

Workflow actions are attached via `Tables\Actions` on the List/View pages (e.g., `RequisitionsTable` has approve/decline/issue actions).

Each resource also has **Print** and **Download** actions that open PDF documents (visible based on document status and user permissions).

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

Defined in `Modules\Core\app\Settings\FeatureSettings.php`:

- `inventory_pharmacy_procurement` — enable pharmacy-linked items and issue-to-pharmacy
- `inventory_ward_requisitions` — enable requisition workflow
- `inventory_inter_branch_transfers` — enable stock transfers between branches

Checked via `Feature::isEnabled('inventory_ward_requisitions')`. When disabled, the corresponding Filament resource is hidden and service methods should be gated.

## Testing

15 feature tests covering the core services:

| Test file | What it tests |
|-----------|---------------|
| `StockLedgerServiceTest` | LockAndIncrement, lockAndDecrement, auto-creates balances, writes transactions |
| `DocumentNumberingServiceTest` | Sequence generation, prefix/date/branch locking |
| `PurchaseOrderServiceTest` | CRUD, submit, partial/full receive, close remaining, cancel |
| `RequisitionServiceTest` | CRUD, approve, decline, issue, cancel, close |
| `InterBranchTransferServiceTest` | CRUD, ship, partial/full receive, close, cancel |

Run with:
```bash
php artisan test --filter='Inventory'
php artisan test Modules/Inventory/tests/ --compact
```

## Extension Points

- **New stock location types** — Add to `StockLocationType` enum, update `StockBalance` unique constraint migration, handle in service logic.
- **New transaction types** — Add to `TransactionType` enum, ensure existing `lockAndIncrement`/`lockAndDecrement` callers work.
- **Custom document prefixes** — `DocumentNumberingService` accepts any prefix string; create additional `DocumentSequence` usage in new services.
- **Additional Filament resources** — Follow the existing pattern: `Schemas/`, `Tables/`, `Pages/` directories per resource, register in `InventoryPlugin`.
- **Cross-module stock integration** — Implement `StockProviderContract` to bridge Inventory ledger with another module's stock system.
- **New feature toggles** — Add boolean to `FeatureSettings`, gate Filament resources via `canView()`, gate service methods.
