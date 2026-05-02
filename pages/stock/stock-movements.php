<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/connect_database.php';
header('Location: ' . app_path('pages/stock/stock-list.php'));
exit;
