<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__, 2) . '/includes/document_color_runtime.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_audit_log.php';
require_once dirname(__DIR__, 2) . '/includes/tnc_flash.php';

use Theelincon\Rtdb\Db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

if (!user_can('page.internal.doc_colors')) {
    http_response_code(403);
    echo 'ไม่มีสิทธิ์เข้าถึง — หน้านี้สำหรับผู้ดูแลระบบ (ADMIN) เท่านั้น';
    exit;
}

$configError = '';
$definitions = tnc_doc_color_definitions();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_doc_colors'])) {
    if (!csrf_verify_request()) {
        $configError = 'csrf';
    } else {
        $payload = [
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => (int) $_SESSION['user_id'],
        ];
        $invalidKeys = [];

        foreach ($definitions as $docKey => $def) {
            $field = 'color_' . $docKey;
            $raw = trim((string) ($_POST[$field] ?? ''));
            $normalized = tnc_doc_color_normalize($raw, (string) $def['default']);
            if ($raw !== '' && $normalized !== strtolower($raw) && !preg_match('/^#[0-9a-f]{6}$/', strtolower($raw))) {
                $invalidKeys[] = (string) ($def['label_th'] ?? $docKey);
            }
            $payload[tnc_doc_color_rtdb_key($docKey)] = $normalized;
        }

        if ($invalidKeys !== []) {
            $configError = 'invalid';
        } else {
            $before = Db::row(DOCUMENT_COLOR_CONFIG_TABLE, DOCUMENT_COLOR_CONFIG_PK);
            Db::mergeRow(DOCUMENT_COLOR_CONFIG_TABLE, DOCUMENT_COLOR_CONFIG_PK, $payload);
            tnc_doc_color_clear_cache();
            $after = Db::row(DOCUMENT_COLOR_CONFIG_TABLE, DOCUMENT_COLOR_CONFIG_PK);
            tnc_audit_log('update', 'document_color_config', DOCUMENT_COLOR_CONFIG_PK, 'ตั้งค่าโทนสีเอกสาร', [
                'source' => 'config_color_docs.php',
                'action' => 'save_doc_colors',
                'before' => is_array($before) ? $before : null,
                'after' => is_array($after) ? $after : null,
            ]);
            header('Location: ' . app_path('pages/internal/config_color_docs.php') . '?saved=1');
            exit;
        }
    }
}

/** @var array<string, string> $formColors */
$formColors = [];
foreach ($definitions as $docKey => $def) {
    $formColors[$docKey] = tnc_doc_color_primary($docKey);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_doc_colors']) && $configError !== '') {
    foreach ($definitions as $docKey => $def) {
        $field = 'color_' . $docKey;
        $raw = trim((string) ($_POST[$field] ?? ''));
        $formColors[$docKey] = tnc_doc_color_normalize($raw, (string) $def['default']);
    }
}

