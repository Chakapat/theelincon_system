<?php
declare(strict_types=1);


require_once __DIR__ . '/_page_root.php';
require_once THEELINCON_ROOT . '/config/connect_database.php';

header('Location: ' . app_path('pages/employee-payslip-request-list.php'));
exit();
