<?php

/**
 * 2FA: คุกกี้ "จำอุปกรณ์" แบบลงชื่อ HMAC (7 วัน)
 * ตั้งค่าโปรดักชัน: ตัวแปรสภาพแวดล้อม THEELINCON_2FA_TRUST_SECRET (อย่างน้อย 32 ตัวอักษร)
 */
declare(strict_types=1);

require_once __DIR__ . '/foundation.php';
require_once dirname(__DIR__) . '/includes/Security/Totp.php';

const THEELINCON_2FA_TRUST_COOKIE = 'theelin_2fa_trust';
const THEELINCON_2FA_TRUST_MAX_AGE = 7 * 24 * 60 * 60;

function theelincon_cookie_path(): string
{
    $b = BASE_URL;
    if ($b === '' || $b === '/') {
        return '/';
    }

    return rtrim($b, '/') . '/';
}

function theelincon_is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    return false;
}

/** คีย์ HMAC สำหรับคุกกี้ความไว้วางใจ — ต้องไม่เปลี่ยนบ่อยเพราะจะทำให้ผู้ใช้ต้องยืนยันใหม่หมด */
function theelincon_2fa_trust_secret(): string
{
    $e = getenv('THEELINCON_2FA_TRUST_SECRET');
    if (is_string($e) && strlen($e) >= 32) {
        return $e;
    }

    return hash('sha256', 'THEELINCON_2FA_TRUST_FALLBACK_v1|' . ROOT_PATH, true);
}

function theelincon_2fa_trust_payload_verify(string $userId, string $cookieValue): bool
{
    $userId = trim($userId);
    if ($userId === '' || $cookieValue === '') {
        return false;
    }
    $parts = explode('.', $cookieValue, 2);
    if (count($parts) !== 2) {
        return false;
    }
    [$b64Payload, $sigHex] = $parts;
    $raw = base64_decode(strtr($b64Payload, '-_', '+/'), true);
    if ($raw === false || $raw === '') {
        return false;
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return false;
    }
    $uid = isset($payload['uid']) ? (string) $payload['uid'] : '';
    $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
    if ($uid === '' || $uid !== $userId) {
        return false;
    }
    if ($exp < time()) {
        return false;
    }
    $expect = hash_hmac('sha256', $raw, theelincon_2fa_trust_secret(), true);
    $sigBin = @hex2bin($sigHex);
    if ($sigBin === false || !hash_equals($expect, $sigBin)) {
        return false;
    }

    return true;
}

/** ตรวจคุกกี้ความไว้วางใจสำหรับ user id (สตริงคีย์ RTDB) */
function theelincon_2fa_trust_cookie_valid_for(string $userId): bool
{
    $v = $_COOKIE[THEELINCON_2FA_TRUST_COOKIE] ?? '';
    if (!is_string($v) || $v === '') {
        return false;
    }

    return theelincon_2fa_trust_payload_verify($userId, $v);
}

/** ออกคุกกี้ความไว้วางใจ 7 วัน (หลังผ่าน TOTP แล้ว) */
function theelincon_2fa_trust_cookie_issue(string $userId): void
{
    $userId = trim($userId);
    if ($userId === '') {
        return;
    }
    $exp = time() + THEELINCON_2FA_TRUST_MAX_AGE;
    $payloadArr = ['uid' => $userId, 'exp' => $exp];
    $raw = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
    if ($raw === false) {
        return;
    }
    $sig = hash_hmac('sha256', $raw, theelincon_2fa_trust_secret(), true);
    $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') . '.' . bin2hex($sig);
    $opt = [
        'expires' => $exp,
        'path' => theelincon_cookie_path(),
        'secure' => theelincon_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie(THEELINCON_2FA_TRUST_COOKIE, $token, $opt);
    } else {
        setcookie(THEELINCON_2FA_TRUST_COOKIE, $token, $exp, theelincon_cookie_path() . '; samesite=Lax', '', theelincon_is_https_request(), true);
    }
}

function theelincon_2fa_trust_cookie_clear(): void
{
    $past = time() - 3600;
    $opt = [
        'expires' => $past,
        'path' => theelincon_cookie_path(),
        'secure' => theelincon_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie(THEELINCON_2FA_TRUST_COOKIE, '', $opt);
    } else {
        setcookie(THEELINCON_2FA_TRUST_COOKIE, '', $past, theelincon_cookie_path() . '; samesite=Lax', '', theelincon_is_https_request(), true);
    }
}
