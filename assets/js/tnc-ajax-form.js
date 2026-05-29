/**
 * ส่งฟอร์ม POST ไป action-handler แบบ AJAX — ไม่ redirect ไป URL อื่น
 * - เพิ่ม _tnc_ajax=1 อัตโนมัติ
 * - ค่าเริ่มต้น: สำเร็จแล้ว soft-reload หน้าเดิม (refresh รายการ) ยกเว้น form มี data-tnc-soft-reload="0"
 */
(function () {
    'use strict';
    var toastStylesInjected = false;

    function ensureToastStyles() {
        if (toastStylesInjected) return;
        toastStylesInjected = true;
        var style = document.createElement('style');
        style.id = 'tnc-modern-toast-style';
        style.textContent = ''
            + '.swal2-container.swal2-top-end{padding:.9rem .85rem;}'
            + '.swal2-container.swal2-top-end>.swal2-popup.tnc-toast{margin:0 0 .62rem auto;}'
            + '.swal2-popup.tnc-toast{width:min(94vw,360px);border-radius:12px;padding:.76rem .86rem .7rem;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);box-shadow:0 12px 32px rgba(15,23,42,.18);border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.78)!important;color:#0f172a;overflow:hidden;}'
            + '.swal2-popup.tnc-toast .swal2-html-container{margin:0!important;padding:0!important;font-size:.94rem;}'
            + '.tnc-toast-body{display:flex;align-items:flex-start;gap:.62rem;line-height:1.38;}'
            + '.tnc-toast-icon{width:28px;height:28px;flex:0 0 28px;display:inline-flex;align-items:center;justify-content:center;}'
            + '.tnc-toast-icon svg{width:100%;height:100%;display:block;}'
            + '.tnc-toast-text{font-weight:800;letter-spacing:.01em;color:#0f172a;word-break:break-word;}'
            + '.swal2-popup.tnc-toast.tnc-toast-success{background:linear-gradient(180deg,rgba(236,253,245,.84),rgba(236,253,245,.72))!important;border-color:rgba(34,197,94,.34);}'
            + '.swal2-popup.tnc-toast.tnc-toast-update{background:linear-gradient(180deg,rgba(239,246,255,.84),rgba(239,246,255,.72))!important;border-color:rgba(59,130,246,.34);}'
            + '.swal2-popup.tnc-toast.tnc-toast-delete{background:linear-gradient(180deg,rgba(254,242,242,.86),rgba(254,242,242,.74))!important;border-color:rgba(239,68,68,.34);}'
            + '.swal2-popup.tnc-toast.tnc-toast-error{background:linear-gradient(180deg,rgba(255,241,242,.87),rgba(255,241,242,.74))!important;border-color:rgba(244,63,94,.34);}'
            + '.tnc-toast-progress{position:absolute;left:0;right:0;bottom:0;height:3px;opacity:.95;transform-origin:left center;animation:tncToastProgress linear forwards;}'
            + '.tnc-toast-success .tnc-toast-progress{background:#22c55e;}'
            + '.tnc-toast-update .tnc-toast-progress{background:#3b82f6;}'
            + '.tnc-toast-delete .tnc-toast-progress{background:#ef4444;}'
            + '.tnc-toast-error .tnc-toast-progress{background:#f43f5e;}'
            + '.tnc-anim-check{animation:tncToastBounce 1.1s ease-in-out infinite;}'
            + '.tnc-anim-info{animation:tncToastPulse 1.25s ease-in-out infinite;}'
            + '.tnc-toast-success .tnc-toast-icon{color:#16a34a;}'
            + '.tnc-toast-update .tnc-toast-icon{color:#2563eb;}'
            + '.tnc-toast-delete .tnc-toast-icon,.tnc-toast-error .tnc-toast-icon{color:#dc2626;}'
            + '.swal2-popup.tnc-toast.swal2-show{animation:tncToastSlideIn .28s ease-out both;}'
            + '.swal2-popup.tnc-toast.swal2-hide{animation:tncToastFadeOut .22s ease-in both;}'
            + '@keyframes tncToastSlideIn{from{opacity:0;transform:translate3d(16px,0,0)}to{opacity:1;transform:translate3d(0,0,0)}}'
            + '@keyframes tncToastFadeOut{from{opacity:1;transform:translate3d(0,0,0)}to{opacity:0;transform:translate3d(10px,0,0)}}'
            + '@keyframes tncToastProgress{from{transform:scaleX(1)}to{transform:scaleX(0)}}'
            + '@keyframes tncToastBounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}'
            + '@keyframes tncToastPulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.08);opacity:.86}}';
        document.head.appendChild(style);
    }

    function inferToastVariant(ok, message, action) {
        var m = String(message || '').toLowerCase();
        var a = String(action || '').toLowerCase();
        if (!ok) return 'error';
        if (a.indexOf('delete') !== -1 || m.indexOf('ลบ') !== -1) return 'delete';
        if (
            a.indexOf('update') !== -1
            || a.indexOf('edit') !== -1
            || m.indexOf('แก้ไข') !== -1
            || m.indexOf('อัปเดต') !== -1
            || m.indexOf('ปรับปรุง') !== -1
        ) return 'update';
        return 'success';
    }

    function toastSvg(variant) {
        if (variant === 'update') {
            return '<span class="tnc-toast-icon" aria-hidden="true"><svg class="tnc-anim-info" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"></circle><path d="M12 10v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path><circle cx="12" cy="7.2" r="1.2" fill="currentColor"></circle></svg></span>';
        }
        if (variant === 'delete' || variant === 'error') {
            return '<span class="tnc-toast-icon" aria-hidden="true"><svg class="tnc-anim-info" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"></circle><path d="M9 9l6 6M15 9l-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg></span>';
        }
        return '<span class="tnc-toast-icon" aria-hidden="true"><svg class="tnc-anim-check" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"></circle><path d="M8 12.5l2.6 2.6L16 9.6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></path></svg></span>';
    }

    function isAjaxBackendForm(form) {
        var a = (form.getAttribute('action') || '').toLowerCase();
        return (
            a.indexOf('action-handler.php') !== -1
            || a.indexOf('invoice-update.php') !== -1
            || a.indexOf('stock-handler.php') !== -1
            || a.indexOf('cash-ledger-handler.php') !== -1
        );
    }

    function shouldBind(form) {
        if (form.getAttribute('data-tnc-fullnav') === '1') return false;
        if (form.getAttribute('data-tnc-ajax') === '1' && String(form.getAttribute('method') || '').toLowerCase() === 'post') {
            return true;
        }
        if (form.getAttribute('method') && String(form.getAttribute('method')).toLowerCase() !== 'post') return false;
        return isAjaxBackendForm(form);
    }

    /** เปิด reload หน้าเดิมหลังสำเร็จ — ใส่ data-tnc-soft-reload="1" ที่ฟอร์มที่ต้องการรีเฟรชรายการ */
    function softReloadDefault(form) {
        if (form.getAttribute('data-tnc-soft-reload') === '0') return false;
        if (form.getAttribute('data-tnc-soft-reload') === '1') return true;
        var a = (form.getAttribute('action') || '').toLowerCase();
        if (a.indexOf('invoice-update.php') !== -1) return true;
        return false;
    }

    function showToast(ok, message, action) {
        if (typeof Swal === 'undefined') {
            if (ok) window.alert(message || 'สำเร็จ');
            else window.alert(message || 'ผิดพลาด');
            return;
        }
        ensureToastStyles();
        var variant = inferToastVariant(ok, message, action);
        var timer = ok ? 2600 : 4500;
        var html = ''
            + '<div class="tnc-toast-body">'
            + toastSvg(variant)
            + '<div class="tnc-toast-text">' + String(message || '') + '</div>'
            + '</div>'
            + '<div class="tnc-toast-progress" style="animation-duration:' + timer + 'ms"></div>';
        Swal.fire({
            toast: true,
            position: 'top-end',
            html: html,
            showConfirmButton: false,
            timer: timer,
            timerProgressBar: false,
            customClass: {
                popup: 'tnc-toast tnc-toast-' + variant
            },
            showClass: {
                popup: 'swal2-show'
            },
            hideClass: {
                popup: 'swal2-hide'
            }
        });
    }

    function bindForm(form) {
        if (form.getAttribute('data-tnc-ajax-bound') === '1') return;
        form.setAttribute('data-tnc-ajax-bound', '1');

        form.addEventListener('submit', function (ev) {
            if (!shouldBind(form)) return;
            ev.preventDefault();
            if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.show === 'function') {
                window.TncLoadingOverlay.show();
            }

            var fd = new FormData(form);
            fd.set('_tnc_ajax', '1');

            var action = form.getAttribute('action') || '';
            var submitBtn = form.querySelector('[type="submit"]:focus')
                || form.querySelector('[type="submit"]');
            if (submitBtn && submitBtn.getAttribute('name')) {
                fd.set(submitBtn.getAttribute('name'), submitBtn.getAttribute('value') || '');
            }

            fetch(action, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Tnc-Ajax': '1',
                    Accept: 'application/json'
                }
            })
                .then(function (r) {
                    var ct = r.headers.get('content-type') || '';
                    if (ct.indexOf('application/json') !== -1) {
                        return r.json().then(function (j) {
                            return { status: r.status, json: j };
                        });
                    }
                    return r.text().then(function (t) {
                        return { status: r.status, text: t };
                    });
                })
                .then(function (res) {
                    if (res.json) {
                        var j = res.json;
                        var ok = !!j.ok;
                        var msg = j.message || (ok ? 'บันทึกแล้ว' : 'ดำเนินการไม่สำเร็จ');
                        if (j.action === 'po_created' && j.po_number) {
                            msg = 'สร้าง PO สำเร็จ หมายเลข ' + j.po_number;
                        }
                        showToast(ok, msg, j.action || '');
                        window.dispatchEvent(new CustomEvent('tnc:form-ajax-success', { detail: j }));
                        if (ok) {
                            var modalEl = form.closest('.modal');
                            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                var mi = bootstrap.Modal.getInstance(modalEl);
                                if (mi) mi.hide();
                            }
                            var poCreatedUrl = (j.action === 'po_created' && (j.redirect || j.url)) ? (j.redirect || j.url) : null;
                            if (poCreatedUrl) {
                                setTimeout(function () {
                                    window.location.href = poCreatedUrl;
                                }, 800);
                            } else if (softReloadDefault(form)) {
                                setTimeout(function () {
                                    window.location.reload();
                                }, 650);
                            }
                        }
                        return;
                    }
                    showToast(false, 'เซิร์ฟเวอร์ตอบกลับไม่ใช่ JSON', '');
                })
                .catch(function () {
                    showToast(false, 'เครือข่ายผิดพลาด', '');
                })
                .finally(function () {
                    if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.hide === 'function') {
                        window.TncLoadingOverlay.hide();
                    }
                });
        });
    }

    function scan() {
        document.querySelectorAll('form').forEach(function (f) {
            if (shouldBind(f)) bindForm(f);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
})();
