<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Stock Adjustment Voucher') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #6b7280; }
        .section { margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; font-size: 11px; }
        .right { text-align: right; }
    </style>
</head>
<body>
    @include('core::print.partials.pdf-brand-header', [
        'branchId' => $transaction->branch_id,
        'subtitle' => $transaction->branch?->name,
    ])
    <h1>{{ __('Stock Adjustment Voucher') }}</h1>

    <div class="section">
        <strong>{{ __('Transaction ID') }}:</strong> {{ $transaction->id }}<br>
        <strong>{{ __('Item') }}:</strong> {{ $transaction->inventoryItem?->name ?? '-' }}<br>
        <strong>{{ __('SKU') }}:</strong> {{ $transaction->inventoryItem?->sku ?? '-' }}<br>
        <strong>{{ __('Unit') }}:</strong> {{ $transaction->inventoryItem?->unit?->name ?? $transaction->unit_label_snapshot ?? '-' }}<br>
        <strong>{{ __('Date') }}:</strong> {{ $transaction->created_at->format('Y-m-d H:i') }}<br>
        <strong>{{ __('Performed by') }}:</strong> {{ $transaction->performed_by ?? '-' }}<br>
        @if ($transaction->reference_type && $transaction->reference_id)
            <strong>{{ __('Reference') }}:</strong> {{ class_basename($transaction->reference_type) }} #{{ $transaction->reference_id }}<br>
        @endif
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>{{ __('Field') }}</th>
                    <th>{{ __('Value') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ __('Delta') }}</td>
                    <td>{{ $transaction->delta > 0 ? '+' : '' }}{{ $transaction->delta }}</td>
                </tr>
                <tr>
                    <td>{{ __('Quantity After') }}</td>
                    <td>{{ $transaction->quantity_after }}</td>
                </tr>
                <tr>
                    <td>{{ __('Location') }}</td>
                    <td>{{ $transaction->to_location_type ?? $transaction->from_location_type ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section muted">
        {{ __('Generated on') }} {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
