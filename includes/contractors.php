<?php

declare(strict_types=1);

use Theelincon\Rtdb\Db;

if (!function_exists('tnc_contractor_bank_options')) {
    /** @return list<string> */
    function tnc_contractor_bank_options(): array
    {
        return [
            'ธนาคารกรุงเทพ',
            'ธนาคารกสิกรไทย',
            'ธนาคารกรุงไทย',
            'ธนาคารทหารไทยธนชาต',
            'ธนาคารไทยพาณิชย์',
            'ธนาคารกรุงศรีอยุธยา',
            'ธนาคารเกียรตินาคินภัทร',
            'ธนาคารซีไอเอ็มบี ไทย',
            'ธนาคารยูโอบี',
            'ธนาคารแลนด์ แอนด์ เฮ้าส์',
            'ธนาคารออมสิน',
            'ธนาคารเพื่อการเกษตรและสหกรณ์การเกษตร',
            'ธนาคารอาคารสงเคราะห์',
            'ธนาคารอิสลามแห่งประเทศไทย',
            'ธนาคารทิสโก้',
            'ธนาคารไอซีบีซี (ไทย)',
            'ธนาคารฮ่องกงและเซี่ยงไฮ้',
            'อื่นๆ',
        ];
    }
}

if (!function_exists('tnc_contractor_payment_methods')) {
    /** @return array<string, string> */
    function tnc_contractor_payment_methods(): array
    {
        return [
            'bank_transfer' => 'โอนเงินเข้าบัญชีธนาคาร',
        ];
    }
}

if (!function_exists('tnc_contractor_title_prefix_th_options')) {
    /** @return array<string, string> */
    function tnc_contractor_title_prefix_th_options(): array
    {
        return [
            'นาย' => 'นาย',
            'นาง' => 'นาง',
            'นางสาว' => 'นางสาว',
            'เด็กชาย' => 'เด็กชาย',
            'เด็กหญิง' => 'เด็กหญิง',
        ];
    }
}

if (!function_exists('tnc_contractor_title_prefix_en_options')) {
    /** @return array<string, string> */
    function tnc_contractor_title_prefix_en_options(): array
    {
        return [
            'Mr.' => 'Mr.',
            'Mrs.' => 'Mrs.',
            'Ms.' => 'Ms.',
            'Miss' => 'Miss',
        ];
    }
}

if (!function_exists('tnc_contractor_normalize_title_prefix_th')) {
    function tnc_contractor_normalize_title_prefix_th(string $raw): string
    {
        $raw = trim($raw);
        $options = tnc_contractor_title_prefix_th_options();

        return array_key_exists($raw, $options) ? $raw : '';
    }
}

if (!function_exists('tnc_contractor_normalize_title_prefix_en')) {
    function tnc_contractor_normalize_title_prefix_en(string $raw): string
    {
        $raw = trim($raw);
        $options = tnc_contractor_title_prefix_en_options();

        return array_key_exists($raw, $options) ? $raw : '';
    }
}

if (!function_exists('tnc_contractor_format_full_name')) {
    function tnc_contractor_format_full_name(string $prefix, string $first, string $last): string
    {
        $parts = array_values(array_filter([
            trim($prefix),
            trim($first),
            trim($last),
        ], static fn (string $p): bool => $p !== ''));

        return implode(' ', $parts);
    }
}

if (!function_exists('tnc_contractor_full_name_th')) {
    /** @param array<string, mixed> $row */
    function tnc_contractor_full_name_th(array $row): string
    {
        return tnc_contractor_format_full_name(
            (string) ($row['title_prefix_th'] ?? ''),
            (string) ($row['first_name_th'] ?? ''),
            (string) ($row['last_name_th'] ?? '')
        );
    }
}

if (!function_exists('tnc_contractor_full_name_en')) {
    /** @param array<string, mixed> $row */
    function tnc_contractor_full_name_en(array $row): string
    {
        return tnc_contractor_format_full_name(
            (string) ($row['title_prefix_en'] ?? ''),
            (string) ($row['first_name_en'] ?? ''),
            (string) ($row['last_name_en'] ?? '')
        );
    }
}

if (!function_exists('tnc_contractor_display_label')) {
    /** @param array<string, mixed> $row */
    function tnc_contractor_display_label(array $row): string
    {
        $nameTh = tnc_contractor_full_name_th($row);
        $nid = preg_replace('/\D+/', '', (string) ($row['national_id'] ?? ''));
        if ($nid !== '') {
            return $nameTh !== '' ? ($nameTh . ' (' . $nid . ')') : $nid;
        }

        return $nameTh !== '' ? $nameTh : ('#' . (int) ($row['id'] ?? 0));
    }
}

