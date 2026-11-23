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
        <strong>{{ __('Store') }}:</strong> {{ $requisition->store?->name ?? '-' }}<br>
        <strong>{{ __('Department') }}:</strong> {{ $requisition->department?->name ?? '-' }}@if ($requisition->location) ({{ $requisition->location->name }})@endif<br>
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
                    <th class="right">{{ __('Requested') }}</th>
                    <th class="right">{{ __('Approved') }}</th>
                    <th class="right">{{ __('Issued') }}</th>
                    <th class="right">{{ __('Closed short') }}</th>
                    <th class="right">{{ __('Still owed') }}</th>
                    <th class="right">{{ __('Received (ward)') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requisition->items as $item)
                    <tr>
                        <td>{{ $item->inventoryItem?->name ?? '-' }}@if ($item->notes)<br><span class="muted">{{ $item->notes }}</span>@endif</td>
                        <td class="right">{{ $item->quantity_requested }}</td>
                        <td class="right">{{ $item->quantity_approved ?? '-' }}</td>
                        <td class="right">{{ $item->quantity_issued ?: '-' }}</td>
                        <td class="right">{{ $item->quantity_closed ?: '-' }}</td>
                        <td class="right">{{ $item->outstandingQuantity() ?: '-' }}</td>
                        <td class="right">{{ $receivedByItem[$item->id] ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="center">{{ __('No items.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($handovers->isNotEmpty())
        <div class="section">
            <h2 style="font-size: 14px; margin-bottom: 4px;">{{ __('Hand-overs') }}</h2>

            @foreach ($handovers as $handover)
                @php($first = $handover['first'])
                <table style="page-break-inside: avoid;">
                    <thead>
                        <tr>
                            <th colspan="5">
                                {{ $first->issued_at?->format('Y-m-d H:i') }}
                                · {{ __('Issued by') }}: {{ $first->issuedBy?->name ?? '-' }}
                                · {{ __('Collected by') }}: {{ $handover['issues']->map(fn ($i) => $i->collectorName())->filter()->first() ?? '-' }}
                            </th>
                        </tr>
                        <tr>
                            <th>{{ __('Item') }}</th>
                            <th class="right">{{ __('Issued') }}</th>
                            <th>{{ __('Short issue') }}</th>
                            <th>{{ __('Ward receipt') }}</th>
                            <th class="center" style="width: 170px;">{{ __('Collector signature') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($handover['issues'] as $issue)
                            <tr>
                                <td>{{ $issue->inventoryItem?->name ?? '-' }}</td>
                                <td class="right">{{ $issue->quantity_issued }}</td>
                                <td>
                                    @if ($issue->short_issue_reason)
                                        {{ $issue->short_issue_reason->getLabel() }}@if ($issue->short_issue_note): {{ $issue->short_issue_note }}@endif
                                        <br><span class="muted">{{ $issue->quantity_closed > 0 ? __('Rest closed (:n)', ['n' => $issue->quantity_closed]) : __('Rest backordered') }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>
                                    {{ $issue->receipt_status?->getLabel() }}
                                    @if ($issue->quantity_received !== null && $issue->quantity_issued > 0)
                                        <br><span class="muted">{{ __('Received :r', ['r' => $issue->quantity_received]) }}@if ($issue->receiptConfirmedBy) · {{ $issue->receiptConfirmedBy->name }}@endif</span>
                                    @endif
                                    @if ($issue->discrepancy_note)
                                        <br><span class="muted">{{ $issue->discrepancy_note }}</span>
                                    @endif
                                </td>
                                @if ($loop->first)
                                    <td class="center" rowspan="{{ $handover['issues']->count() }}">
                                        @if ($handover['signature'])
                                            <img src="{{ $handover['signature'] }}" alt="{{ __('Collector signature') }}" style="max-width: 160px; max-height: 60px;">
                                        @else
                                            <span class="muted">{{ __('No signature') }}</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </div>
    @endif

    <div class="section muted">
        {{ __('Generated on') }} {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
