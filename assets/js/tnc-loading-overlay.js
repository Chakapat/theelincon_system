/**
 * Loading overlay ทั้งระบบ — กันผู้ใช้กดซ้ำระหว่างส่งฟอร์ม / CRUD
 * - POST form ที่ไม่ถูก cancel ด้วย preventDefault → แสดง overlay จนโหลดหน้าใหม่
 * - ฟอร์ม AJAX: ควบคุมด้วย tnc-ajax-form.js (เรียก show/hide)
 * - ลบแบบ POST จาก tnc-delete-confirm.js: เรียก show ก่อน form.submit()
 * ข้าม: method="get" หรือ data-tnc-no-overlay="1"
 */
(function () {
    'use strict';

    var ROOT_ID = 'tnc-global-loading-overlay';
    var refCount = 0;

    function injectStylesOnce() {
        if (document.getElementById('tnc-loading-overlay-style')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'tnc-loading-overlay-style';
        style.textContent = ''
            + '@keyframes tncLoSpin{to{transform:rotate(360deg)}}'
            + '#' + ROOT_ID + '{position:fixed;inset:0;z-index:200000;background:rgba(15,23,42,.45);'
            + 'backdrop-filter:saturate(130%) blur(8px);-webkit-backdrop-filter:saturate(130%) blur(8px);'
            + 'display:none;align-items:center;justify-content:center;padding:1.25rem;}'
            + '#' + ROOT_ID + '.tnc-lo-on{display:flex!important;}'
            + '#' + ROOT_ID + ' .tnc-lo-card{background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.22);'
            + 'padding:1.35rem 1.85rem;max-width:min(92vw,380px);text-align:center;}'
            + '#' + ROOT_ID + ' .tnc-lo-spinner{width:48px;height:48px;border-radius:50%;margin:0 auto .75rem;'
            + 'border:3px solid #e2e8f0;border-top-color:#0d6efd;animation:tncLoSpin .75s linear infinite;}'
            + '#' + ROOT_ID + ' .tnc-lo-title{font-weight:800;font-size:1.05rem;color:#0f172a;}'
            + '#' + ROOT_ID + ' .tnc-lo-sub{font-size:.875rem;color:#64748b;margin-top:.35rem;line-height:1.45;}'
            + 'body.tnc-lo-scroll-lock{overflow:hidden!important;}';
        document.head.appendChild(style);
    }

    function ensureRoot() {
        injectStylesOnce();
        var el = document.getElementById(ROOT_ID);
        if (el) {
            return el;
        }
        el = document.createElement('div');
        el.id = ROOT_ID;
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.setAttribute('aria-busy', 'true');
        el.innerHTML = ''
            + '<div class="tnc-lo-card">'
            + '<div class="tnc-lo-spinner" aria-hidden="true"></div>'
            + '<div class="tnc-lo-title">กำลังดำเนินการ…</div>'
            + '<div class="tnc-lo-sub">กรุณารอสักครู่ อย่ากดซ้ำจนกว่าระบบจะตอบกลับ</div>'
            + '</div>';
        document.body.appendChild(el);
        return el;
    }

    function applyVisible(on) {
        var el = document.getElementById(ROOT_ID);
        if (!el) {
            return;
        }
        if (on) {
            el.classList.add('tnc-lo-on');
            document.body.classList.add('tnc-lo-scroll-lock');
        } else {
            el.classList.remove('tnc-lo-on');
            document.body.classList.remove('tnc-lo-scroll-lock');
        }
    }

    function show() {
        ensureRoot();
        refCount += 1;
        if (refCount === 1) {
            applyVisible(true);
        }
    }

    function hide() {
        refCount = Math.max(0, refCount - 1);
        if (refCount === 0) {
            applyVisible(false);
        }
    }

    function forceHide() {
        refCount = 0;
        applyVisible(false);
    }

    window.TncLoadingOverlay = {
        show: show,
        hide: hide,
        forceHide: forceHide
    };

    /** หลังจบการ dispatch ทั้งหมด — ถ้าไม่มีใคร prevent แปลว่าจะ navigate จริง */
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        var method = String(form.getAttribute('method') || 'get').toLowerCase();
        if (method === 'get') {
            return;
        }
        if (form.getAttribute('data-tnc-no-overlay') === '1') {
            return;
        }

        var evRef = ev;
        window.setTimeout(function () {
            if (evRef.defaultPrevented) {
                return;
            }
            show();
        }, 0);
    }, false);

    window.addEventListener('pageshow', function () {
        forceHide();
    });
})();
