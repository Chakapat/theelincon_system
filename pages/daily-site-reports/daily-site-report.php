<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/theelincon_page_router.php';

theelincon_require_pageview(__DIR__, [
    'list' => 'daily-site-report-list.php',
    'form' => 'daily-site-report-form.php',
    'view' => 'daily-site-report-view.php',
    'calendar' => 'daily-site-report-calendar.php',
    'monthly' => 'daily-site-report-monthly-report.php',
], 'list');
