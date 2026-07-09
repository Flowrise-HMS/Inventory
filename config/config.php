<?php

return [
    'features' => [
        'pharmacy_procurement' => env('INVENTORY_PHARMACY_PROCUREMENT', true),
        'ward_requisitions' => env('INVENTORY_WARD_REQUISITIONS', true),
        'inter_branch_transfers' => env('INVENTORY_INTER_BRANCH_TRANSFERS', true),
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
    ],
];
