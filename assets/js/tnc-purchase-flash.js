/**
 * One-shot flash URL cleanup (ทั้งระบบ)
 * - อ่าน feedback จาก window.__TNC_FLASH_Q__ (session hydrate) หรือ query string
 * - ล้าง ephemeral keys ออกจาก address bar หลังแสดงแล้ว (กัน copy URL / fallback)
 */
(function () {
    'use strict';

    var FLASH_KEYS = [
        'success', 'updated', 'deleted', 'cancelled', 'error', 'err', 'payment_saved', 'billing_saved',
        'created', 'web_approved', 'web_rejected', 'approved', 'rejected', 'line_notify', 'line_error',
        'po_number', 'print_po_id', 'payment_slips_updated', 'payment_reverted', 'po_ignored', 'po_unignored',
        'pr_updated', 'invoice_updated', 'message', 'auto_bill', 'bill_month', 'bill_id',
        'product_added', 'cat_saved', 'cat_deleted', 'cat_remapped', 'cat_remap_partial',
        'name_updated', 'saved', 'exceeds_pr', 'prs', 'pos', 'failed'
    ];

    function flashObject() {
        var q = window.__TNC_FLASH_Q__;
        return q && typeof q === 'object' ? q : {};
    }

    window.tncFlashSearchParams = function tncFlashSearchParams() {
        var params = new URLSearchParams(window.location.search || '');
        var bag = flashObject();
        Object.keys(bag).forEach(function (key) {
            if (bag[key] == null || bag[key] === '') {
                return;
            }
            params.set(key, String(bag[key]));
        });
        return params;
    };

    function stripFlashFromUrl() {
        if (!window.history || typeof window.history.replaceState !== 'function') {
            return;
        }
        var params = new URLSearchParams(window.location.search || '');
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

    function onReady() {
        stripFlashFromUrl();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
