<?php

declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
session_start();
require_once THEELINCON_ROOT . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . app_path('index.php'));
    exit;
}
header('Location: ' . app_path('pages/supplier-list.php'));
exit;
