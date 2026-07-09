<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Stock Transfer Note') }} {{ $transfer->transfer_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
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
        'branchId' => $transfer->from_branch_id,
        'subtitle' => $transfer->fromBranch?->name,
    ])
    <h1>{{ __('Stock Transfer Note') }}</h1>

    <div class="section">
        <strong>{{ __('Transfer #') }}:</strong> {{ $transfer->transfer_number }}<br>
        <strong>{{ __('From') }}:</strong> {{ $transfer->fromBranch?->name ?? '-' }} ({{ $transfer->from_location_type }})<br>
        <strong>{{ __('To') }}:</strong> {{ $transfer->toBranch?->name ?? '-' }} ({{ $transfer->to_location_type }})<br>
        <strong>{{ __('Status') }}:</strong> {{ $transfer->status?->getLabel() ?? $transfer->status }}<br>
        @if ($transfer->shippedBy)
            <strong>{{ __('Shipped by') }}:</strong> {{ $transfer->shippedBy->name }} {{ $transfer->shipped_at ? 'at '.$transfer->shipped_at->format('Y-m-d H:i') : '' }}<br>
        @endif
        @if ($transfer->receivedBy)
            <strong>{{ __('Received by') }}:</strong> {{ $transfer->receivedBy->name }} {{ $transfer->received_at ? 'at '.$transfer->received_at->format('Y-m-d H:i') : '' }}<br>
        @endif
        @if ($transfer->notes)
            <strong>{{ __('Notes') }}:</strong> {{ $transfer->notes }}<br>
        @endif
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>{{ __('Item') }}</th>
                    <th class="right">{{ __('Qty Requested') }}</th>
                    <th class="right">{{ __('Qty Shipped') }}</th>
                    <th class="right">{{ __('Qty Received') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transfer->items as $item)
                    <tr>
                        <td>{{ $item->inventoryItem?->name ?? '-' }}</td>
                        <td class="right">{{ $item->quantity_requested }}</td>
                        <td class="right">{{ $item->quantity_shipped ?? '-' }}</td>
                        <td class="right">{{ $item->quantity_received ?: '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="center">{{ __('No items.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section muted">
        {{ __('Generated on') }} {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
