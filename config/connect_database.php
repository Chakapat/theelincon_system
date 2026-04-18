<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/firebase_settings.php';
require_once __DIR__ . '/firebase_service_account.php';

/**
 * ฐานข้อมูลหลัก: Firebase Realtime Database (mirror theelincon_mirror/)
 * อ่าน/เขียนผ่าน Theelincon\Rtdb\Db
 */
if (!defined('THEELINCON_FIREBASE_PRIMARY')) {
    define('THEELINCON_FIREBASE_PRIMARY', true);
}

// ย้าย session เก่า: users.position → users.role (หลัง deploy ยังล็อกอินอยู่)
if (session_status() === PHP_SESSION_ACTIVE) {
    if (isset($_SESSION['position']) && !isset($_SESSION['role'])) {
        $_SESSION['role'] = $_SESSION['position'];
    }
    unset($_SESSION['position']);
}
