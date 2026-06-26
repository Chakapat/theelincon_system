<?php

declare(strict_types=1);

if (!function_exists('tnc_party_logo_public_url')) {
    function tnc_party_logo_public_url(?string $storedFilename): string
    {
        if ($storedFilename === null || trim($storedFilename) === '') {
            return '';
        }
        $basename = basename(str_replace('\\', '/', trim($storedFilename)));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return '';
        }
        $abs = ROOT_PATH . '/uploads/logos/' . $basename;
        if (is_file($abs)) {
            return upload_logo_url($basename);
        }
        $assetAbs = ROOT_PATH . '/assets/img/' . $basename;
        if (is_file($assetAbs)) {
            return app_path('assets/img/' . $basename);
        }

        return '';
    }
}

if (!function_exists('tnc_party_logo_delete_stored')) {
    function tnc_party_logo_delete_stored(?string $storedFilename): void
    {
        if ($storedFilename === null || trim($storedFilename) === '') {
            return;
        }
        $basename = basename(str_replace('\\', '/', trim($storedFilename)));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return;
        }
        $abs = ROOT_PATH . '/uploads/logos/' . $basename;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}

if (!function_exists('tnc_party_logo_upload_from_post')) {
    /**
     * @param array<string, mixed> $file
     * @return array{ok: bool, filename: string, error: string}
     */
    function tnc_party_logo_upload_from_post(array $file): array
    {
        $fail = static fn (string $code): array => ['ok' => false, 'filename' => '', 'error' => $code];

        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'filename' => '', 'error' => ''];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return $fail('upload_failed');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $fail('upload_failed');
        }

        $originalName = trim((string) ($file['name'] ?? 'logo'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowedExt, true)) {
            return $fail('upload_type');
        }

        $dirAbs = ROOT_PATH . '/uploads/logos';
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            return $fail('upload_failed');
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim((string) $safeBase, '._-');
        if ($safeBase === '') {
            $safeBase = 'logo';
        }
        $storedName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs = $dirAbs . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $destAbs)) {
            return $fail('upload_failed');
        }

        return ['ok' => true, 'filename' => $storedName, 'error' => ''];
    }
}
