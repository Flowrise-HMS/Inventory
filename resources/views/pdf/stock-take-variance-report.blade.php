<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Variance Report') }} {{ $stockTake->reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #6b7280; }
        .section { margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 5px 7px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; font-size: 10px; }
        .right { text-align: right; }
        .over { color: #1d4ed8; }
        .short { color: #b91c1c; }
    </style>
</head>
<body>
    @include('core::print.partials.pdf-brand-header', [
        'branchId' => $stockTake->branch_id,
        'subtitle' => $stockTake->branch?->name,
    ])
    <h1>{{ __('Stock-take Variance Report') }}</h1>

    <div class="section">
        <strong>{{ __('Reference') }}:</strong> {{ $stockTake->reference }}<br>
        <strong>{{ __('Store') }}:</strong> {{ $stockTake->store?->name }}<br>
        <strong>{{ __('Status') }}:</strong> {{ $stockTake->status->getLabel() }}<br>
        <strong>{{ __('Snapshot taken') }}:</strong> {{ $stockTake->started_at?->format('Y-m-d H:i') }} {{ $stockTake->startedBy ? '· '.$stockTake->startedBy->name : '' }}<br>
        @if ($stockTake->submitted_at)
            <strong>{{ __('Submitted') }}:</strong> {{ $stockTake->submitted_at->format('Y-m-d H:i') }} {{ $stockTake->submittedBy ? '· '.$stockTake->submittedBy->name : '' }}<br>
        @endif
        @if ($stockTake->approved_at)
            <strong>{{ __('Approved') }}:</strong> {{ $stockTake->approved_at->format('Y-m-d H:i') }} {{ $stockTake->approvedBy ? '· '.$stockTake->approvedBy->name : '' }}<br>
        @endif
        <strong>{{ __('Summary') }}:</strong>
        {{ __(':counted of :lines lines counted; :variance with a difference; :over unit(s) over, :short unit(s) short.', [
            'counted' => $summary['counted'],
            'lines' => $summary['lines'],
            'variance' => $summary['with_variance'],
            'over' => $summary['units_over'],
            'short' => $summary['units_short'],
        ]) }}
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>{{ __('Item') }}</th>
                    <th>{{ __('Lot') }}</th>
                    <th>{{ __('Expiry') }}</th>
                    <th class="right">{{ __('System when counted') }}</th>
                    <th class="right">{{ __('Counted') }}</th>
                    <th class="right">{{ __('Difference') }}</th>
                    <th>{{ __('Reason') }}</th>
                    <th>{{ __('Posted') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    @php($variance = $line->variance())
                    <tr>
                        <td>{{ $line->inventoryItem?->name }}@if ($line->is_found_item)<br><span class="muted">{{ __('Found during count') }}</span>@endif</td>
                        <td>{{ $line->lot_number ?? '-' }}</td>
                        <td>{{ $line->expiry_date?->format('Y-m-d') ?? '-' }}</td>
                        <td class="right">{{ $line->book_quantity }}</td>
                        <td class="right">{{ $line->counted_quantity }}</td>
                        <td class="right {{ $variance > 0 ? 'over' : 'short' }}">{{ $variance > 0 ? '+'.$variance : $variance }}</td>
                        <td>{{ $line->variance_reason ?? '-' }}</td>
                        <td>{{ $line->adjustmentTransaction ? $line->adjustmentTransaction->created_at?->format('Y-m-d H:i') : __('Not yet') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">{{ __('No differences: the count matched the system.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section muted">{{ __('Generated on') }} {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
