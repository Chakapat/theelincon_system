<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/connect_database.php';
require_once dirname(__DIR__, 2) . '/includes/daily_site_report_projects.php';

$qs = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
$target = daily_site_report_hub_url() . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $target, true, 302);
exit;
