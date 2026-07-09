<?php

namespace Modules\Inventory\Classes\Support;

use Modules\Core\Settings\FeatureSettings;

class Feature
{
    public static function pharmacyProcurementEnabled(): bool
    {
        return app(FeatureSettings::class)->inventory_pharmacy_procurement;
    }

    public static function wardRequisitionsEnabled(): bool
    {
        return app(FeatureSettings::class)->inventory_ward_requisitions;
    }

    public static function interBranchTransfersEnabled(): bool
    {
        return app(FeatureSettings::class)->inventory_inter_branch_transfers;
    }
}
