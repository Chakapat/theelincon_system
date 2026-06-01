/**
 * เปิด/ปิดเสียงแยก: แจ้งเตือน (notification bell) vs PR/PO audio feedback.
 */
(function () {
    'use strict';

    var KEY_NOTIF = 'tnc_notif_audio_muted';
    var KEY_PR_PO = 'tnc_pr_po_audio_muted';

    function readMuted(key) {
        try {
            return localStorage.getItem(key) === '1';
        } catch (e) {
            return false;
        }
    }

    function writeMuted(key, muted) {
        try {
            if (muted) {
                localStorage.setItem(key, '1');
            } else {
                localStorage.removeItem(key);
            }
        } catch (e) {}
    }

    function dispatch(type, muted) {
        window.dispatchEvent(new CustomEvent('tnc:sound-settings-changed', {
            detail: { type: type, muted: muted }
        }));
    }

    function bindToggle(inputId, type, isMutedFn, setMutedFn) {
        var el = document.getElementById(inputId);
        if (!el) {
            return;
        }
        el.checked = !isMutedFn();
        el.addEventListener('change', function () {
            var enabled = !!el.checked;
            setMutedFn(!enabled);
            dispatch(type, !enabled);
        });
    }

    function syncNavbarToggles() {
        var notifEl = document.getElementById('tncSoundNotifToggle');
        var prPoEl = document.getElementById('tncSoundPrPoToggle');
        if (notifEl) {
            notifEl.checked = !isNotifMuted();
        }
        if (prPoEl) {
            prPoEl.checked = !isPrPoMuted();
        }
    }

    function isNotifMuted() {
        return readMuted(KEY_NOTIF);
    }

    function setNotifMuted(muted) {
        writeMuted(KEY_NOTIF, muted);
        syncNavbarToggles();
    }

    function isPrPoMuted() {
        return readMuted(KEY_PR_PO);
    }

    function setPrPoMuted(muted) {
        writeMuted(KEY_PR_PO, muted);
        syncNavbarToggles();
    }

    function initNavbar() {
        bindToggle('tncSoundNotifToggle', 'notif', isNotifMuted, setNotifMuted);
        bindToggle('tncSoundPrPoToggle', 'prPo', isPrPoMuted, setPrPoMuted);
        ['tncSoundNotifToggle', 'tncSoundPrPoToggle'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            }
        });
        syncNavbarToggles();
    }

    window.TncSoundSettings = {
        isNotifMuted: isNotifMuted,
        setNotifMuted: setNotifMuted,
        isPrPoMuted: isPrPoMuted,
        setPrPoMuted: setPrPoMuted,
        syncNavbarToggles: syncNavbarToggles
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavbar);
    } else {
        initNavbar();
    }
})();