if (!function_exists('tnc_contractor_row_by_id')) {
    /** @return array<string, mixed>|null */
    function tnc_contractor_row_by_id(int $contractorId): ?array
    {
        if ($contractorId <= 0) {
            return null;
        }

        return Db::rowByIdField('contractors', $contractorId);
    }
}

if (!function_exists('tnc_contractor_name_from_row')) {
    /** @param array<string, mixed> $row */
    function tnc_contractor_name_from_row(array $row): string
    {
        $name = tnc_contractor_full_name_th($row);
        if ($name !== '') {
            return $name;
        }

        return tnc_contractor_full_name_en($row);
    }
}

if (!function_exists('tnc_contractor_format_national_id_display')) {
    function tnc_contractor_format_national_id_display(string $nationalId): string
    {
        $digits = tnc_contractor_normalize_national_id($nationalId);

        return $digits !== '' ? $digits : trim($nationalId);
    }
}

if (!function_exists('tnc_contractor_payment_lines')) {
    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    function tnc_contractor_payment_lines(array $row): array
    {
        $methods = tnc_contractor_payment_methods();
        $methodKey = trim((string) ($row['payment_method'] ?? ''));
        $method = $methods[$methodKey] ?? $methodKey;
        $bank = trim((string) ($row['bank_name'] ?? ''));
        $accNo = trim((string) ($row['bank_account_no'] ?? ''));
        $accName = trim((string) ($row['bank_account_name'] ?? ''));

        $lines = [];
        if ($method !== '') {
            $lines[] = $method;
        }
        $bankParts = [];
        if ($bank !== '') {
            $bankParts[] = $bank;
        }
        if ($accNo !== '') {
            $bankParts[] = 'เลขบัญชี ' . $accNo;
        }
        if ($accName !== '') {
            $bankParts[] = $accName;
        }
        if ($bankParts !== []) {
            $lines[] = implode(' · ', $bankParts);
        }

        return $lines;
    }
}

if (!function_exists('tnc_contractor_transfer_display_line')) {
    /**
     * บรรทัดช่องทางชำระ: ธนาคาร · เลขบัญชี · ชื่อบัญชี (ไม่รวมชื่อวิธีชำระ)
     *
     * @param array<string, mixed> $row
     */
    function tnc_contractor_transfer_display_line(array $row): string
    {
        $accNo = trim((string) ($row['bank_account_no'] ?? ''));
        $accName = trim((string) ($row['bank_account_name'] ?? ''));
        $bank = trim((string) ($row['bank_name'] ?? ''));
        $parts = [];
        if ($bank !== '') {
            $parts[] = $bank;
        }
        if ($accNo !== '') {
            $parts[] = $accNo;
        }
        if ($accName !== '') {
            $parts[] = $accName;
        }

        return implode(' · ', $parts);
    }
}

if (!function_exists('tnc_contractor_identity_display_line')) {
    /**
     * @param array{name_th?: string, national_id?: string, address?: string} $profile
     */
    function tnc_contractor_identity_display_line(array $profile): string
    {
        $parts = [];
        $name = trim((string) ($profile['name_th'] ?? ''));
        $nationalId = trim((string) ($profile['national_id'] ?? ''));
        $address = trim((string) ($profile['address'] ?? ''));
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($nationalId !== '') {
            $parts[] = $nationalId;
        }
        if ($address !== '') {
            $parts[] = $address;
        }

        return implode(' · ', $parts);
    }
}

if (!function_exists('tnc_contractor_print_profile')) {
    /**
     * @return array{
     *     name_th: string,
     *     national_id: string,
     *     address: string,
     *     payment_lines: list<string>,
     *     transfer_line: string,
     *     identity_line: string,
     *     found: bool
     * }
     */
    function tnc_contractor_print_profile(int $contractorId, string $fallbackName = ''): array
    {
        $profile = [
            'name_th' => trim($fallbackName),
            'national_id' => '',
            'address' => '',
            'payment_lines' => [],
            'transfer_line' => '',
            'identity_line' => '',
            'found' => false,
        ];
        $row = tnc_contractor_row_by_id($contractorId);
        if ($row === null) {
            if ($profile['name_th'] !== '') {
                $profile['identity_line'] = tnc_contractor_identity_display_line($profile);
            }

            return $profile;
        }

        $nameTh = tnc_contractor_full_name_th($row);
        $profile['name_th'] = $nameTh !== '' ? $nameTh : trim($fallbackName);
        $profile['national_id'] = tnc_contractor_format_national_id_display((string) ($row['national_id'] ?? ''));
        $profile['address'] = trim((string) ($row['address'] ?? ''));
        $profile['payment_lines'] = tnc_contractor_payment_lines($row);
        $profile['transfer_line'] = tnc_contractor_transfer_display_line($row);
        $profile['identity_line'] = tnc_contractor_identity_display_line($profile);
        $profile['found'] = true;

        return $profile;
    }
}

