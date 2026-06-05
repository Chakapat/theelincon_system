/**
 * เปิด/ปิดเสียงในระบบ — สวิตช์เดียวควบคุมทั้งแจ้งเตือน (กระดิ่ง) และเสียง PR/PO
 */
(function () {
    'use strict';

    var KEY_SYSTEM = 'tnc_system_audio_muted';
    var KEY_NOTIF_LEGACY = 'tnc_notif_audio_muted';
    var KEY_PR_PO_LEGACY = 'tnc_pr_po_audio_muted';

    function isMuted() {
        try {
            var v = localStorage.getItem(KEY_SYSTEM);
            if (v === '1') {
                return true;
            }
            if (v === '0') {
                return false;
            }
            return localStorage.getItem(KEY_NOTIF_LEGACY) === '1'
                || localStorage.getItem(KEY_PR_PO_LEGACY) === '1';
        } catch (e) {
            return false;
        }
    }

    function setMuted(muted) {
        try {
            if (muted) {
                localStorage.setItem(KEY_SYSTEM, '1');
            } else {
                localStorage.removeItem(KEY_SYSTEM);
            }
            localStorage.removeItem(KEY_NOTIF_LEGACY);
            localStorage.removeItem(KEY_PR_PO_LEGACY);
        } catch (e) {}
        syncNavbarToggles();
    }

    function dispatch(muted) {
        window.dispatchEvent(new CustomEvent('tnc:sound-settings-changed', {
            detail: { type: 'all', muted: muted }
        }));
    }

    function syncNavbarToggles() {
        var el = document.getElementById('tncSoundToggle');
        if (el) {
            el.checked = !isMuted();
        }
    }

    function initNavbar() {
        var el = document.getElementById('tncSoundToggle');
        if (!el) {
            return;
        }
        el.checked = !isMuted();
        el.addEventListener('change', function () {
            var enabled = !!el.checked;
            setMuted(!enabled);
            dispatch(!enabled);
        });
        el.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }

    window.TncSoundSettings = {
        isMuted: isMuted,
        setMuted: setMuted,
        isNotifMuted: isMuted,
        setNotifMuted: setMuted,
        isPrPoMuted: isMuted,
        setPrPoMuted: setMuted,
        syncNavbarToggles: syncNavbarToggles
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavbar);
    } else {
        initNavbar();
    }
})();
