<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/theelincon_page_router.php';

theelincon_require_pageview(__DIR__, [
    'list' => 'purchase-request-list.php',
    'create' => 'purchase-request-create.php',
    'view' => 'purchase-request-view.php',
], 'list');
