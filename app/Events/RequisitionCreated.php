<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\Requisition;

class RequisitionCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Requisition $requisition,
    ) {}
}
