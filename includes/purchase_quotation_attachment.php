<?php

declare(strict_types=1);

/**
 * ไฟล์แนบใบเสนอราคา (PR / PO) — PDF หรือรูปภาพ
 */

/**
 * @return list<string>
 */
function tnc_purchase_quotation_allowed_ext(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'];
}

/**
 * @param array<string, mixed> $file Single $_FILES entry
 * @return array{ok:true, path:string, url:string, name:string, mime:string, size:int}|array{ok:false, error:string}|null
 *         null = ไม่มีไฟล์แนบมา
 */
function tnc_purchase_quotation_upload(string $folder, int $entityId, array $file): ?array
{
    $folder = trim($folder, '/');
    if ($folder === '' || $entityId <= 0) {
        return ['ok' => false, 'error' => 'upload_failed'];
    }

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'upload_failed'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'upload_failed'];
    }

    $originalName = trim((string) ($file['name'] ?? 'quotation'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, tnc_purchase_quotation_allowed_ext(), true)) {
        return ['ok' => false, 'error' => 'upload_type'];
    }

    $dirAbs = ROOT_PATH . '/uploads/' . $folder . '/' . $entityId;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        return ['ok' => false, 'error' => 'upload_failed'];
    }

    $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBase = trim((string) $safeBase, '._-');
    if ($safeBase === '') {
        $safeBase = 'quotation';
    }
    $storedName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
    $destAbs = $dirAbs . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $destAbs)) {
        return ['ok' => false, 'error' => 'upload_failed'];
    }

    $path = 'uploads/' . $folder . '/' . $entityId . '/' . $storedName;

    return [
        'ok' => true,
        'path' => $path,
        'url' => app_path($path),
        'name' => $originalName,
        'mime' => (string) ($file['type'] ?? ''),
        'size' => (int) ($file['size'] ?? 0),
    ];
}

/**
 * คัดลอกไฟล์แนบจาก path เดิมไปโฟลเดอร์ entity ใหม่ (เช่น PR → PO)
 *
 * @return array{ok:true, path:string, url:string, name:string, mime:string, size:int}|null
 */
function tnc_purchase_quotation_copy_from_path(
    string $sourceRel,
    string $folder,
    int $entityId,
    string $originalName = '',
    string $mime = ''
): ?array {
    $sourceRel = str_replace('\\', '/', trim($sourceRel));
    $sourceRel = ltrim($sourceRel, '/');
    if ($sourceRel === '' || !str_starts_with($sourceRel, 'uploads/') || str_contains($sourceRel, '..')) {
        return null;
    }
    $srcAbs = ROOT_PATH . '/' . $sourceRel;
    if (!is_file($srcAbs) || !is_readable($srcAbs)) {
        return null;
    }

    $folder = trim($folder, '/');
    if ($folder === '' || $entityId <= 0) {
        return null;
    }

    $ext = strtolower(pathinfo($sourceRel, PATHINFO_EXTENSION));
    if (!in_array($ext, tnc_purchase_quotation_allowed_ext(), true)) {
        return null;
    }

    $dirAbs = ROOT_PATH . '/uploads/' . $folder . '/' . $entityId;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        return null;
    }

    if ($originalName === '') {
        $originalName = basename($sourceRel);
    }
    $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBase = trim((string) $safeBase, '._-');
    if ($safeBase === '') {
        $safeBase = 'quotation';
    }
    $storedName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
    $destAbs = $dirAbs . '/' . $storedName;
    if (!@copy($srcAbs, $destAbs)) {
        return null;
    }

    $path = 'uploads/' . $folder . '/' . $entityId . '/' . $storedName;
    $size = (int) @filesize($destAbs);

    return [
        'ok' => true,
        'path' => $path,
        'url' => app_path($path),
        'name' => $originalName,
        'mime' => $mime,
        'size' => $size > 0 ? $size : 0,
    ];
}

/**
 * @param array<string, mixed> $row PR หรือ PO
 * @return array{has:bool, path:string, url:string, name:string, mime:string, size:int, from_pr:bool}
 */
