<?php

declare(strict_types=1);

namespace Theelincon\Rtdb;

use Kreait\Firebase\Database\Reference;
use Theelincon\Firebase\RtdbFactory;

/**
 * อ่าน/เขียน Realtime DB ใต้ theelincon_mirror/ (ข้อมูลเดิมจาก MySQL)
 */
final class Db
{
    public static function rootPath(): string
    {
        require_once dirname(__DIR__, 2) . '/config/firebase_settings.php';

        return THEELINCON_RTDB_MIRROR_ROOT;
    }

    public static function tableRef(string $table): Reference
    {
        return RtdbFactory::database()->getReference(self::rootPath() . '/' . $table);
    }

    /** @return array<string, array<string, mixed>> */
    public static function tableKeyed(string $table): array
    {
        $snap = self::tableRef($table)->getSnapshot();
        $v = $snap->getValue();

        if (!is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $pk => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[(string) $pk] = $row;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function tableRows(string $table): array
    {
        return array_values(self::tableKeyed($table));
    }

    /** @return array<string, mixed>|null */
    public static function row(string $table, string $pk): ?array
    {
        $snap = self::tableRef($table)->getChild($pk)->getSnapshot();
        $v = $snap->getValue();

        return is_array($v) ? $v : null;
    }

    public static function setRow(string $table, string $pk, array $data): void
    {
        self::tableRef($table)->getChild($pk)->set($data);
    }

    public static function mergeRow(string $table, string $pk, array $partial): void
    {
        $cur = self::row($table, $pk) ?? [];
        self::setRow($table, $pk, array_merge($cur, $partial));
    }

    public static function deleteRow(string $table, string $pk): void
    {
        self::tableRef($table)->getChild($pk)->remove();
    }

    /** ลบหลายแถวที่ key ขึ้นต้นด้วย prefix (ช่วยลบ items ที่เก็บแยก) — ระวังประสิทธิภาพ */
    public static function deleteRowsMatching(string $table, callable $predicate): int
    {
        $n = 0;
        foreach (self::tableKeyed($table) as $pk => $row) {
            if ($predicate((string) $pk, $row)) {
                self::deleteRow($table, (string) $pk);
                ++$n;
            }
        }

        return $n;
    }

    public static function sanitizeKeyPart(string $v): string
    {
        return preg_replace('/[.#$\[\]\/]/', '_', $v) ?? $v;
    }

    /** คีย์ผสมเหมือนสคริปต์ migrate */
    public static function compositeKey(array $parts): string
    {
        return implode('__', array_map(fn ($p) => self::sanitizeKeyPart((string) $p), $parts));
    }

    /** หาเลข pk ใหม่ (ดูจากคีย์และฟิลด์) */
    public static function nextNumericId(string $table, string $pkColumn = 'id'): int
    {
        $max = 0;
        foreach (self::tableKeyed($table) as $key => $row) {
            $kc = ctype_digit((string) $key) ? (int) $key : 0;
            $kr = isset($row[$pkColumn]) && is_numeric($row[$pkColumn]) ? (int) $row[$pkColumn] : 0;
            $max = max($max, $kc, $kr);
        }

        return $max + 1;
    }

    /** @param callable(array<string,mixed>):bool $fn */
    public static function findFirst(string $table, callable $fn): ?array
    {
        foreach (self::tableRows($table) as $row) {
            if ($fn($row)) {
                return $row;
            }
        }

        return null;
    }

    /** @param callable(array<string,mixed>):bool $fn */
    public static function filter(string $table, callable $fn): array
    {
        return array_values(array_filter(self::tableRows($table), $fn));
    }

    /** usort ช่วย ORDER BY */
    public static function sortRows(array &$rows, string $field, bool $desc = false): void
    {
        usort($rows, static function ($a, $b) use ($field, $desc): int {
            $va = $a[$field] ?? '';
            $vb = $b[$field] ?? '';
            if (is_numeric($va) && is_numeric($vb)) {
                $cmp = (float) $va <=> (float) $vb;
            } else {
                $cmp = strcmp((string) $va, (string) $vb);
            }

            return $desc ? -$cmp : $cmp;
        });
    }

    /** ลบแถวที่ field = value (เทียบแบบ string) */
    public static function deleteWhereEquals(string $table, string $field, string $value): int
    {
        $n = 0;
        foreach (self::tableKeyed($table) as $pk => $row) {
            if (isset($row[$field]) && (string) $row[$field] === $value) {
                self::deleteRow($table, (string) $pk);
                ++$n;
            }
        }

        return $n;
    }
}
