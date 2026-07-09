<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Classes\Services\Pdf\RequisitionVoucherPdfService;
use Modules\Inventory\Models\Requisition;

class RequisitionVoucherPdfController
{
    public function __invoke(Request $request, Requisition $requisition, RequisitionVoucherPdfService $pdfs): Response
    {
        $user = $request->user();
        $download = $request->boolean('download');

        if ($download) {
            abort_unless($user?->can('download_inventory_document'), 403);
        } else {
            abort_unless($user?->can('print_inventory_document'), 403);
        }

        $pdf = $pdfs->render($requisition);
        $disposition = $download ? 'attachment' : 'inline';
        $filename = $pdfs->filename($requisition);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }
}
