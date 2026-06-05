<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/theelincon_page_router.php';

theelincon_require_pageview(__DIR__, [
    'list' => 'purchase-order-list.php',
    'wo_list' => 'work-order-list.php',
    'create' => 'purchase-order-create.php',
    'create_direct' => 'purchase-order-create-direct.php',
    'edit' => 'purchase-order-edit.php',
    'view' => 'purchase-order-view.php',
    'from_pr' => 'purchase-order-from-pr.php',
], 'list');