function tnc_purchase_quotation_info(array $row, bool $fromPr = false): array
{
    $path = trim((string) ($row['quotation_attachment_path'] ?? ''));
    $path = str_replace('\\', '/', $path);
    if ($path !== '' && (str_contains($path, '..') || !str_starts_with($path, 'uploads/'))) {
        $path = '';
    }
    $name = trim((string) ($row['quotation_attachment_name'] ?? ''));
    $url = trim((string) ($row['quotation_attachment_url'] ?? ''));
    if ($path !== '' && $url === '') {
        $url = app_path($path);
    }
    if ($name === '' && $path !== '') {
        $name = basename($path);
    }

    return [
        'has' => $path !== '',
        'path' => $path,
        'url' => $url,
        'name' => $name,
        'mime' => trim((string) ($row['quotation_attachment_mime'] ?? '')),
        'size' => (int) ($row['quotation_attachment_size'] ?? 0),
        'from_pr' => $fromPr && $path !== '',
    ];
}

/**
 * ใช้ไฟล์บน PO ก่อน — ถ้าไม่มีให้ใช้จาก PR ที่เชื่อม
 *
 * @param array<string, mixed> $po
 * @param array<string, mixed>|null $pr
 * @return array{has:bool, path:string, url:string, name:string, mime:string, size:int, from_pr:bool}
 */
function tnc_purchase_quotation_info_for_po(array $po, ?array $pr = null): array
{
    $info = tnc_purchase_quotation_info($po, false);
    if ($info['has'] || !is_array($pr)) {
        return $info;
    }

    return tnc_purchase_quotation_info($pr, true);
}

/**
 * ผสานข้อมูลแนบ QT ลงแถวเอกสารสำหรับแสดง/พิมพ์ (ไม่เขียน DB)
 *
 * @param array<string, mixed> $row
 * @param array{has:bool, path:string, url:string, name:string, mime:string, size:int, from_pr?:bool} $info
 * @return array<string, mixed>
 */
function tnc_purchase_quotation_apply_to_row(array $row, array $info): array
{
    if (empty($info['has'])) {
        return $row;
    }
    $row['quotation_attachment_path'] = (string) ($info['path'] ?? '');
    $row['quotation_attachment_url'] = (string) ($info['url'] ?? '');
    $row['quotation_attachment_name'] = (string) ($info['name'] ?? '');
    $row['quotation_attachment_mime'] = (string) ($info['mime'] ?? '');
    $row['quotation_attachment_size'] = (int) ($info['size'] ?? 0);
    $row['quotation_attachment_from_pr'] = !empty($info['from_pr']) ? 1 : 0;

    return $row;
}

/**
 * HTML อ้างอิงใบเสนอราคาบนหัวเอกสาร — แสดงเฉพาะเลขที่ QT (ไม่ฝังลิงก์ไฟล์ในเอกสาร)
 */
function tnc_purchase_quotation_doc_header_html(array $info, string $quotationNumber = ''): string
{
    $quotationNumber = trim($quotationNumber);
    if ($quotationNumber === '') {
        return '';
    }

    return '<div class="small text-muted tnc-doc-qt-number">อ้างอิงใบเสนอราคา: '
        . htmlspecialchars($quotationNumber, ENT_QUOTES, 'UTF-8')
        . '</div>';
}

/**
 * @param array{path?:string, url?:string, name?:string, mime?:string, size?:int} $fields
 * @return array{quotation_attachment_path:string, quotation_attachment_url:string, quotation_attachment_name:string, quotation_attachment_mime:string, quotation_attachment_size:int}
 */
function tnc_purchase_quotation_db_fields(array $fields): array
{
    return [
        'quotation_attachment_path' => trim((string) ($fields['path'] ?? '')),
        'quotation_attachment_url' => trim((string) ($fields['url'] ?? '')),
        'quotation_attachment_name' => trim((string) ($fields['name'] ?? '')),
        'quotation_attachment_mime' => trim((string) ($fields['mime'] ?? '')),
        'quotation_attachment_size' => (int) ($fields['size'] ?? 0),
    ];
}
