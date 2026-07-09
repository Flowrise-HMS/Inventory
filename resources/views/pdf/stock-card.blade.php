<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Stock Card') }} - {{ $item->name }}</title>
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
    @include('core::print.partials.pdf-brand-header')
    <h1>{{ __('Stock Card') }}</h1>

    <div class="section">
        <strong>{{ __('Item') }}:</strong> {{ $item->name }}<br>
        <strong>{{ __('SKU') }}:</strong> {{ $item->sku ?? '-' }}<br>
        <strong>{{ __('Category') }}:</strong> {{ $item->category?->getLabel() ?? $item->category }}<br>
        <strong>{{ __('Unit') }}:</strong> {{ $item->unit?->name ?? '-' }}<br>
        @if ($branchId)
            <strong>{{ __('Branch') }}:</strong> {{ $branchId }}<br>
        @endif
        @if ($from)
            <strong>{{ __('From') }}:</strong> {{ $from->format('Y-m-d') }}<br>
        @endif
        @if ($to)
            <strong>{{ __('To') }}:</strong> {{ $to->format('Y-m-d') }}<br>
        @endif
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Reference') }}</th>
                    <th class="right">{{ __('In') }}</th>
                    <th class="right">{{ __('Out') }}</th>
                    <th class="right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $tx)
                    <tr>
                        <td>{{ $tx->created_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $tx->transaction_type?->getLabel() ?? $tx->transaction_type }}</td>
                        <td>{{ $tx->reference ? class_basename($tx->reference_type).' #'.($tx->reference_id ?? '') : '-' }}</td>
                        <td class="right">{{ $tx->delta > 0 ? $tx->delta : '-' }}</td>
                        <td class="right">{{ $tx->delta < 0 ? abs($tx->delta) : '-' }}</td>
                        <td class="right">{{ $tx->quantity_after }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="center">{{ __('No transactions found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section muted">
        {{ __('Generated on') }} {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
