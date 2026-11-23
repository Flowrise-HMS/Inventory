<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Classes\Services\Pdf\StockTakePdfService;
use Modules\Inventory\Enums\StockTakeStatus;
use Modules\Inventory\Models\StockTake;

class StockTakePdfController
{
    public function countSheet(Request $request, StockTake $stockTake, StockTakePdfService $pdfs): Response
    {
        $this->authorizePrint($request, $stockTake);

        return $this->respond($request, $pdfs->countSheet($stockTake), $pdfs->filename($stockTake, 'count-sheet'));
    }

    public function varianceReport(Request $request, StockTake $stockTake, StockTakePdfService $pdfs): Response
    {
        $this->authorizePrint($request, $stockTake);
        abort_unless(in_array($stockTake->status, [StockTakeStatus::Submitted, StockTakeStatus::Approved], true), 404);

        return $this->respond($request, $pdfs->varianceReport($stockTake), $pdfs->filename($stockTake, 'variance-report'));
    }

    protected function authorizePrint(Request $request, StockTake $stockTake): void
    {
        $user = $request->user();

        abort_unless($user !== null && $user->can('view', $stockTake), 403);
        abort_unless($user->can($request->boolean('download') ? 'download_inventory_document' : 'print_inventory_document'), 403);
    }

    protected function respond(Request $request, string $pdf, string $filename): Response
    {
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }
}
