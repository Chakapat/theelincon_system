<?php

declare(strict_types=1);

/**
 * Audit trail + password verification for destructive actions.
 *
 * บันทึก detail_json: source, action, before, after, nested[], meta
 */

use Theelincon\Rtdb\Db;

require_once __DIR__ . '/../config/foundation.php';
require_once __DIR__ . '/tnc_action_response.php';

/** ฟิลด์ที่ไม่บันทึกใน snapshot */
if (!function_exists('tnc_audit_sensitive_keys')) {
    /**
     * @return list<string>
     */
    function tnc_audit_sensitive_keys(): array
    {
        return ['password', 'password_hash', 'line_approval_token', 'csrf', '_csrf'];
    }
}

if (!function_exists('tnc_audit_sanitize_row')) {
    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    function tnc_audit_sanitize_row(?array $row, int $maxString = 1200): ?array
    {
        if ($row === null) {
            return null;
        }
        $skip = array_map('strtolower', tnc_audit_sensitive_keys());
        $out = [];
        foreach ($row as $k => $v) {
            $kl = strtolower((string) $k);
            if (in_array($kl, $skip, true)) {
                continue;
            }
            if (is_string($v)) {
                if (strlen($v) > $maxString) {
                    $v = substr($v, 0, $maxString) . '…';
                }
            } elseif (is_array($v)) {
                $enc = json_encode($v, JSON_UNESCAPED_UNICODE);
                if ($enc !== false && strlen($enc) > $maxString) {
                    $v = mb_substr($enc, 0, $maxString, 'UTF-8') . '…';
                }
            }
            $out[(string) $k] = $v;
        }

        return $out;
    }
}

if (!function_exists('tnc_audit_sanitize_nested_list')) {
    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    function tnc_audit_sanitize_nested_list(array $list, int $maxItems = 80): array
    {
        $out = [];
        $n = 0;
        foreach ($list as $item) {
            if ($n >= $maxItems) {
                $out[] = ['_truncated' => true, '_more' => count($list) - $maxItems];
                break;
            }
            if (isset($item['snapshot']) && is_array($item['snapshot'])) {
                $item['snapshot'] = tnc_audit_sanitize_row($item['snapshot']);
            }
            $out[] = $item;
            ++$n;
        }

        return $out;
    }
}

if (!function_exists('tnc_audit_sanitize_deep')) {
    /**
     * @param mixed $v
     * @return mixed
     */
    function tnc_audit_sanitize_deep($v, int $depth = 5, int $maxStr = 900, int $maxList = 100)
    {
        if ($depth <= 0) {
            return '…';
        }
        if (is_array($v)) {
            $i = 0;
            $out = [];
            foreach ($v as $k => $x) {
                if ($i >= $maxList) {
                    $out['_truncated_list'] = true;
                    break;
                }
                $ks = is_string($k) ? $k : (string) $k;
                if (in_array(strtolower($ks), array_map('strtolower', tnc_audit_sensitive_keys()), true)) {
                    continue;
                }
                $out[$ks] = tnc_audit_sanitize_deep($x, $depth - 1, $maxStr, $maxList);
                ++$i;
            }

            return $out;
        }
        if (is_string($v) && strlen($v) > $maxStr) {
            return substr($v, 0, $maxStr) . '…';
        }

        return $v;
    }
}

if (!function_exists('tnc_audit_encode_detail')) {
    function tnc_audit_encode_detail(array $detail, int $maxBytes = 15500): string
    {
        if (isset($detail['nested']) && is_array($detail['nested'])) {
            $detail['nested'] = tnc_audit_sanitize_nested_list($detail['nested']);
        }
        if (isset($detail['before']) && is_array($detail['before'])) {
            $detail['before'] = tnc_audit_sanitize_row($detail['before']);
        }
        if (isset($detail['after']) && is_array($detail['after'])) {
            $detail['after'] = tnc_audit_sanitize_row($detail['after']);
        }
        if (isset($detail['meta']) && is_array($detail['meta'])) {
            $detail['meta'] = tnc_audit_sanitize_deep($detail['meta']);
        }
        $json = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '{"error":"json_encode_failed"}';
        }
        if (strlen($json) <= $maxBytes) {
            return $json;
        }
        $detail['_truncated'] = true;
        $detail['_original_bytes'] = strlen($json);
        if (isset($detail['after']) && is_array($detail['after'])) {
            $detail['after'] = ['_omitted' => true, 'keys' => array_keys($detail['after'])];
        }
        if (isset($detail['before']) && is_array($detail['before'])) {
            $detail['before'] = ['_omitted' => true, 'keys' => array_keys($detail['before'])];
        }
        $json2 = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json2 !== false ? (strlen($json2) > $maxBytes ? substr($json2, 0, $maxBytes) . '…' : $json2) : '{}';
    }
}

if (!function_exists('tnc_verify_user_password_row')) {
    function tnc_verify_user_password_row(?array $user, string $plain): bool
    {
        if ($user === null || $plain === '') {
            return false;
        }
        $stored = (string) ($user['password'] ?? '');
        if ($stored !== '' && password_verify($plain, $stored)) {
            return true;
        }

        return strlen($stored) === 32 && ctype_xdigit($stored) && hash_equals($stored, md5($plain));
    }
}

