<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Count Sheet') }} {{ $stockTake->reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #6b7280; }
        .section { margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 5px 7px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; font-size: 10px; }
        .right { text-align: right; }
        .count { width: 70px; }
        .signatures td { border: none; padding-top: 28px; width: 33%; }
        .line { border-top: 1px solid #111827; padding-top: 3px; }
    </style>
</head>
<body>
    @include('core::print.partials.pdf-brand-header', [
        'branchId' => $stockTake->branch_id,
        'subtitle' => $stockTake->branch?->name,
    ])
    <h1>{{ __('Stock-take Count Sheet') }}</h1>

    <div class="section">
        <strong>{{ __('Reference') }}:</strong> {{ $stockTake->reference }}<br>
        <strong>{{ __('Store') }}:</strong> {{ $stockTake->store?->name }}<br>
        <strong>{{ __('Snapshot taken') }}:</strong> {{ $stockTake->started_at?->format('Y-m-d H:i') }} {{ $stockTake->startedBy ? '· '.$stockTake->startedBy->name : '' }}<br>
        @unless ($showSystem)
            <span class="muted">{{ __('Blind count: system quantities are not shown. Count everything on the shelf.') }}</span>
        @endunless
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('Item') }}</th>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Lot') }}</th>
                    <th>{{ __('Expiry') }}</th>
                    @if ($showSystem)
                        <th class="right">{{ __('System') }}</th>
                    @endif
                    <th class="count">{{ __('Counted') }}</th>
                    <th>{{ __('Remarks') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stockTake->lines as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $line->inventoryItem?->name }}</td>
                        <td>{{ $line->inventoryItem?->unit?->label ?? '-' }}</td>
                        <td>{{ $line->lot_number ?? '-' }}</td>
                        <td>{{ $line->expiry_date?->format('Y-m-d') ?? '-' }}</td>
                        @if ($showSystem)
                            <td class="right">{{ $line->system_quantity }}</td>
                        @endif
                        <td class="count">{{ $line->counted_quantity ?? '' }}</td>
                        <td></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showSystem ? 8 : 7 }}">{{ __('No stock lines at the snapshot.') }}</td></tr>
                @endforelse
                @for ($i = 0; $i < 5; $i++)
                    <tr><td>&nbsp;</td><td colspan="{{ $showSystem ? 7 : 6 }}" class="muted">{{ $i === 0 ? __('Items found that are not listed above:') : '' }}</td></tr>
                @endfor
            </tbody>
        </table>
    </div>

    <table class="signatures">
        <tr>
            <td><div class="line">{{ __('Counted by (name & signature)') }}</div></td>
            <td><div class="line">{{ __('Checked by') }}</div></td>
            <td><div class="line">{{ __('Date') }}</div></td>
        </tr>
    </table>

    <div class="section muted">{{ __('Generated on') }} {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
