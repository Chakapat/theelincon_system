<?php

declare(strict_types=1);

require_once __DIR__ . '/purchase_print/vat_print_summary.php';

if (!function_exists('tnc_po_adjustments_editor_seed')) {
    /**
     * @return list<array{label:string,input:string,sign:string}>
     */
    function tnc_po_adjustments_editor_seed(?array $po = null): array
    {
        if ($po !== null) {
            $lines = tnc_po_adjustment_lines_from_row($po);
            if ($lines !== []) {
                return $lines;
            }
        }

        return [[
            'label' => '',
            'input' => '',
            'sign' => 'subtract',
        ]];
    }
}

if (!function_exists('tnc_po_render_adjustments_summary_slot')) {
    function tnc_po_render_adjustments_summary_slot(): void
    {
        echo '<div id="po_adjustments_summary" class="po-adjustments-summary" aria-live="polite"></div>';
    }
}

if (!function_exists('tnc_po_render_adjustments_panel')) {
    /**
     * @param list<array{label?:string,input?:string,sign?:string}> $initialLines
     */
    function tnc_po_render_adjustments_panel(array $initialLines = []): void
    {
        if ($initialLines === []) {
            $initialLines = [[
                'label' => '',
                'input' => '',
                'sign' => 'subtract',
            ]];
        }
        ?>
        <div class="po-adjustments-panel">
            <div class="po-adjustments-panel__head">
                <div>
                    <h3 class="po-adjustments-panel__title">ปรับยอดสุทธิ</h3>
                    <p class="po-adjustments-panel__hint">ไม่บังคับ · หักหรือบวกหลัง VAT · แสดงบน PO</p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-orange rounded-pill po-adjustments-panel__add" id="po_adjustment_add">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มรายการ
                </button>
            </div>
            <div class="po-adjustment-row po-adjustment-row--head" aria-hidden="true">
                <span>บวก/ลบ</span>
                <span>ชื่อรายการ</span>
                <span>จำนวน</span>
                <span></span>
            </div>
            <div id="po_adjustments_rows" class="po-adjustments-rows">
                <?php foreach ($initialLines as $line): ?>
                    <?php
                    $adjSign = (($line['sign'] ?? 'subtract') === 'add') ? 'add' : 'subtract';
                    $adjLabel = trim((string) ($line['label'] ?? ''));
                    $adjInput = trim((string) ($line['input'] ?? ''));
                    ?>
                    <div class="po-adjustment-row">
                        <select name="adjustment_sign[]" class="form-select form-select-sm po-adj-sign" aria-label="บวกหรือลบ">
                            <option value="subtract"<?= $adjSign === 'subtract' ? ' selected' : '' ?>>− ลบ</option>
                            <option value="add"<?= $adjSign === 'add' ? ' selected' : '' ?>>+ บวก</option>
                        </select>
                        <input type="text" name="adjustment_label[]" class="form-control form-control-sm po-adj-label" maxlength="120" placeholder="เช่น หักประกันผลงาน" value="<?= htmlspecialchars($adjLabel, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                        <input type="text" name="adjustment_input[]" class="form-control form-control-sm po-adj-input text-end" maxlength="20" inputmode="decimal" placeholder="500 หรือ 5%" value="<?= htmlspecialchars($adjInput, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                        <button type="button" class="btn btn-sm btn-outline-danger po-adj-remove" title="ลบรายการ" aria-label="ลบรายการ"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="po-adjustments-panel__foot form-text mb-0">% คิดจากฐานก่อน VAT · ว่างจำนวน = ไม่นับรายการนั้น</p>
        </div>
        <?php
    }
}

if (!function_exists('tnc_po_render_adjustments_editor')) {
    /** @deprecated ใช้ tnc_po_render_adjustments_panel + tnc_po_render_adjustments_summary_slot */
    function tnc_po_render_adjustments_editor(array $initialLines = []): void
    {
        tnc_po_render_adjustments_panel($initialLines);
        tnc_po_render_adjustments_summary_slot();
    }
}