$configRow = tnc_doc_color_config_row();
$updatedAt = trim((string) ($configRow['updated_at'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tnc_ops_head.php';
    tnc_ops_head([
        'title' => 'ตั้งค่าโทนสีเอกสาร | THEELIN CON',
        'doc_color_config' => true,
        'document_color_style' => true,
        'sweetalert' => true,
        'include_ops_ui' => false,
    ]);
    ?>
</head>
<body class="tnc-app-body">

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 doc-color-shell">
    <div class="tnc-page-head mb-4">
        <div>
            <p class="tnc-page-kicker">Admin</p>
            <h1 class="tnc-list-title mb-1">
                <span class="tnc-list-title__icon me-2"><i class="bi bi-palette-fill"></i></span>
                ตั้งค่าโทนสีเอกสาร
            </h1>
            <p class="text-muted mb-0 small">กำหนดสีหลักของ PR, PO, Invoice และใบกำกับภาษี — เอกสารทุกใบจะดึงสีจากค่าที่บันทึกไว้ที่นี่</p>
            <?php if ($updatedAt !== ''): ?>
            <p class="text-muted mb-0 small mt-1"><i class="bi bi-clock-history me-1"></i>อัปเดตล่าสุด: <?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php
            require_once dirname(__DIR__, 2) . '/includes/tnc_ui.php';
            echo tnc_ui_back_previous_button();
            ?>
        </div>
    </div>

    <?php if ($configError === 'csrf'): ?>
    <div class="alert alert-danger">โทเค็นความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง</div>
    <?php elseif ($configError === 'invalid'): ?>
    <div class="alert alert-danger">รูปแบบสีไม่ถูกต้อง ใช้รหัส HEX เช่น #ea580c</div>
    <?php endif; ?>

    <form method="post" class="doc-color-card mb-4">
        <?php csrf_field(); ?>
        <input type="hidden" name="save_doc_colors" value="1">

        <?php foreach ($definitions as $docKey => $def):
            $primary = $formColors[$docKey] ?? (string) $def['default'];
            $palette = tnc_doc_color_palette($docKey);
            $field = 'color_' . $docKey;
            $previewTitle = match ($docKey) {
                'pr' => 'PURCHASE REQUISITION',
                'po' => 'PURCHASE ORDER',
                'invoice' => 'INVOICE',
                'tax_invoice' => 'TAX INVOICE',
                default => 'DOCUMENT',
            };
        ?>
        <div class="doc-type-row" data-doc-key="<?= htmlspecialchars($docKey, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <h2 class="doc-type-title h6"><?= htmlspecialchars((string) $def['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="doc-type-sub"><?= htmlspecialchars((string) $def['label_th'], ENT_QUOTES, 'UTF-8') ?></p>
                <div class="doc-preview-title js-doc-preview-title" style="color: <?= htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars($previewTitle, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="doc-preview-bar js-doc-preview-bar" style="background: <?= htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') ?>;"></div>
                <div class="doc-preview-strip">
                    <span class="doc-preview-chip js-chip-primary" style="color: <?= htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') ?>; background: <?= htmlspecialchars($palette['soft'], ENT_QUOTES, 'UTF-8') ?>; border-color: <?= htmlspecialchars($palette['border'], ENT_QUOTES, 'UTF-8') ?>;">Primary</span>
                    <span class="doc-preview-chip js-chip-deep" style="color: <?= htmlspecialchars($palette['deep'], ENT_QUOTES, 'UTF-8') ?>; background: #fff; border-color: #e2e8f0;">Deep</span>
                </div>
            </div>
            <div class="doc-color-controls">
                <input type="color" value="<?= htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') ?>" aria-label="เลือกสี" class="js-color-picker" data-target="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>">
                <input type="text" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{6}" class="js-color-text" data-target="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="p-3 p-md-4 border-top bg-light">
            <button type="submit" class="btn btn-orange px-4 fw-bold"><i class="bi bi-save2 me-2"></i>บันทึกโทนสี</button>
            <a href="<?= htmlspecialchars(app_path('pages/purchase/purchase-order-view.php') . '?id=1', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary ms-2" target="_blank" rel="noopener">ดูตัวอย่าง PO</a>
        </div>
    </form>
</div>

<script>
(function () {
    function normalizeHex(raw) {
        var s = String(raw || '').trim();
        if (!s) return '';
        if (s.charAt(0) !== '#') s = '#' + s;
        if (!/^#[0-9A-Fa-f]{6}$/.test(s)) return '';
        return s.toLowerCase();
    }

    function mixWhite(hex, ratio) {
        var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
        if (!m) return '#ffffff';
        function channel(i) {
            var v = parseInt(m[i], 16);
            return Math.round(v + (255 - v) * ratio);
        }
        function toHex(n) {
            var h = n.toString(16);
            return h.length === 1 ? '0' + h : h;
        }
        return '#' + toHex(channel(1)) + toHex(channel(2)) + toHex(channel(3));
    }

    function darken(hex, amount) {
        var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
        if (!m) return hex;
        var f = 1 - amount;
        function channel(i) {
            return Math.round(parseInt(m[i], 16) * f);
        }
        function toHex(n) {
            var h = n.toString(16);
            return h.length === 1 ? '0' + h : h;
        }
        return '#' + toHex(channel(1)) + toHex(channel(2)) + toHex(channel(3));
    }

    function syncRow(row, hex) {
        var primary = normalizeHex(hex);
        if (!primary) return;
        var deep = darken(primary, 0.28);
        var soft = mixWhite(primary, 0.92);
        var border = mixWhite(primary, 0.75);
        var title = row.querySelector('.js-doc-preview-title');
        var bar = row.querySelector('.js-doc-preview-bar');
        var chipPrimary = row.querySelector('.js-chip-primary');
        var chipDeep = row.querySelector('.js-chip-deep');
        if (title) title.style.color = primary;
        if (bar) bar.style.background = primary;
        if (chipPrimary) {
            chipPrimary.style.color = primary;
            chipPrimary.style.background = soft;
            chipPrimary.style.borderColor = border;
        }
        if (chipDeep) chipDeep.style.color = deep;
    }

    document.querySelectorAll('.doc-type-row').forEach(function (row) {
        var picker = row.querySelector('.js-color-picker');
        var text = row.querySelector('.js-color-text');
        if (!picker || !text) return;

        picker.addEventListener('input', function () {
            text.value = picker.value.toLowerCase();
            syncRow(row, picker.value);
        });

        text.addEventListener('input', function () {
            var hex = normalizeHex(text.value);
            if (!hex) return;
            text.value = hex;
            picker.value = hex;
            syncRow(row, hex);
        });
    });

    var params = (typeof tncFlashSearchParams === 'function' ? tncFlashSearchParams() : new URLSearchParams(window.location.search));
    if (params.get('saved') && typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'success',
            title: 'บันทึกโทนสีแล้ว',
            text: 'เอกสารจะใช้สีใหม่ทันทีเมื่อเปิดหรือพิมพ์',
            confirmButtonColor: '#ea580c'
        });
    }
})();
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/tnc_tailwind_assets.php'; tnc_bootstrap_js_tag(); ?>
<?php include dirname(__DIR__, 2) . '/components/shell-chrome-end.php'; ?>
</body>
</html>
