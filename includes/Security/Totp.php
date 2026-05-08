<?php

declare(strict_types=1);

namespace Theelincon\Security;

/**
 * TOTP (RFC 6238) เข้ากันได้กับ Google Authenticator — SHA-1, 30 วินาที, 6 หลัก
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** สร้างความลับแบบ Base32 (ขนาดเริ่มต้น ~80 บิต) */
    public static function generateSecret(int $entropyBytes = 10): string
    {
        return self::base32Encode(random_bytes(max(5, $entropyBytes)));
    }

    /**
     * otpauth URI สำหรับ QR — Key Uri Format ที่ Google Authenticator / Microsoft ใช้
     *
     * - encode ทั้งค่า label "issuer:account" ครั้งเดียว (ทั้งสตริงก่อน encode)
     * - issuer / account ที่ใช้ใน URI ควรเป็น ASCII — ช่องว่าง/อักขระพิเศษ/ภาษาไทยใน label
     *   ทำให้บางแอปบน iOS สแกนแล้วปฏิเสธ → ให้เรียก sanitizeProvisioningLabel() ก่อน
     */
    public static function provisioningUri(string $secret, string $accountLabel, string $issuer): string
    {
        $issuer = trim($issuer);
        $accountLabel = trim($accountLabel);
        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');

        $labelPlain = $issuer !== '' ? ($issuer . ':' . $accountLabel) : $accountLabel;
        $path = rawurlencode($labelPlain);

        // digits / period ระบุชัด — บางเวอร์ชันของ GA บน iOS คาดพารามิเตอร์ครบ
        $params = [
            'secret' => $secret,
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ];
        if ($issuer !== '') {
            $params['issuer'] = $issuer;
        }
        $q = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $path . '?' . $q;
    }

    /**
     * @return array{0:string,1:string} [issuer, account] สำหรับ otpauth — ASCII เท่านั้น (เข้ากันได้กับ GA บน iOS)
     */
    public static function provisioningLabelsForAuthenticator(string $issuer, string $accountLabel, string $fallbackAccount): array
    {
        $issuer = trim($issuer);
        $issuer = preg_replace('/\s+/u', '', $issuer) ?? '';
        $issuer = preg_replace('/[^a-zA-Z0-9._-]/', '', $issuer) ?? '';
        if ($issuer === '') {
            $issuer = 'THEELINCON';
        }

        $fb = trim($fallbackAccount);
        if ($fb === '') {
            $fb = 'user';
        }
        $accountLabel = trim($accountLabel);
        if ($accountLabel === '' || !preg_match('/^[\x20-\x7E]+$/u', $accountLabel)) {
            $accountLabel = $fb;
        }
        $accountLabel = preg_replace('/[^\x20-\x7E]/', '', $accountLabel) ?? '';
        $accountLabel = trim($accountLabel);
        if ($accountLabel === '') {
            $accountLabel = $fb;
        }

        return [$issuer, $accountLabel];
    }

    /** ตรวจรหัส 6 หลักกับความลับ Base32 */
    public static function verify(string $secretBase32, string $userCode, int $window = 2): bool
    {
        $secretBase32 = preg_replace('/\s+/', '', strtoupper(trim($secretBase32))) ?? '';
        if ($secretBase32 === '') {
            return false;
        }
        $secret = self::base32Decode($secretBase32);
        if ($secret === '') {
            return false;
        }
        $code = preg_replace('/\s+/', '', $userCode) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $t = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; ++$i) {
            $try = self::hotp($secret, $t + $i);
            if (hash_equals($try, $code)) {
                return true;
            }
        }

        return false;
    }

    private static function hotp(string $binarySecret, int $counter): string
    {
        $binCounter = pack('N*', 0, $counter);
        $hm = hash_hmac('sha1', $binCounter, $binarySecret, true);
        if ($hm === false || strlen($hm) < 20) {
            return str_repeat('0', self::DIGITS);
        }
        $offset = ord($hm[19]) & 0x0f;
        $trunc =
            ((ord($hm[$offset]) & 0x7f) << 24)
            | ((ord($hm[$offset + 1]) & 0xff) << 16)
            | ((ord($hm[$offset + 2]) & 0xff) << 8)
            | (ord($hm[$offset + 3]) & 0xff);
        $mod = 10 ** self::DIGITS;

        return str_pad((string) ($trunc % $mod), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): string
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVW234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        $l = strlen($b32);
        for ($i = 0; $i < $l; ++$i) {
            $v = strpos($map, $b32[$i]);
            if ($v === false) {
                return '';
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }

    private static function base32Encode(string $data): string
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVW234567';
        $bits = '';
        $l = strlen($data);
        for ($i = 0; $i < $l; ++$i) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= $map[bindec($chunk)];
        }

        return $out;
    }
}
