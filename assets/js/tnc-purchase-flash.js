/**
 * Purchase module: ล้าง query string หลังแสดง flash แล้ว (กัน refresh ซ้ำ)
 */
(function () {
    'use strict';

    function isPurchasePage() {
        return /\/pages\/purchase\//.test(window.location.pathname || '');
    }

    var FLASH_KEYS = [
        'success', 'updated', 'deleted', 'cancelled', 'error', 'payment_saved', 'billing_saved',
        'created', 'web_approved', 'web_rejected', 'line_notify', 'po_number',
        'print_po_id', 'payment_slips_updated', 'payment_reverted', 'po_ignored', 'po_unignored',
        'pr_updated', 'message', 'auto_bill', 'bill_month', 'bill_id'
    ];

    function onReady() {
        if (!isPurchasePage() || !window.location.search) {
            return;
        }
        if (!document.querySelector('[data-tnc-purchase-flash]')) {
            return;
        }
        var params = new URLSearchParams(window.location.search);
        var changed = false;
        FLASH_KEYS.forEach(function (key) {
            if (params.has(key)) {
                params.delete(key);
                changed = true;
            }
        });
        if (!changed) {
            return;
        }
        var qs = params.toString();
        var next = window.location.pathname + (qs ? '?' + qs : '') + (window.location.hash || '');
        window.history.replaceState(null, '', next);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
