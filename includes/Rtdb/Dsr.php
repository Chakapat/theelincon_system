<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

/** สมุดรายวันหน้างาน — แทน JOIN ในรายการ */
final class Dsr
{
    /** @return list<array<string,mixed>> */
    public static function listRowsForListPage(): array
    {
        $reports = Db::tableRows('daily_site_reports');
        $users = Db::tableKeyed('users');
        $companies = Db::tableKeyed('company');

        foreach ($reports as &$r) {
            $uid = (string) ($r['created_by'] ?? '');
            $u = $users[$uid] ?? null;
            $r['recorder_name'] = trim((string) (($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')));
            $cid = (string) ($r['company_id'] ?? '');
            $r['company_name'] = (string) (($companies[$cid]['name'] ?? '') ?: '');
        }
        unset($r);

        usort($reports, static function ($a, $b): int {
            $da = (string) ($a['report_date'] ?? '');
            $db = (string) ($b['report_date'] ?? '');
            if ($da !== $db) {
                return strcmp($db, $da);
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        return $reports;
    }

    /** @return list<array<string,mixed>> */
    public static function listRowsForCalendarPage(): array
    {
        $reports = self::listRowsForListPage();
        $photosAll = Db::tableRows('daily_site_report_photos');
        $byReport = [];

        foreach ($photosAll as $ph) {
            $rid = (int) ($ph['report_id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            if (!isset($byReport[$rid])) {
                $byReport[$rid] = [];
            }
            $byReport[$rid][] = $ph;
        }

        foreach ($reports as &$r) {
            $id = (int) ($r['id'] ?? 0);
            $photos = $byReport[$id] ?? [];
            usort($photos, static function ($a, $b): int {
                $sa = (int) ($a['sort_order'] ?? 0);
                $sb = (int) ($b['sort_order'] ?? 0);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });
            $r['photos'] = $photos;
        }
        unset($r);

        return $reports;
    }
}