if (!function_exists('tnc_audit_actor_display')) {
    function tnc_audit_actor_display(): string
    {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $name = trim((string) ($_SESSION['name'] ?? ''));
        if ($name === '') {
            return $uid > 0 ? ('#' . $uid) : '-';
        }

        return $name . ($uid > 0 ? ' #' . $uid : '');
    }
}

if (!function_exists('tnc_audit_normalize_detail_arg')) {
    /**
     * รองรับพารามิเตอร์เดิม: array แบบ meta เล็กๆ ไม่มีคีย์สงวน → ห่อเป็น meta
     *
     * @return array<string, mixed>
     */
    function tnc_audit_normalize_detail_arg(?array $detail): array
    {
        if ($detail === null || count($detail) === 0) {
            return [];
        }
        $reserved = ['before', 'after', 'nested', 'source', 'action', 'meta'];
        foreach ($reserved as $k) {
            if (array_key_exists($k, $detail)) {
                return $detail;
            }
        }

        return ['meta' => $detail];
    }
}

if (!function_exists('tnc_audit_log')) {
    /**
     * @param array<string, mixed>|null $detail source, action, before, after, nested[], meta หรือ meta แบบเดิม (flat)
     */
    function tnc_audit_log(string $verb, string $entityType, string $entityId, string $summary = '', ?array $detail = null): void
    {
        $verb = trim($verb);
        $entityType = trim($entityType);
        if ($verb === '' || $entityType === '') {
            return;
        }
        $entityId = trim($entityId);
        $summary = mb_substr(trim($summary), 0, 500, 'UTF-8');
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $userName = trim((string) ($_SESSION['name'] ?? ''));

        $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $norm = tnc_audit_normalize_detail_arg($detail);
        $detailJson = count($norm) > 0 ? tnc_audit_encode_detail($norm) : '';

        $row = [
            'id' => 0,
            'created_at' => $createdAt,
            'user_id' => $uid,
            'user_name' => $userName !== '' ? $userName : (string) $uid,
            'verb' => $verb,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => $summary,
            'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64, 'UTF-8'),
            'request_uri' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500, 'UTF-8'),
            'detail_json' => $detailJson,
        ];

        try {
            $nid = Db::nextNumericId('audit_logs', 'id');
            $row['id'] = $nid;
            Db::setRow('audit_logs', (string) $nid, $row);
        } catch (Throwable $e) {
            // avoid blocking primary operation if audit path fails
        }
    }
}

if (!function_exists('tnc_redirect_back_or_home_with_query')) {
    /**
     * @param array<string, scalar> $query
     *
     * @return never
     */
    function tnc_redirect_back_or_home_with_query(array $query): void
    {
        $ref = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
        if ($ref !== '') {
            $refParts = parse_url($ref);
            $hostNeed = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
            $hostRef = isset($refParts['host']) ? strtolower((string) $refParts['host']) : '';
            if ($hostRef !== '' && $hostNeed !== '' && $hostRef === $hostNeed) {
                $path = isset($refParts['path']) ? (string) $refParts['path'] : '/';
                $q = [];
                if (!empty($refParts['query'])) {
                    parse_str((string) $refParts['query'], $q);
                }
                foreach ($query as $k => $v) {
                    $q[(string) $k] = $v;
                }
                $url = $path . '?' . http_build_query($q);

                tnc_action_redirect($url);
                exit;
            }
        }

        tnc_action_redirect(app_path('index.php') . '?' . http_build_query($query));
        exit;
    }
}

if (!function_exists('tnc_require_post_confirm_password')) {
    /**
     * Requires POST + confirm_password matching logged-in user's password.
     *
     * @return never on failure
     */
    function tnc_require_post_confirm_password(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            if (tnc_ajax_form_requested()) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'การลบต้องส่งแบบ POST และใส่รหัสผ่านยืนยัน'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Content-Type: text/html; charset=UTF-8');
            http_response_code(405);
            echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ไม่รองรับ</title></head><body style="font-family:sarabun,sans-serif;padding:24px;"><p>การลบต้องดำเนินการผ่านปุ่มในระบบและใส่<strong>รหัสผ่านของคุณ</strong>เพื่อยืนยัน</p><p><a href="javascript:history.back()">กลับ</a></p></body></html>';
            exit;
        }

        $pw = (string) ($_POST['confirm_password'] ?? '');
        if (trim($pw) === '') {
            if (tnc_ajax_form_requested()) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => tnc_ajax_error_message('confirm_password_required')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            tnc_redirect_back_or_home_with_query(['error' => 'confirm_password_required']);
        }

        $uid = (string) ($_SESSION['user_id'] ?? '');
        $user = $uid !== '' ? Db::row('users', $uid) : null;
        if (!tnc_verify_user_password_row($user, $pw)) {
            if (tnc_ajax_form_requested()) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => tnc_ajax_error_message('confirm_password_invalid')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            tnc_redirect_back_or_home_with_query(['error' => 'confirm_password_invalid']);
        }
    }
}
