<?php

declare(strict_types=1);

/**
 * ระบบแจ้งเตือนบนเว็บ (web notifications)
 * เก็บใน Firebase RTDB ตาราง `web_notifications` แยกตามผู้รับ (user_id)
 *
 * โครงสร้างแต่ละแถว:
 *   id, user_id, type, title, message, link,
 *   entity_type, entity_id, is_read (0/1), read_at, created_at, created_ts
 */

use Theelincon\Rtdb\Db;

if (!function_exists('tnc_notif_table')) {
    function tnc_notif_table(): string
    {
        return 'web_notifications';
    }
}

if (!function_exists('tnc_notif_create_for_users')) {
    /**
     * สร้างการแจ้งเตือน 1 รายการต่อผู้รับแต่ละคน
     *
     * @param list<int|string> $userIds
     * @param array<string, mixed> $base ฟิลด์ร่วม (type, title, message, link, entity_type, entity_id)
     */
    function tnc_notif_create_for_users(array $userIds, array $base): int
    {
        $clean = [];
        foreach ($userIds as $u) {
            $uid = (int) $u;
            if ($uid > 0 && !in_array($uid, $clean, true)) {
                $clean[] = $uid;
            }
        }
        if ($clean === []) {
            return 0;
        }

        $nextId = Db::nextNumericId(tnc_notif_table(), 'id');
        $now = date('Y-m-d H:i:s');
        $ts = time();
        $created = 0;

        foreach ($clean as $uid) {
            $id = $nextId++;
            $row = array_merge([
                'type' => '',
                'title' => '',
                'message' => '',
                'link' => '',
                'entity_type' => '',
                'entity_id' => 0,
            ], $base, [
                'id' => $id,
                'user_id' => $uid,
                'is_read' => 0,
                'read_at' => '',
                'created_at' => $now,
                'created_ts' => $ts,
            ]);
            Db::setRow(tnc_notif_table(), (string) $id, $row);
            $created++;
        }

        return $created;
    }
}

if (!function_exists('tnc_notif_list_for_user')) {
    /**
     * รายการแจ้งเตือนล่าสุดของผู้ใช้ (ใหม่สุดอยู่บน)
     *
     * @return list<array<string, mixed>>
     */
    function tnc_notif_list_for_user(int $userId, int $limit = 20): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = Db::filter(tnc_notif_table(), static function (array $r) use ($userId): bool {
            return (int) ($r['user_id'] ?? 0) === $userId;
        });
        usort($rows, static function (array $a, array $b): int {
            $ta = (int) ($a['created_ts'] ?? 0);
            $tb = (int) ($b['created_ts'] ?? 0);
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }
            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'type' => (string) ($r['type'] ?? ''),
                'title' => (string) ($r['title'] ?? ''),
                'message' => (string) ($r['message'] ?? ''),
                'link' => (string) ($r['link'] ?? ''),
                'is_read' => (int) ($r['is_read'] ?? 0) === 1 ? 1 : 0,
                'created_at' => (string) ($r['created_at'] ?? ''),
                'ago' => tnc_notif_time_ago((string) ($r['created_at'] ?? '')),
            ];
        }

        return $out;
    }
}

if (!function_exists('tnc_notif_unread_count')) {
    function tnc_notif_unread_count(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $n = 0;
        foreach (Db::tableRows(tnc_notif_table()) as $r) {
            if ((int) ($r['user_id'] ?? 0) === $userId && (int) ($r['is_read'] ?? 0) !== 1) {
                $n++;
            }
        }

        return $n;
    }
}

if (!function_exists('tnc_notif_mark_read')) {
    function tnc_notif_mark_read(int $userId, int $notifId): bool
    {
        if ($userId <= 0 || $notifId <= 0) {
            return false;
        }
        $pk = Db::pkForLogicalId(tnc_notif_table(), $notifId);
        $row = Db::row(tnc_notif_table(), $pk);
        if ($row === null || (int) ($row['user_id'] ?? 0) !== $userId) {
            return false;
        }
        Db::mergeRow(tnc_notif_table(), $pk, [
            'is_read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }
}

if (!function_exists('tnc_notif_mark_all_read')) {
    function tnc_notif_mark_all_read(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $n = 0;
        $now = date('Y-m-d H:i:s');
        foreach (Db::tableKeyed(tnc_notif_table()) as $pk => $r) {
            if ((int) ($r['user_id'] ?? 0) === $userId && (int) ($r['is_read'] ?? 0) !== 1) {
                Db::mergeRow(tnc_notif_table(), (string) $pk, ['is_read' => 1, 'read_at' => $now]);
                $n++;
            }
        }

        return $n;
    }
}

if (!function_exists('tnc_notif_time_ago')) {
    function tnc_notif_time_ago(string $datetime): string
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return 'เมื่อสักครู่';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . ' นาทีที่แล้ว';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
        }
        if ($diff < 604800) {
            return (int) floor($diff / 86400) . ' วันที่แล้ว';
        }

        return date('d/m/Y H:i', $ts);
    }
}

if (!function_exists('tnc_pr_decision_notify')) {
    /**
     * สร้างการแจ้งเตือนถึงเว็บเมื่อ PR ถูกอนุมัติ/ไม่อนุมัติ
     * ผู้รับ: ผู้บันทึก (created_by) และผู้ขอ (requested_by) ยกเว้นผู้ตัดสินเอง
     *
     * @param array<string, mixed> $prAfter ข้อมูล PR หลังบันทึกผล
     */
    function tnc_pr_decision_notify(int $prId, string $decision, array $prAfter): void
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return;
        }
        $approved = $decision === 'approve';
        $prNo = trim((string) ($prAfter['pr_number'] ?? ''));
        if ($prNo === '') {
            $prNo = 'PR #' . $prId;
        }

        $decider = (int) ($prAfter['line_decided_by_user_id'] ?? 0);
        $recipients = [];
        foreach ([(int) ($prAfter['created_by'] ?? 0), (int) ($prAfter['requested_by'] ?? 0)] as $u) {
            if ($u > 0 && $u !== $decider) {
                $recipients[] = $u;
            }
        }
        if ($recipients === []) {
            return;
        }

        $link = function_exists('app_path')
            ? app_path('pages/purchase/purchase-request-view.php') . '?id=' . $prId
            : '';

        tnc_notif_create_for_users($recipients, [
            'type' => $approved ? 'pr_approved' : 'pr_rejected',
            'title' => $approved ? 'ใบขอซื้อได้รับการอนุมัติ' : 'ใบขอซื้อไม่ได้รับการอนุมัติ',
            'message' => $approved
                ? ('ใบขอซื้อ ' . $prNo . ' ได้รับการอนุมัติแล้ว')
                : ('ใบขอซื้อ ' . $prNo . ' ไม่ได้รับการอนุมัติ'),
            'link' => $link,
            'entity_type' => 'purchase_request',
            'entity_id' => $prId,
        ]);
    }
}
