/**
 * VAT 7% สำหรับ PR/PO จัดซื้อ
 * - รวม: ยอดรายการ = round(ผลรวมแถว × 100 ÷ 107, 2) → ยอดสุทธิ = round(ยอดรายการ × 1.07, 2)
 * - แยก: ยอดรายการ = ผลรวมแถว → VAT = round(ยอด × 7%, 2) → ยอดสุทธิ = round(ยอด × 1.07, 2)
 */
(function (global) {
    'use strict';

    function money2(n) {
        return Math.round(n * 100) / 100;
    }

    function tncPurchaseVatFromLineSum(lineSum, vatOn, vatMode) {
        lineSum = money2(parseFloat(lineSum) || 0);
        vatMode = vatMode === 'inclusive' ? 'inclusive' : 'exclusive';
        let subtotal = lineSum;
        let vat = 0;
        let gross = lineSum;

        if (vatOn && lineSum > 0) {
            if (vatMode === 'inclusive') {
                subtotal = money2((lineSum * 100) / 107);
                gross = money2(subtotal * 1.07);
                vat = money2(gross - subtotal);
            } else {
                subtotal = lineSum;
                vat = money2(subtotal * 0.07);
                gross = money2(subtotal * 1.07);
            }
        }

        return {
            subtotal: subtotal,
            vat: vat,
            gross: gross,
            net: gross,
            lineSum: lineSum,
        };
    }

    global.tncPurchaseVatFromLineSum = tncPurchaseVatFromLineSum;
    global.tncPurchaseMoney2 = money2;
})(typeof window !== 'undefined' ? window : globalThis);