if (!function_exists('tnc_contractor_normalize_national_id')) {
    function tnc_contractor_normalize_national_id(string $raw): string
    {
        return preg_replace('/\D+/', '', trim($raw)) ?? '';
    }
}

if (!function_exists('tnc_contractor_name_th_key')) {
    function tnc_contractor_name_th_key(string $prefix, string $first, string $last): string
    {
        $parts = array_map(
            static fn (string $part): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($part)) ?? '', 'UTF-8'),
            [$prefix, $first, $last]
        );

        return implode('|', array_values(array_filter($parts, static fn (string $part): bool => $part !== '')));
    }
}

if (!function_exists('tnc_contractor_row_name_th_key')) {
    /** @param array<string, mixed> $row */
    function tnc_contractor_row_name_th_key(array $row): string
    {
        return tnc_contractor_name_th_key(
            (string) ($row['title_prefix_th'] ?? ''),
            (string) ($row['first_name_th'] ?? ''),
            (string) ($row['last_name_th'] ?? '')
        );
    }
}

if (!function_exists('tnc_contractor_find_duplicate_conflict')) {
    /**
     * @param array<string, mixed> $fields
     * @return 'duplicate_national_id'|'duplicate_name'|null
     */
    function tnc_contractor_find_duplicate_conflict(array $fields, int $excludeId = 0): ?string
    {
        $nationalId = tnc_contractor_normalize_national_id((string) ($fields['national_id'] ?? ''));
        $nameKey = tnc_contractor_name_th_key(
            (string) ($fields['title_prefix_th'] ?? ''),
            (string) ($fields['first_name_th'] ?? ''),
            (string) ($fields['last_name_th'] ?? '')
        );

        foreach (Db::tableRows('contractors') as $other) {
            $otherId = (int) ($other['id'] ?? 0);
            if ($otherId <= 0 || $otherId === $excludeId) {
                continue;
            }
            if ($nationalId !== ''
                && tnc_contractor_normalize_national_id((string) ($other['national_id'] ?? '')) === $nationalId) {
                return 'duplicate_national_id';
            }
            if ($nameKey !== '' && tnc_contractor_row_name_th_key($other) === $nameKey) {
                return 'duplicate_name';
            }
        }

        return null;
    }
}

if (!function_exists('tnc_contractor_is_valid_national_id')) {
    function tnc_contractor_is_valid_national_id(string $nationalId): bool
    {
        $digits = tnc_contractor_normalize_national_id($nationalId);
        if (strlen($digits) !== 13 || !ctype_digit($digits)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $digits[$i] * (13 - $i);
        }
        $check = (11 - ($sum % 11)) % 10;

        return $check === (int) $digits[12];
    }
}

