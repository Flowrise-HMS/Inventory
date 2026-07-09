<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\InventoryReportCriteria;
use Modules\Inventory\Enums\InventoryItemCategory;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderReceiptItem;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockTransfer;

class InventoryAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function buildFromCriteria(InventoryReportCriteria $criteria): array
    {
        return [
            'summary' => $this->buildSummary($criteria),
            'items_by_category' => $this->buildItemsByCategory(),
            'transaction_trend' => $this->buildTransactionTrend($criteria),
            'po_status' => $this->buildPoStatus($criteria),
            'requisitions_by_dept' => $this->buildRequisitionsByDept($criteria),
            'stock_by_location' => $this->buildStockByLocation($criteria),
            'low_stock_items' => $this->buildLowStockItems($criteria),
            'recent_transactions' => $this->buildRecentTransactions($criteria),
            'recent_pos' => $this->buildRecentPurchaseOrders($criteria),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSummary(InventoryReportCriteria $criteria): array
    {
        $activeItems = InventoryItem::where('is_active', true)->count();

        $lowStockQuery = StockBalance::whereColumn('quantity_on_hand', '<=', DB::raw('COALESCE(reorder_point, 0)'))
            ->where('quantity_on_hand', '>', 0);
        if ($criteria->branchId) {
            $lowStockQuery->where('branch_id', $criteria->branchId);
        }
        $lowStockCount = $lowStockQuery->count();

        $poQuery = PurchaseOrder::whereBetween('created_at', [$criteria->startDate, $criteria->endDate]);
        if ($criteria->branchId) {
            $poQuery->where('branch_id', $criteria->branchId);
        }
        $poCount = $poQuery->count();

        $reqQuery = Requisition::whereBetween('created_at', [$criteria->startDate, $criteria->endDate]);
        if ($criteria->branchId) {
            $reqQuery->where('branch_id', $criteria->branchId);
        }
        $reqCount = $reqQuery->count();

        $transferQuery = StockTransfer::whereBetween('created_at', [$criteria->startDate, $criteria->endDate]);
        if ($criteria->branchId) {
            $transferQuery->where('from_branch_id', $criteria->branchId)
                ->orWhere('to_branch_id', $criteria->branchId);
        }
        $transferCount = $transferQuery->count();

        $spendQuery = PurchaseOrderReceiptItem::whereHas('receipt.purchaseOrder', function ($q) use ($criteria): void {
            $q->whereBetween('created_at', [$criteria->startDate, $criteria->endDate]);
            if ($criteria->branchId) {
                $q->where('branch_id', $criteria->branchId);
            }
        });
        $totalSpend = (float) $spendQuery->sum(DB::raw('quantity_received * unit_price'));

        return [
            'active_items' => $activeItems,
            'low_stock_count' => $lowStockCount,
            'po_count' => $poCount,
            'requisition_count' => $reqCount,
            'transfer_count' => $transferCount,
            'total_spend' => $totalSpend,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildItemsByCategory(): array
    {
        $counts = InventoryItem::selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->all();

        $labels = [];
        $data = [];
        foreach (InventoryItemCategory::cases() as $case) {
            $label = $case->getLabel();
            $count = (int) ($counts[$case->value] ?? 0);
            if ($count > 0) {
                $labels[] = $label;
                $data[] = $count;
            }
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildTransactionTrend(InventoryReportCriteria $criteria): array
    {
        $rows = InventoryTransaction::selectRaw('DATE(created_at) as date, SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) as total_in, SUM(CASE WHEN delta < 0 THEN ABS(delta) ELSE 0 END) as total_out')
            ->whereBetween('created_at', [$criteria->startDate, $criteria->endDate])
            ->when($criteria->branchId, fn ($q) => $q->where('branch_id', $criteria->branchId))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        $labels = [];
        $inData = [];
        $outData = [];

        foreach ($rows as $row) {
            $labels[] = $row->date;
            $inData[] = (int) $row->total_in;
            $outData[] = (int) $row->total_out;
        }

        return ['labels' => $labels, 'in' => $inData, 'out' => $outData];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPoStatus(InventoryReportCriteria $criteria): array
    {
        $counts = PurchaseOrder::selectRaw('status, COUNT(*) as count')
            ->whereBetween('created_at', [$criteria->startDate, $criteria->endDate])
            ->when($criteria->branchId, fn ($q) => $q->where('branch_id', $criteria->branchId))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $labels = [];
        $data = [];
        foreach (PurchaseOrderStatus::cases() as $case) {
            $count = (int) ($counts[$case->value] ?? 0);
            if ($count > 0) {
                $labels[] = $case->getLabel();
                $data[] = $count;
            }
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRequisitionsByDept(InventoryReportCriteria $criteria): array
    {
        $rows = Requisition::selectRaw('COALESCE(department_id, \'unassigned\') as dept_id, COUNT(*) as count')
            ->whereBetween('created_at', [$criteria->startDate, $criteria->endDate])
            ->when($criteria->branchId, fn ($q) => $q->where('branch_id', $criteria->branchId))
            ->groupBy('department_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $deptIds = $rows->pluck('dept_id')->filter(fn ($id) => $id !== 'unassigned')->all();

        $deptNames = [];
        if ($deptIds !== []) {
            $deptNames = DB::table('departments')->whereIn('id', $deptIds)->pluck('name', 'id')->all();
        }

        $labels = [];
        $data = [];
        foreach ($rows as $row) {
            $label = $row->dept_id === 'unassigned' ? 'Unassigned' : ($deptNames[$row->dept_id] ?? $row->dept_id);
            $labels[] = $label;
            $data[] = (int) $row->count;
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildStockByLocation(InventoryReportCriteria $criteria): array
    {
        $query = StockBalance::selectRaw('location_type, SUM(quantity_on_hand) as total')
            ->when($criteria->branchId, fn ($q) => $q->where('branch_id', $criteria->branchId))
            ->groupBy('location_type');

        $counts = $query->pluck('total', 'location_type')->all();

        $labels = [];
        $data = [];
        foreach (StockLocationType::cases() as $case) {
            $label = $case->getLabel();
            $total = (int) ($counts[$case->value] ?? 0);
            if ($total > 0) {
                $labels[] = $label;
                $data[] = $total;
            }
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildLowStockItems(InventoryReportCriteria $criteria): array
    {
        $query = StockBalance::with(['inventoryItem', 'branch'])
            ->whereColumn('quantity_on_hand', '<=', DB::raw('COALESCE(reorder_point, 0)'))
            ->where('quantity_on_hand', '>', 0)
            ->orderByRaw('(quantity_on_hand * 1.0 / NULLIF(reorder_point, 0)) asc');

        if ($criteria->branchId) {
            $query->where('branch_id', $criteria->branchId);
        }

        return $query->limit(20)->get()->map(fn (StockBalance $sb) => [
            'item' => $sb->inventoryItem?->name ?? '-',
            'branch' => $sb->branch?->name ?? '-',
            'location' => $sb->location_type?->getLabel() ?? $sb->location_type,
            'quantity_on_hand' => $sb->quantity_on_hand,
            'reorder_point' => $sb->reorder_point ?? 0,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildRecentTransactions(InventoryReportCriteria $criteria): array
    {
        $query = InventoryTransaction::with(['inventoryItem', 'branch'])
            ->whereBetween('created_at', [$criteria->startDate, $criteria->endDate])
            ->orderByDesc('created_at')
            ->limit(20);

        if ($criteria->branchId) {
            $query->where('branch_id', $criteria->branchId);
        }

        return $query->get()->map(fn (InventoryTransaction $tx) => [
            'date' => $tx->created_at->format('Y-m-d H:i'),
            'item' => $tx->inventoryItem?->name ?? '-',
            'type' => $tx->transaction_type?->getLabel() ?? $tx->transaction_type,
            'delta' => $tx->delta,
            'balance' => $tx->quantity_after,
            'branch' => $tx->branch?->name ?? '-',
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildRecentPurchaseOrders(InventoryReportCriteria $criteria): array
    {
        $query = PurchaseOrder::with(['supplier', 'branch'])
            ->whereBetween('created_at', [$criteria->startDate, $criteria->endDate])
            ->orderByDesc('created_at')
            ->limit(20);

        if ($criteria->branchId) {
            $query->where('branch_id', $criteria->branchId);
        }

        return $query->get()->map(fn (PurchaseOrder $po) => [
            'po_number' => $po->po_number,
            'supplier' => $po->supplier?->name ?? '-',
            'branch' => $po->branch?->name ?? '-',
            'status' => $po->status?->getLabel() ?? $po->status,
            'ordered_at' => $po->ordered_at?->format('Y-m-d') ?? '-',
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toCsvRows(InventoryReportCriteria $criteria): array
    {
        $report = $this->buildFromCriteria($criteria);
        $rows = [];

        $labels = [
            'active_items' => 'Active items',
            'low_stock_count' => 'Low stock items',
            'po_count' => 'Purchase orders',
            'requisition_count' => 'Requisitions',
            'transfer_count' => 'Transfers',
            'total_spend' => 'Total spend',
        ];

        $rows[] = ['Section', 'Key', 'Value'];
        $rows[] = [];

        foreach ($report['summary'] as $key => $value) {
            $rows[] = ['Summary', $labels[$key] ?? $key, $value];
        }

        $rows[] = [];

        foreach ($report['low_stock_items'] as $item) {
            $rows[] = ['Low Stock', $item['item'], "{$item['branch']} / {$item['location']} — On hand: {$item['quantity_on_hand']}, Reorder: {$item['reorder_point']}"];
        }

        $rows[] = [];

        foreach ($report['recent_transactions'] as $tx) {
            $rows[] = ['Transaction', $tx['date'], "{$tx['item']} — {$tx['type']} ({$tx['delta']}) → {$tx['balance']} @ {$tx['branch']}"];
        }

        return $rows;
    }
}
