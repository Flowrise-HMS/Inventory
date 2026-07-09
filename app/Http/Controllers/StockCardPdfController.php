<?php

namespace Modules\Inventory\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Classes\Services\Pdf\StockCardPdfService;
use Modules\Inventory\Models\InventoryItem;

class StockCardPdfController
{
    public function __invoke(Request $request, InventoryItem $item, StockCardPdfService $pdfs): Response
    {
        $user = $request->user();
        $download = $request->boolean('download');

        if ($download) {
            abort_unless($user?->can('download_inventory_document'), 403);
        } else {
            abort_unless($user?->can('print_inventory_document'), 403);
        }

        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : null;
        $branchId = $request->input('branch_id');

        $pdf = $pdfs->render($item, $branchId, $from, $to);
        $disposition = $download ? 'attachment' : 'inline';
        $filename = $pdfs->filename($item);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }
}
