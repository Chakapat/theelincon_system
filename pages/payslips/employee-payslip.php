<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/theelincon_page_router.php';

theelincon_require_pageview(__DIR__, [
    'form' => 'employee-payslip-form.php',
    'my' => 'employee-payslip-my.php',
    'payslip' => 'employee-payslip-view.php',
    'requests' => 'employee-payslip-request-list.php',
], 'form');
