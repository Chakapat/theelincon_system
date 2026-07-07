/**
 * VAT 7% สำหรับ PR/PO จัดซื้อ
 * - รายการ: checkbox "คิด VAT" ติ๊กถูก = คิด VAT (default), ปิด = ไม่คิด VAT
 * - รวม/แยก VAT คิดเฉพาะรายการที่ติ๊กคิด VAT
 */
(function (global) {
    'use strict';

    function money2(n) {
        return Math.round(n * 100) / 100;
    }

    function tncPurchaseVatFromLineSum(lineSum, vatOn, vatMode) {
        return tncPurchaseVatFromLineSums(lineSum, 0, vatOn, vatMode);
    }

    function tncPurchaseVatFromLineSums(taxableSum, exemptSum, vatOn, vatMode) {
        taxableSum = money2(parseFloat(taxableSum) || 0);
        exemptSum = money2(parseFloat(exemptSum) || 0);
        const lineSum = money2(taxableSum + exemptSum);
        vatMode = vatMode === 'inclusive' ? 'inclusive' : 'exclusive';

        if (!vatOn) {
            return {
                subtotal: lineSum,
                vat: 0,
                gross: lineSum,
                net: lineSum,
                lineSum: lineSum,
                taxableSum: taxableSum,
                exemptSum: exemptSum,
            };
        }

        let subtotal = taxableSum;
        let vat = 0;
        let gross = lineSum;

        if (taxableSum > 0) {
            if (vatMode === 'inclusive') {
                subtotal = money2((taxableSum * 100) / 107);
                gross = money2(subtotal * 1.07 + exemptSum);
                vat = money2(gross - exemptSum - subtotal);
            } else {
                subtotal = taxableSum;
                vat = money2(subtotal * 0.07);
                gross = money2(subtotal * 1.07 + exemptSum);
            }
        }

        return {
            subtotal: subtotal,
            vat: vat,
            gross: gross,
            net: gross,
            lineSum: lineSum,
            taxableSum: taxableSum,
            exemptSum: exemptSum,
        };
    }

    /** sync hidden item_vat_exempt[] จาก checkbox คิด VAT */
    function tncPurchaseSyncVatApplyHidden(checkbox) {
        const row = checkbox && checkbox.closest ? checkbox.closest('tr') : null;
        const hidden = row ? row.querySelector('.line-vat-exempt-val') : null;
        if (hidden && checkbox) {
            hidden.value = checkbox.checked ? '0' : '1';
        }
    }

    /** รายการนี้ไม่คิด VAT หรือไม่ */
    function tncPurchaseLineIsVatExempt(row) {
        if (!row) {
            return false;
        }
        const applyEl = row.querySelector('.line-vat-apply');
        if (applyEl) {
            return !applyEl.checked;
        }
        const hidden = row.querySelector('.line-vat-exempt-val');
        if (hidden) {
            return String(hidden.value) === '1';
        }
        const legacyEl = row.querySelector('.line-vat-exempt');
        if (legacyEl) {
            return legacyEl.checked;
        }
        return false;
    }

    /** แยกยอดรวมเป็น taxable / exempt จากแถวตาราง */
    function tncPurchaseSumLineVatBuckets(rows, lineTotalFn) {
        let taxableSum = 0;
        let exemptSum = 0;
        const list = rows ? Array.from(rows) : [];
        list.forEach(function (row) {
            if (!row || row.classList.contains('po-line-empty')) {
                return;
            }
            const applyEl = row.querySelector('.line-vat-apply');
            if (applyEl && typeof tncPurchaseSyncVatApplyHidden === 'function') {
                tncPurchaseSyncVatApplyHidden(applyEl);
            }
            const total = money2(lineTotalFn(row));
            if (tncPurchaseLineIsVatExempt(row)) {
                exemptSum += total;
            } else {
                taxableSum += total;
            }
        });
        return {
            taxableSum: money2(taxableSum),
            exemptSum: money2(exemptSum),
        };
    }

    global.tncPurchaseVatFromLineSum = tncPurchaseVatFromLineSum;
    global.tncPurchaseVatFromLineSums = tncPurchaseVatFromLineSums;
    global.tncPurchaseMoney2 = money2;
    global.tncPurchaseLineIsVatExempt = tncPurchaseLineIsVatExempt;
    global.tncPurchaseSyncVatApplyHidden = tncPurchaseSyncVatApplyHidden;
    global.tncPurchaseSumLineVatBuckets = tncPurchaseSumLineVatBuckets;
})(typeof window !== 'undefined' ? window : globalThis);
