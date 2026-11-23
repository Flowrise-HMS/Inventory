<?php

return [
    'features' => [
        'pharmacy_procurement' => env('INVENTORY_PHARMACY_PROCUREMENT', true),
        'ward_requisitions' => env('INVENTORY_WARD_REQUISITIONS', true),
        'inter_branch_transfers' => env('INVENTORY_INTER_BRANCH_TRANSFERS', true),
    ],
    'requisitions' => [
        // The person collecting items at the store counter must draw a signature.
        'require_collector_signature' => env('INVENTORY_REQUIRE_COLLECTOR_SIGNATURE', true),
        // Private disk for collector signature images.
        'signature_disk' => env('INVENTORY_SIGNATURE_DISK', 'local'),
    ],
    'alerts' => [
        // Notify a store's keepers the moment an item drops to or below its reorder point.
        'realtime_low_stock' => env('INVENTORY_REALTIME_LOW_STOCK_ALERTS', true),
        // Lots expiring within this many days are flagged on the dashboard and in the digest.
        'expiry_warning_days' => (int) env('INVENTORY_EXPIRY_WARNING_DAYS', 60),
    ],
    'document_prefixes' => [
        'requisition' => 'REQ',
        'purchase_order' => 'PO',
        'stock_transfer' => 'TRF',
    ],
    'permissions' => [
        'print_inventory_document' => 'Print Inventory Document',
        'download_inventory_document' => 'Download Inventory Document',
        'view_inventory_report' => 'View Inventory Report',
        'manage_all_stores' => 'Manage All Stores',
        'approve_stock_take' => 'Approve Stock Take',
    ],
];
