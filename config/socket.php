<?php

/**
 * Socket.IO (Node) — ใช้ร่วมกับ actions/socket-token.php และ server/socket-server.js
 * ตั้งค่า SOCKET_IO_SECRET / SOCKET_IO_PUBLIC_URL ในเซิร์ฟเวอร์ (หรือ getenv) สำหรับ production
 */

if (!function_exists('socket_io_secret')) {
    function socket_io_secret(): string
    {
        $s = getenv('SOCKET_IO_SECRET');
        if ($s !== false && $s !== '') {
            return $s;
        }
        return 'theelincon_dev_socket_CHANGE_ME';
    }
}

if (!function_exists('socket_io_public_url')) {
    /**
     * URL ที่เบราว์เซอร์ใช้ต่อ Socket.IO (เช่น http://localhost:3001)
     */
    function socket_io_public_url(): string
    {
        $e = getenv('SOCKET_IO_PUBLIC_URL');
        if ($e !== false && $e !== '') {
            return rtrim($e, '/');
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/:\d+$/', '', (string) $host);
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . $host . ':3001';
    }
}
