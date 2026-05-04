/**
 * ส่งฟอร์ม POST ไป action-handler แบบ AJAX — ไม่ redirect ไป URL อื่น
 * - เพิ่ม _tnc_ajax=1 อัตโนมัติ
 * - ค่าเริ่มต้น: สำเร็จแล้ว soft-reload หน้าเดิม (refresh รายการ) ยกเว้น form มี data-tnc-soft-reload="0"
 */
(function () {
    'use strict';

    function isAjaxBackendForm(form) {
        var a = (form.getAttribute('action') || '').toLowerCase();
        return (
            a.indexOf('action-handler.php') !== -1
            || a.indexOf('invoice-update.php') !== -1
            || a.indexOf('stock-handler.php') !== -1
            || a.indexOf('labor-payroll-handler.php') !== -1
            || a.indexOf('labor-payroll-archive-handler.php') !== -1
            || a.indexOf('cash-ledger-handler.php') !== -1
        );
    }

    function shouldBind(form) {
        if (form.id === 'payrollForm') return false;
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

    function showToast(ok, message) {
        if (typeof Swal === 'undefined') {
            if (ok) window.alert(message || 'สำเร็จ');
            else window.alert(message || 'ผิดพลาด');
            return;
        }
        Swal.fire({
            icon: ok ? 'success' : 'error',
            title: ok ? 'สำเร็จ' : 'ไม่สำเร็จ',
            text: message || '',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: ok ? 2200 : 4500,
            timerProgressBar: true
        });
    }

    function bindForm(form) {
        if (form.getAttribute('data-tnc-ajax-bound') === '1') return;
        form.setAttribute('data-tnc-ajax-bound', '1');

        form.addEventListener('submit', function (ev) {
            if (!shouldBind(form)) return;
            ev.preventDefault();

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
                        showToast(ok, msg);
                        window.dispatchEvent(new CustomEvent('tnc:form-ajax-success', { detail: j }));
                        if (ok) {
                            var modalEl = form.closest('.modal');
                            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                var mi = bootstrap.Modal.getInstance(modalEl);
                                if (mi) mi.hide();
                            }
                            if (softReloadDefault(form)) {
                                setTimeout(function () {
                                    window.location.reload();
                                }, 650);
                            }
                        }
                        return;
                    }
                    showToast(false, 'เซิร์ฟเวอร์ตอบกลับไม่ใช่ JSON');
                })
                .catch(function () {
                    showToast(false, 'เครือข่ายผิดพลาด');
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
