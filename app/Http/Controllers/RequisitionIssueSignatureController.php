<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Classes\Services\RequisitionIssueService;
use Modules\Inventory\Models\RequisitionIssue;

/**
 * Streams a collector's signature image from private storage.
 */
class RequisitionIssueSignatureController
{
    public function __invoke(Request $request, RequisitionIssue $issue, RequisitionIssueService $issues): Response
    {
        $user = $request->user();
        $requisition = $issue->requisition;

        abort_unless(
            $user !== null && (
                $user->can('view', $requisition)
                || (int) $requisition->requestor_id === (int) $user->getKey()
                || (int) $issue->collected_by === (int) $user->getKey()
            ),
            403,
        );

        abort_unless($issue->hasSignature() && $issues->signatureDisk()->exists($issue->collector_signature_path), 404);

        return response((string) $issues->signatureDisk()->get($issue->collector_signature_path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
