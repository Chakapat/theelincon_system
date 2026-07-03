<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

const LINE_TASK_ASSIGNEES_TABLE = 'line_task_assignees';

/** @return list<array<string, mixed>> */
function line_task_assignees_list(bool $activeOnly = true): array
{
    $rows = Db::tableRows(LINE_TASK_ASSIGNEES_TABLE);
    if ($activeOnly) {
        $rows = array_values(array_filter($rows, static function (array $r): bool {
            return !isset($r['is_active']) || (int) $r['is_active'] !== 0;
        }));
    }
    usort($rows, static function (array $a, array $b): int {
        $sa = (int) ($a['sort_order'] ?? 0);
        $sb = (int) ($b['sort_order'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        return strcasecmp(
            trim((string) ($a['name'] ?? '')),
            trim((string) ($b['name'] ?? ''))
        );
    });

    return $rows;
}

/** @return array<string, mixed>|null */
function line_task_assignee_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    return Db::rowByIdField(LINE_TASK_ASSIGNEES_TABLE, $id);
}

/**
 * @return array{ok: bool, id: int, error: ?string}
 */
function line_task_assignee_save(int $id, string $name, string $userLineId, bool $isActive = true): array
{
    $name = trim($name);
    $userLineId = trim($userLineId);
    if ($name === '') {
        return ['ok' => false, 'id' => 0, 'error' => 'need_name'];
    }
    if ($userLineId === '' || !preg_match('/^U[a-f0-9]{32}$/i', $userLineId)) {
        return ['ok' => false, 'id' => 0, 'error' => 'invalid_line_id'];
    }

    $now = date('Y-m-d H:i:s');
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

    if ($id > 0) {
        $existing = line_task_assignee_by_id($id);
        if ($existing === null) {
            return ['ok' => false, 'id' => 0, 'error' => 'not_found'];
        }
        Db::setRow(LINE_TASK_ASSIGNEES_TABLE, (string) $id, array_merge($existing, [
            'name' => $name,
            'user_line_id' => $userLineId,
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => $now,
            'updated_by' => $userId,
        ]));

        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    $newId = Db::nextNumericId(LINE_TASK_ASSIGNEES_TABLE);
    Db::setRow(LINE_TASK_ASSIGNEES_TABLE, (string) $newId, [
        'id' => $newId,
        'name' => $name,
        'user_line_id' => $userLineId,
        'is_active' => $isActive ? 1 : 0,
        'sort_order' => $newId,
        'created_at' => $now,
        'updated_at' => $now,
        'created_by' => $userId,
        'updated_by' => $userId,
    ]);

    return ['ok' => true, 'id' => $newId, 'error' => null];
}

/**
 * @return array{ok: bool, error: ?string}
 */
function line_task_assignee_delete(int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'invalid_id'];
    }
    if (line_task_assignee_by_id($id) === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    Db::deleteRow(LINE_TASK_ASSIGNEES_TABLE, (string) $id);

    return ['ok' => true, 'error' => null];
}
