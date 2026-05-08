<?php

declare(strict_types=1);


session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}
if (!isset($_SESSION['role']) || !in_array((string) $_SESSION['role'], ['admin', 'Accounting'], true)) {
    header('Location: ' . app_path('index.php'));
    exit;
}
header('Location: ' . app_path('pages/organization/sites.php'));
exit;