if (!function_exists('tnc_contractor_validate_fields')) {
    /**
     * @param array<string, mixed> $fields
     * @param bool $requirePhoto
     * @return list<string>
     */
    function tnc_contractor_validate_fields(array $fields, bool $requirePhoto = true): array
    {
        $errors = [];
        $requiredText = [
            'title_prefix_th' => 'คำนำหน้า (ภาษาไทย)',
            'first_name_th' => 'ชื่อภาษาไทย',
            'last_name_th' => 'นามสกุลภาษาไทย',
            'title_prefix_en' => 'คำนำหน้า (ภาษาอังกฤษ)',
            'first_name_en' => 'ชื่อภาษาอังกฤษ',
            'last_name_en' => 'นามสกุลภาษาอังกฤษ',
            'national_id' => 'เลขบัตรประชาชน',
            'birth_date' => 'วันเกิด',
            'address' => 'ที่อยู่',
            'bank_account_no' => 'เลขบัญชี',
            'bank_name' => 'ธนาคาร',
            'bank_account_name' => 'ชื่อบัญชีธนาคาร',
        ];
        foreach ($requiredText as $key => $label) {
            if (trim((string) ($fields[$key] ?? '')) === '') {
                $errors[] = 'กรุณากรอก' . $label;
            }
        }

        $paymentMethod = trim((string) ($fields['payment_method'] ?? ''));
        if ($paymentMethod === '') {
            $errors[] = 'กรุณาเลือกช่องทางการชำระเงิน';
        }

        if (trim((string) ($fields['title_prefix_th'] ?? '')) !== ''
            && tnc_contractor_normalize_title_prefix_th((string) ($fields['title_prefix_th'] ?? '')) === '') {
            $errors[] = 'คำนำหน้า (ภาษาไทย) ไม่ถูกต้อง';
        }
        if (trim((string) ($fields['title_prefix_en'] ?? '')) !== ''
            && tnc_contractor_normalize_title_prefix_en((string) ($fields['title_prefix_en'] ?? '')) === '') {
            $errors[] = 'คำนำหน้า (ภาษาอังกฤษ) ไม่ถูกต้อง';
        }

        $nationalId = tnc_contractor_normalize_national_id((string) ($fields['national_id'] ?? ''));
        if ($nationalId !== '' && !tnc_contractor_is_valid_national_id($nationalId)) {
            $errors[] = 'เลขบัตรประชาชนไม่ถูกต้อง';
        }

        $birthDate = trim((string) ($fields['birth_date'] ?? ''));
        if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            $errors[] = 'รูปแบบวันเกิดไม่ถูกต้อง';
        } elseif ($birthDate !== '' && strtotime($birthDate) > strtotime('today')) {
            $errors[] = 'วันเกิดต้องไม่เกินวันนี้';
        }

        if ($requirePhoto && trim((string) ($fields['id_card_photo_path'] ?? '')) === '') {
            $errors[] = 'กรุณาแนบรูปบัตรประชาชน';
        }

        return $errors;
    }
}

if (!function_exists('tnc_contractor_save_id_photo')) {
    /**
     * @return array{ok: bool, path: string, name: string, mime: string, size: int, error: string}
     */
    function tnc_contractor_save_id_photo(int $contractorId, array $file): array
    {
        $fail = static fn (string $msg): array => [
            'ok' => false,
            'path' => '',
            'name' => '',
            'mime' => '',
            'size' => 0,
            'error' => $msg,
        ];

        if ($contractorId <= 0) {
            return $fail('invalid_id');
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return $fail('no_file');
        }
        if ($err !== UPLOAD_ERR_OK) {
            return $fail('upload_failed');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $fail('upload_failed');
        }

        $originalName = trim((string) ($file['name'] ?? 'id-card'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExt, true)) {
            return $fail('upload_type');
        }

        $dirAbs = ROOT_PATH . '/uploads/contractor-id-photos/' . $contractorId;
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            return $fail('upload_failed');
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBase = trim((string) $safeBase, '._-');
        if ($safeBase === '') {
            $safeBase = 'id-card';
        }
        $relPath = 'uploads/contractor-id-photos/' . $contractorId . '/' . $safeBase . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs = ROOT_PATH . '/' . $relPath;
        if (!move_uploaded_file($tmp, $destAbs)) {
            return $fail('upload_failed');
        }

        return [
            'ok' => true,
            'path' => $relPath,
            'name' => $originalName,
            'mime' => (string) ($file['type'] ?? 'image/' . $ext),
            'size' => (int) ($file['size'] ?? 0),
            'error' => '',
        ];
    }
}

if (!function_exists('tnc_contractor_resolve_from_post')) {
    /** @return array{row: array<string, mixed>|null, name: string, id: int} */
    function tnc_contractor_resolve_from_post(array $post): array
    {
        $contractorId = (int) ($post['contractor_id'] ?? 0);
        $row = tnc_contractor_row_by_id($contractorId);
        if ($row === null) {
            return ['row' => null, 'name' => '', 'id' => 0];
        }

        return [
            'row' => $row,
            'name' => tnc_contractor_name_from_row($row),
            'id' => $contractorId,
        ];
    }
}

if (!function_exists('tnc_contractor_id_photo_url')) {
    /** @param array<string, mixed> $row */
    function tnc_contractor_id_photo_url(array $row): string
    {
        $rel = trim((string) ($row['id_card_photo_path'] ?? ''));
        if ($rel === '') {
            return '';
        }

        return app_path($rel);
    }
}
