<?php

declare(strict_types=1);

if (!function_exists('tnc_bank_options')) {
    /** @return list<string> */
    function tnc_bank_options(): array
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

if (!function_exists('tnc_bank_logo_slug_map')) {
    /** @return array<string, string> bank display name => asset filename (without path) */
    function tnc_bank_logo_slug_map(): array
    {
        return [
            'ธนาคารกรุงเทพ' => 'bbl.svg',
            'ธนาคารกสิกรไทย' => 'kbank.svg',
            'ธนาคารกรุงไทย' => 'ktb.svg',
            'ธนาคารทหารไทยธนชาต' => 'ttb.svg',
            'ธนาคารไทยพาณิชย์' => 'scb.svg',
            'ธนาคารกรุงศรีอยุธยา' => 'bay.svg',
            'ธนาคารเกียรตินาคินภัทร' => 'kkp.svg',
            'ธนาคารซีไอเอ็มบี ไทย' => 'cimb.svg',
            'ธนาคารยูโอบี' => 'uob.svg',
            'ธนาคารแลนด์ แอนด์ เฮ้าส์' => 'lhb.svg',
            'ธนาคารออมสิน' => 'gsb.svg',
            'ธนาคารเพื่อการเกษตรและสหกรณ์การเกษตร' => 'baac.svg',
            'ธนาคารอาคารสงเคราะห์' => 'ghb.svg',
            'ธนาคารอิสลามแห่งประเทศไทย' => 'ibank.svg',
            'ธนาคารทิสโก้' => 'tisco.svg',
            'ธนาคารไอซีบีซี (ไทย)' => 'icbc.svg',
            'ธนาคารฮ่องกงและเซี่ยงไฮ้' => 'hsbc.svg',
            'อื่นๆ' => 'other.svg',
        ];
    }
}

if (!function_exists('tnc_bank_logo_filename')) {
    function tnc_bank_logo_filename(string $bankName): string
    {
        $bankName = trim($bankName);
        if ($bankName === '') {
            return '';
        }
        $map = tnc_bank_logo_slug_map();

        return $map[$bankName] ?? '';
    }
}

if (!function_exists('tnc_bank_logos_base_url')) {
    function tnc_bank_logos_base_url(): string
    {
        return rtrim(app_path('assets/img/banks'), '/') . '/';
    }
}

if (!function_exists('tnc_bank_logo_url')) {
    function tnc_bank_logo_url(string $bankName): string
    {
        $filename = tnc_bank_logo_filename($bankName);
        if ($filename === '') {
            return '';
        }
        $path = ROOT_PATH . '/assets/img/banks/' . $filename;
        if (!is_file($path)) {
            return '';
        }

        return tnc_bank_logos_base_url() . rawurlencode($filename);
    }
}

if (!function_exists('tnc_bank_logo_url_map')) {
    /** @return array<string, string> */
    function tnc_bank_logo_url_map(): array
    {
        $out = [];
        foreach (tnc_bank_options() as $name) {
            $url = tnc_bank_logo_url($name);
            if ($url !== '') {
                $out[$name] = $url;
            }
        }

        return $out;
    }
}

if (!function_exists('tnc_normalize_bank_account_number')) {
    function tnc_normalize_bank_account_number(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        return mb_substr($digits, 0, 20);
    }
}

if (!function_exists('tnc_normalize_company_bank_fields')) {
    /**
     * @param array<string, mixed> $fields
     * @return array{bank_name: string, bank_account_name: string, bank_account_number: string}
     */
    function tnc_normalize_company_bank_fields(array $fields): array
    {
        $bankName = mb_substr(trim((string) ($fields['bank_name'] ?? '')), 0, 120);
        $allowed = tnc_bank_options();
        if ($bankName !== '' && !in_array($bankName, $allowed, true)) {
            $bankName = '';
        }

        return [
            'bank_name' => $bankName,
            'bank_account_name' => mb_substr(trim((string) ($fields['bank_account_name'] ?? '')), 0, 200),
            'bank_account_number' => tnc_normalize_bank_account_number((string) ($fields['bank_account_number'] ?? '')),
        ];
    }
}
