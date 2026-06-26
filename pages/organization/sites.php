<?php

declare(strict_types=1);

/**
 * ย้ายไป Site Picker — redirect สำหรับ bookmark / ลิงก์เก่า
 */
session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

header('Location: ' . app_path('pages/sites/site-picker.php'), true, 302);
exit;
