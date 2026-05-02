<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/theelincon_page_router.php';

theelincon_require_pageview(__DIR__, [
    'list' => 'purchase-need-list.php',
    'create' => 'purchase-need-create.php',
    'view' => 'purchase-need-view.php',
], 'list');
