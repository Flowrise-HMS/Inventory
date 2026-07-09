<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Classes\Services\Pdf\AdjustmentVoucherPdfService;
use Modules\Inventory\Models\InventoryTransaction;

class AdjustmentVoucherPdfController
{
    public function __invoke(Request $request, InventoryTransaction $transaction, AdjustmentVoucherPdfService $pdfs): Response
    {
        $user = $request->user();
        $download = $request->boolean('download');

        if ($download) {
            abort_unless($user?->can('download_inventory_document'), 403);
        } else {
            abort_unless($user?->can('print_inventory_document'), 403);
        }

        $pdf = $pdfs->render($transaction);
        $disposition = $download ? 'attachment' : 'inline';
        $filename = $pdfs->filename($transaction);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }
}
