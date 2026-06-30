/**
 * Loading states สำหรับโมดูล Purchase — ข้อความ overlay, ปุ่ม submit, boot หน้ารายการ
 */
(function () {
    'use strict';

    var DEFAULT_SUB = 'กรุณารอสักครู่ อย่ากดซ้ำจนกว่าระบบจะตอบกลับ';

    var ACTION_MESSAGES = {
        save_pr: ['กำลังบันทึกใบขอซื้อ…', 'ระบบกำลังสร้าง PR'],
        update_pr: ['กำลังบันทึกการแก้ไข PR…', DEFAULT_SUB],
        create_po_direct: ['กำลังสร้างใบสั่งซื้อ…', DEFAULT_SUB],
        create_po_from_pr: ['กำลังสร้าง PO จาก PR…', DEFAULT_SUB],
        update_po_direct: ['กำลังบันทึก PO…', DEFAULT_SUB],
        cancel_purchase_order: ['กำลังยกเลิกเอกสาร…', DEFAULT_SUB],
        delete_pr: ['กำลังลบใบขอซื้อ…', DEFAULT_SUB],
        send_pr_line_approval: ['กำลังส่งไป LINE…', 'ระบบกำลังส่งคำขออนุมัติ'],
        update_po_payment_status: ['กำลังบันทึกสถานะการจ่าย…', DEFAULT_SUB],
        receive_po_bill: ['กำลังบันทึกเลขบิล…', DEFAULT_SUB],
        upload_po_payment_slip: ['กำลังอัปโหลดสลิป…', DEFAULT_SUB],
        add_po_payment_slips: ['กำลังแนบสลิป…', DEFAULT_SUB],
        replace_po_payment_slip: ['กำลังเปลี่ยนสลิป…', DEFAULT_SUB]
    };

    window.__tncPurchaseBoot = window.__tncPurchaseBoot || { table: false, sync: false };

    function isPurchasePage() {
        return /\/pages\/purchase\//.test(window.location.pathname || '');
    }

    function actionFromForm(form) {
        if (!form) {
            return '';
        }
        var actionUrl = String(form.getAttribute('action') || '');
        var match = actionUrl.match(/[?&]action=([^&]+)/i);
        if (match) {
            return decodeURIComponent(match[1]);
        }
        var hidden = form.querySelector('input[name="action"]');
        if (hidden && hidden.value) {
            return String(hidden.value).trim();
        }
        return '';
    }

    function resolveFormMessage(form) {
        if (!form || !isPurchasePage()) {
            return null;
        }
        var title = form.getAttribute('data-tnc-overlay-title');
        var sub = form.getAttribute('data-tnc-overlay-sub');
        if (title) {
            return { title: title, sub: sub || DEFAULT_SUB };
        }

        var action = actionFromForm(form);
        if (action === 'pr_web_decision') {
            var decision = form.querySelector('[name="decision"]');
            if (decision && decision.value === 'reject') {
                return ['กำลังบันทึกการไม่อนุมัติ…', DEFAULT_SUB];
            }
            return ['กำลังอนุมัติ PR…', DEFAULT_SUB];
        }

        var mapped = ACTION_MESSAGES[action];
        if (!mapped) {
            return null;
        }
        return { title: mapped[0], sub: mapped[1] };
    }

    function showOverlay(title, sub) {
        if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.showWithMessage === 'function') {
            window.TncLoadingOverlay.showWithMessage(title, sub);
        } else if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.show === 'function') {
            window.TncLoadingOverlay.show();
        }
    }

    function setSubmitButtonLoading(form, btn) {
        if (!form) {
            return;
        }
        var target = btn
            || form.querySelector('[type="submit"]:focus')
            || form.querySelector('[type="submit"]')
            || document.getElementById('btnPrSaveOpenModal');
        if (!target || target.classList.contains('tnc-is-loading')) {
            return;
        }
        if (!target.getAttribute('data-tnc-loading-label')) {
            target.setAttribute('data-tnc-loading-label', target.innerHTML);
        }
        target.classList.add('tnc-is-loading');
        target.setAttribute('aria-busy', 'true');
        if (target.tagName === 'BUTTON') {
            target.disabled = true;
        }
        var label = target.getAttribute('data-tnc-loading-text') || 'กำลังดำเนินการ…';
        if (target.classList.contains('btn')) {
            target.innerHTML = '<span class="tnc-btn-spinner" aria-hidden="true"></span>' + label;
        }
    }

    function submitWithOverlay(form, title, sub) {
        if (!form) {
            return;
        }
        var msg = title ? { title: title, sub: sub || DEFAULT_SUB } : resolveFormMessage(form);
        if (msg) {
            showOverlay(msg.title, msg.sub);
        } else {
            showOverlay('กำลังดำเนินการ…', DEFAULT_SUB);
        }
        setSubmitButtonLoading(form);
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }
        HTMLFormElement.prototype.submit.call(form);
    }

    function markBootReady(part) {
        var boot = window.__tncPurchaseBoot;
        if (!boot) {
            return;
        }
        boot[part] = true;
        tryPageReady();
    }

    function tryPageReady() {
        var boot = window.__tncPurchaseBoot;
        if (!document.body || !document.body.classList.contains('tnc-purchase-boot-lock')) {
            return;
        }
        if (!boot || !boot.table || !boot.sync) {
            return;
        }
        if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.pageReady === 'function') {
            window.TncLoadingOverlay.pageReady();
        }
    }

    function reloadWithWait(title, sub) {
        window.__tncPurchaseReloading = true;
        showOverlay(title || 'กำลังอัปเดตข้อมูล…', sub || 'พบข้อมูลเปลี่ยนแปลง กำลังโหลดหน้าใหม่…');
        window.location.reload();
    }

    function revealTable(bodyId, tableId, onReady) {
        if (window.TncTableSkeleton) {
            window.TncTableSkeleton.reveal(bodyId, tableId, function () {
                markBootReady('table');
                if (typeof onReady === 'function') {
                    onReady();
                }
            });
            return;
        }
        markBootReady('table');
        if (typeof onReady === 'function') {
            onReady();
        }
    }

    function patchNativeSubmit() {
        if (!isPurchasePage() || HTMLFormElement.prototype.__tncPurchaseSubmitPatched) {
            return;
        }
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            if (isPurchasePage() && this.getAttribute('data-tnc-no-overlay') !== '1') {
                var msg = resolveFormMessage(this);
                if (msg) {
                    showOverlay(msg.title, msg.sub);
                } else {
                    showOverlay('กำลังดำเนินการ…', DEFAULT_SUB);
                }
                setSubmitButtonLoading(this);
            }
            return nativeSubmit.call(this);
        };
        HTMLFormElement.prototype.__tncPurchaseSubmitPatched = true;
    }

    function initListBootSync(url) {
        if (!url) {
            markBootReady('sync');
            return;
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok && d.checksum) {
                    window.__tncMirrorChecksum = d.checksum;
                }
                markBootReady('sync');
            })
            .catch(function () {
                markBootReady('sync');
            });
    }

    function initNavLoading() {
        if (!isPurchasePage()) {
            return;
        }
        document.addEventListener('click', function (ev) {
            var link = ev.target.closest('a[data-tnc-nav-loading]');
            if (!link || ev.defaultPrevented) {
                return;
            }
            if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
                return;
            }
            if (link.getAttribute('target') === '_blank') {
                return;
            }
            var title = link.getAttribute('data-tnc-nav-loading-title') || 'กำลังเปิดหน้า…';
            var sub = link.getAttribute('data-tnc-nav-loading-sub') || DEFAULT_SUB;
            showOverlay(title, sub);
        }, false);
    }

    function initBootSync() {
        if (!document.body || !document.body.classList.contains('tnc-purchase-boot-lock')) {
            return;
        }
        var embedded = document.body.getAttribute('data-tnc-boot-checksum');
        if (embedded) {
            window.__tncMirrorChecksum = embedded;
            markBootReady('sync');
            return;
        }
        var autoSync = document.body.getAttribute('data-tnc-boot-sync-url');
        if (autoSync) {
            initListBootSync(autoSync);
            return;
        }
        markBootReady('sync');
    }

    function init() {
        patchNativeSubmit();
        initNavLoading();
        initBootSync();
    }

    window.TncPurchaseLoading = {
        resolveFormMessage: resolveFormMessage,
        setSubmitButtonLoading: setSubmitButtonLoading,
        submitWithOverlay: submitWithOverlay,
        showWait: showOverlay,
        reloadWithWait: reloadWithWait,
        revealTable: revealTable,
        markBootTableReady: function () { markBootReady('table'); },
        markBootSyncReady: function () { markBootReady('sync'); },
        tryPageReady: tryPageReady
    };

    window.tncPurchaseReloadWithWait = reloadWithWait;
    window.tncPurchaseTableReveal = function (bodyId, tableId, onReady) {
        var body = typeof bodyId === 'string' ? bodyId.replace(/^#/, '') : '';
        if (bodyId && bodyId.charAt && bodyId.charAt(0) === '#') {
            revealTable(body, (tableId || '').replace(/^#/, ''), onReady);
            return;
        }
        revealTable(bodyId, tableId, onReady);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
