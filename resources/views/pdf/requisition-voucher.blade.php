<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Requisition Voucher') }} {{ $requisition->requisition_number }}</title>
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
        'branchId' => $requisition->branch_id,
        'subtitle' => $requisition->branch?->name,
    ])
    <h1>{{ __('Requisition Voucher') }}</h1>

    <div class="section">
        <strong>{{ __('Requisition #') }}:</strong> {{ $requisition->requisition_number }}<br>
        <strong>{{ __('Department') }}:</strong> {{ $requisition->department?->name ?? '-' }}<br>
        <strong>{{ __('Status') }}:</strong> {{ $requisition->status?->getLabel() ?? $requisition->status }}<br>
        <strong>{{ __('Requested') }}:</strong> {{ $requisition->created_at->format('Y-m-d H:i') }}<br>
        <strong>{{ __('Requestor') }}:</strong> {{ $requisition->requestor?->name ?? '-' }}<br>
        @if ($requisition->approvedBy)
            <strong>{{ __('Approved by') }}:</strong> {{ $requisition->approvedBy->name }} {{ $requisition->approved_at ? 'at '.$requisition->approved_at->format('Y-m-d H:i') : '' }}<br>
        @endif
        @if ($requisition->issuedBy)
            <strong>{{ __('Issued by') }}:</strong> {{ $requisition->issuedBy->name }} {{ $requisition->issued_at ? 'at '.$requisition->issued_at->format('Y-m-d H:i') : '' }}<br>
        @endif
        @if ($requisition->notes)
            <strong>{{ __('Notes') }}:</strong> {{ $requisition->notes }}<br>
        @endif
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>{{ __('Item') }}</th>
                    <th class="right">{{ __('Qty Requested') }}</th>
                    <th class="right">{{ __('Qty Approved') }}</th>
                    <th class="right">{{ __('Qty Issued') }}</th>
                    <th>{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requisition->items as $item)
                    <tr>
                        <td>{{ $item->inventoryItem?->name ?? '-' }}</td>
                        <td class="right">{{ $item->quantity_requested }}</td>
                        <td class="right">{{ $item->quantity_approved ?? '-' }}</td>
                        <td class="right">{{ $item->quantity_issued ?: '-' }}</td>
                        <td>{{ $item->notes ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="center">{{ __('No items.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section muted">
        {{ __('Generated on') }} {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
