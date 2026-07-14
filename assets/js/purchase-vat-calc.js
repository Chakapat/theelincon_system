/**
 * VAT 7% สำหรับ PR/PO จัดซื้อ
 * - รายการ: checkbox "คิด VAT" ติ๊กถูก = คิด VAT (default), ปิด = ไม่คิด VAT
 * - รวม/แยก VAT คิดเฉพาะรายการที่ติ๊กคิด VAT
 * - ปัดเงิน: ค่าเริ่มต้น 2 ทศนิยม (ดูตำแหน่งที่ 3) — ถ้าติ๊ก #round_to_baht จะปัดเต็มบาท
 */
(function (global) {
    'use strict';

    /** ปัด 2 ทศนิยม half-up */
    function money2(n) {
        n = Number(n);
        if (!Number.isFinite(n)) {
            return 0;
        }
        var sign = n < 0 ? -1 : 1;
        var abs = Math.abs(n);
        return sign * Math.round(abs * 100 + 1e-8) / 100;
    }

    /** ปัดใกล้จำนวนเต็มบาท */
    function moneyBaht(n) {
        n = Number(n);
        if (!Number.isFinite(n)) {
            return 0;
        }
        var sign = n < 0 ? -1 : 1;
        var abs = Math.abs(n);
        return sign * Math.round(abs + 1e-8);
    }

    function isRoundToBaht() {
        var el = document.getElementById('round_to_baht');
        if (!el) {
            return false;
        }
        if (el.type === 'checkbox' || el.type === 'radio') {
            return !!el.checked;
        }
        return String(el.value) === '1';
    }

    /** ปัดตามโหมดปัจจุบันบนฟอร์ม */
    function moneyApply(n) {
        return isRoundToBaht() ? moneyBaht(n) : money2(n);
    }

    function tncPurchaseVatFromLineSum(lineSum, vatOn, vatMode) {
        return tncPurchaseVatFromLineSums(lineSum, 0, vatOn, vatMode);
    }

    function tncPurchaseVatFromLineSums(taxableSum, exemptSum, vatOn, vatMode) {
        // ยอดบรรทัดอาจถูกปัดบาทมาแล้ว — คงไว้; ถอด VAT ใช้สตางค์เสมอ (แบบบิลปั๊ม)
        taxableSum = moneyApply(parseFloat(taxableSum) || 0);
        exemptSum = moneyApply(parseFloat(exemptSum) || 0);
        const lineSum = moneyApply(taxableSum + exemptSum);
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
                // รวม VAT: ยอดรวมเป็นหลัก → ถอดฐาน/VAT เป็นสตางค์
                gross = lineSum;
                subtotal = money2((taxableSum * 100) / 107);
                vat = money2(gross - exemptSum - subtotal);
                subtotal = money2(gross - exemptSum - vat);
            } else {
                subtotal = taxableSum;
                vat = money2(subtotal * 0.07);
                gross = money2(subtotal + vat + exemptSum);
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
            const total = moneyApply(lineTotalFn(row));
            if (tncPurchaseLineIsVatExempt(row)) {
                exemptSum += total;
            } else {
                taxableSum += total;
            }
        });
        return {
            taxableSum: moneyApply(taxableSum),
            exemptSum: moneyApply(exemptSum),
        };
    }

    global.tncPurchaseVatFromLineSum = tncPurchaseVatFromLineSum;
    global.tncPurchaseVatFromLineSums = tncPurchaseVatFromLineSums;
    global.tncPurchaseMoney2 = moneyApply;
    global.tncPurchaseMoneySatang = money2;
    global.tncPurchaseMoneyBaht = moneyBaht;
    global.tncPurchaseRoundToBahtEnabled = isRoundToBaht;
    global.tncPurchaseLineIsVatExempt = tncPurchaseLineIsVatExempt;
    global.tncPurchaseSyncVatApplyHidden = tncPurchaseSyncVatApplyHidden;
    global.tncPurchaseSumLineVatBuckets = tncPurchaseSumLineVatBuckets;
})(typeof window !== 'undefined' ? window : globalThis);
