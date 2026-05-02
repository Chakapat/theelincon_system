<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/theelincon_page_router.php';

theelincon_require_pageview(__DIR__, [
    'list' => 'quotation-list.php',
    'create' => 'quotation-create.php',
    'edit' => 'quotation-edit.php',
    'view' => 'quotation-view.php',
], 'list');
