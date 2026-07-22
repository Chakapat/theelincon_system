(function (global) {
    'use strict';

    function money2(n) {
        if (typeof global.tncPurchaseMoneySatang === 'function') {
            return global.tncPurchaseMoneySatang(n);
        }
        n = Number(n);
        if (!isFinite(n)) {
            return 0;
        }
        return Math.round(n * 100 + 1e-8) / 100;
    }

    function defaultLabel() {
        return typeof global.tncPurchaseRetentionLabelDefault === 'function'
            ? global.tncPurchaseRetentionLabelDefault()
            : 'หักประกันผลงาน Retention';
    }

    function normalizePctBase(raw) {
        return String(raw || '') === 'after_vat' ? 'after_vat' : 'before_vat';
    }

    function parseAmount(raw, baseAmount) {
        if (typeof global.tncPurchaseParseRetention === 'function') {
            return global.tncPurchaseParseRetention(raw, baseAmount);
        }
        raw = String(raw || '').trim().replace(/,/g, '').replace(/\s/g, '');
        if (!raw || raw === '0') {
            return { amount: 0, value_type: 'fixed' };
        }
        if (raw.indexOf('%') !== -1) {
            var pct = parseFloat(raw.replace('%', ''));
            if (!isFinite(pct) || pct <= 0) {
                return { amount: 0, value_type: 'percent' };
            }
            pct = Math.min(100, pct);
            return {
                amount: money2(Math.max(0, Number(baseAmount) || 0) * pct / 100),
                value_type: 'percent'
            };
        }
        var fixed = money2(parseFloat(raw));
        return { amount: isFinite(fixed) && fixed > 0 ? fixed : 0, value_type: 'fixed' };
    }

    function collectLines() {
        var rows = document.querySelectorAll('#po_adjustments_rows .po-adjustment-row');
        var items = [];
        rows.forEach(function (row) {
            var signEl = row.querySelector('.po-adj-sign');
            var labelEl = row.querySelector('.po-adj-label');
            var baseEl = row.querySelector('.po-adj-pct-base');
            var inputEl = row.querySelector('.po-adj-input');
            var sign = signEl && signEl.value === 'add' ? 'add' : 'subtract';
            var label = labelEl ? String(labelEl.value || '').trim() : '';
            var input = inputEl ? String(inputEl.value || '').trim() : '';
            var pctBase = normalizePctBase(baseEl ? baseEl.value : 'before_vat');
            if (input === '' || input === '0') {
                return;
            }
            items.push({
                sign: sign,
                label: label !== '' ? label : defaultLabel(),
                input: input,
                pct_base: pctBase
            });
        });
        return items;
    }

    function applyToTotals(gross, subtotal) {
        var lines = collectLines();
        var delta = 0;
        var parsed = [];
        lines.forEach(function (line) {
            var baseAmount = line.pct_base === 'after_vat' ? gross : subtotal;
            var parsedAmount = parseAmount(line.input, baseAmount);
            var amt = parsedAmount.amount || 0;
            if (amt <= 0) {
                return;
            }
            parsed.push({
                sign: line.sign,
                label: line.label,
                input: line.input,
                pct_base: line.pct_base,
                value_type: parsedAmount.value_type || (String(line.input).indexOf('%') !== -1 ? 'percent' : 'fixed'),
                amount: amt
            });
            delta += line.sign === 'add' ? amt : -amt;
        });
        var net = money2((Number(gross) || 0) + delta);
        if (net < 0) {
            net = 0;
        }
        return { items: parsed, delta: money2(delta), net: net };
    }

    function renderSummary(items) {
        var host = document.getElementById('po_adjustments_summary');
        if (!host) {
            return;
        }
        host.innerHTML = '';
        items.forEach(function (item) {
            if (!item || item.amount <= 0) {
                return;
            }
            var row = document.createElement('div');
            row.className = 'summary-line small ' + (item.sign === 'add' ? 'text-success is-add' : 'text-danger is-sub');
            var label = document.createElement('span');
            label.className = 'summary-label';
            label.textContent = item.label || defaultLabel();
            var value = document.createElement('strong');
            value.className = 'summary-value text-end';
            var prefix = item.sign === 'add' ? '+' : '−';
            value.textContent = prefix + item.amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท';
            row.appendChild(label);
            row.appendChild(value);
            host.appendChild(row);
        });
    }

    function syncPctBaseUi(row) {
        var inputEl = row ? row.querySelector('.po-adj-input') : null;
        var baseEl = row ? row.querySelector('.po-adj-pct-base') : null;
        if (!inputEl || !baseEl) {
            return;
        }
        var isPercent = String(inputEl.value || '').indexOf('%') !== -1;
        baseEl.disabled = !isPercent;
        baseEl.classList.toggle('is-disabled', !isPercent);
    }

    function bindRow(row) {
        if (!row || row.dataset.bound === '1') {
            return;
        }
        row.dataset.bound = '1';
        syncPctBaseUi(row);
        row.querySelectorAll('.po-adj-sign, .po-adj-label, .po-adj-pct-base, .po-adj-input').forEach(function (el) {
            el.addEventListener('input', function () {
                syncPctBaseUi(row);
                if (typeof global.calculateTotal === 'function') {
                    global.calculateTotal();
                } else if (typeof global.calculate === 'function') {
                    global.calculate();
                }
            });
            el.addEventListener('change', function () {
                syncPctBaseUi(row);
                if (typeof global.calculateTotal === 'function') {
                    global.calculateTotal();
                } else if (typeof global.calculate === 'function') {
                    global.calculate();
                }
            });
        });
        var removeBtn = row.querySelector('.po-adj-remove');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                var wrap = document.getElementById('po_adjustments_rows');
                if (!wrap) {
                    return;
                }
                if (wrap.querySelectorAll('.po-adjustment-row').length <= 1) {
                    row.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
                    var sign = row.querySelector('.po-adj-sign');
                    if (sign) {
                        sign.value = 'subtract';
                    }
                    var base = row.querySelector('.po-adj-pct-base');
                    if (base) {
                        base.value = 'before_vat';
                    }
                    syncPctBaseUi(row);
                } else {
                    row.remove();
                }
                if (typeof global.calculateTotal === 'function') {
                    global.calculateTotal();
                } else if (typeof global.calculate === 'function') {
                    global.calculate();
                }
            });
        }
    }

    function createRow(data) {
        data = data || {};
        var pctBase = normalizePctBase(data.pct_base || 'before_vat');
        var row = document.createElement('div');
        row.className = 'po-adjustment-row';
        row.innerHTML =
            '<select name="adjustment_sign[]" class="form-select form-select-sm po-adj-sign" aria-label="บวกหรือลบ">' +
                '<option value="subtract"' + ((data.sign || 'subtract') === 'subtract' ? ' selected' : '') + '>− ลบ</option>' +
                '<option value="add"' + (data.sign === 'add' ? ' selected' : '') + '>+ บวก</option>' +
            '</select>' +
            '<input type="text" name="adjustment_label[]" class="form-control form-control-sm po-adj-label" maxlength="120" placeholder="เช่น หักประกันผลงาน" value="' + String(data.label || '').replace(/"/g, '&quot;') + '" autocomplete="off">' +
            '<select name="adjustment_pct_base[]" class="form-select form-select-sm po-adj-pct-base" aria-label="ฐานคิดเปอร์เซ็นต์">' +
                '<option value="before_vat"' + (pctBase === 'before_vat' ? ' selected' : '') + '>ก่อน VAT</option>' +
                '<option value="after_vat"' + (pctBase === 'after_vat' ? ' selected' : '') + '>หลัง VAT</option>' +
            '</select>' +
            '<input type="text" name="adjustment_input[]" class="form-control form-control-sm po-adj-input text-end" maxlength="20" inputmode="decimal" placeholder="500 หรือ 5%" value="' + String(data.input || '').replace(/"/g, '&quot;') + '" autocomplete="off">' +
            '<button type="button" class="btn btn-sm btn-outline-danger po-adj-remove" title="ลบรายการ" aria-label="ลบรายการ"><i class="bi bi-trash3" aria-hidden="true"></i></button>';
        bindRow(row);
        return row;
    }

    function init() {
        var wrap = document.getElementById('po_adjustments_rows');
        if (!wrap) {
            return;
        }
        wrap.querySelectorAll('.po-adjustment-row').forEach(bindRow);
        var addBtn = document.getElementById('po_adjustment_add');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                wrap.appendChild(createRow({ sign: 'subtract', label: '', input: '', pct_base: 'before_vat' }));
                if (typeof global.calculateTotal === 'function') {
                    global.calculateTotal();
                } else if (typeof global.calculate === 'function') {
                    global.calculate();
                }
            });
        }
    }

    global.tncPurchaseCollectAdjustments = collectLines;
    global.tncPurchaseApplyAdjustmentsToTotals = applyToTotals;
    global.tncPurchaseRenderAdjustmentsSummary = renderSummary;
    global.tncPurchaseInitPoAdjustments = init;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(typeof window !== 'undefined' ? window : globalThis);
