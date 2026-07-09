<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\DocumentSequence;

class DocumentNumberingService
{
    public function generate(string $prefix, string $branchId): string
    {
        $date = now()->toDateString();
        $key = ['prefix' => $prefix, 'branch_id' => $branchId, 'date' => $date];

        return DB::transaction(function () use ($prefix, $key) {
            $seq = DocumentSequence::query()
                ->lockForUpdate()
                ->firstOrNew($key);

            if (! $seq->exists) {
                $seq->fill($key);
            }

            $seq->save();

            $seq->increment('sequence');

            $seq->refresh();

            $datePart = now()->format('Ymd');

            return sprintf(
                '%s-%s-%04d',
                $prefix,
                $datePart,
                $seq->sequence
            );
        });
    }
}
