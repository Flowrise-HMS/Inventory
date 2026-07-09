<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Goods Received Note') }} {{ $purchaseOrder->po_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 0; }
        .muted { color: #6b7280; }
        .section { margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; font-size: 11px; }
        .right { text-align: right; }
        .center { text-align: center; }
    </style>
</head>
<body>
    @include('core::print.partials.pdf-brand-header', [
        'branchId' => $purchaseOrder->branch_id,
        'subtitle' => $purchaseOrder->branch?->name,
    ])
    <h1>{{ __('Goods Received Note') }}</h1>

    <div class="section">
        <strong>{{ __('PO #') }}:</strong> {{ $purchaseOrder->po_number }}<br>
        <strong>{{ __('Supplier') }}:</strong> {{ $purchaseOrder->supplier?->name ?? '-' }}<br>
        <strong>{{ __('Ordered') }}:</strong> {{ $purchaseOrder->ordered_at?->format('Y-m-d') ?? '-' }}<br>
    </div>

    @forelse ($purchaseOrder->receipts as $receipt)
        <div class="section">
            <h2>{{ __('Receipt') }} {{ $loop->iteration }}</h2>
            <strong>{{ __('Received') }}:</strong> {{ $receipt->received_at?->format('Y-m-d H:i') ?? '-' }}<br>
            <strong>{{ __('Received by') }}:</strong> {{ $receipt->receivedByUser?->name ?? '-' }}<br>
            @if ($receipt->notes)
                <strong>{{ __('Notes') }}:</strong> {{ $receipt->notes }}<br>
            @endif

            <table>
                <thead>
                    <tr>
                        <th>{{ __('Item') }}</th>
                        <th class="right">{{ __('Qty Ordered') }}</th>
                        <th class="right">{{ __('Qty Received') }}</th>
                        <th>{{ __('Lot #') }}</th>
                        <th>{{ __('Expiry') }}</th>
                        <th class="right">{{ __('Unit Price') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receipt->items as $receiptItem)
                        <tr>
                            <td>{{ $receiptItem->purchaseOrderItem?->inventoryItem?->name ?? '-' }}</td>
                            <td class="right">{{ $receiptItem->purchaseOrderItem?->quantity_ordered ?? 0 }}</td>
                            <td class="right">{{ $receiptItem->quantity_received }}</td>
                            <td>{{ $receiptItem->lot_number ?? '-' }}</td>
                            <td>{{ $receiptItem->expiry_date?->format('Y-m-d') ?? '-' }}</td>
                            <td class="right">{{ $receiptItem->unit_price ? number_format((float) $receiptItem->unit_price, 2) : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="center">{{ __('No items.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @empty
        <div class="section muted">{{ __('No receipts recorded.') }}</div>
    @endforelse

    <div class="section muted">
        {{ __('Generated on') }} {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
