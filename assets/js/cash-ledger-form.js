/**
 * ฟอร์มบันทึกสดย่อย — submit แยกจาก tnc-ajax-form.js ทั่วระบบ
 */
(function () {
    'use strict';

    function showLedgerMsg(icon, title, text) {
        if (typeof Swal === 'undefined') {
            window.alert(text || title);
            return Promise.resolve();
        }
        return Swal.fire({
            icon: icon,
            title: title,
            text: text || undefined,
            confirmButtonColor: '#ea580c',
        });
    }

    function validateLedgerForm(form) {
        var desc = form.querySelector('[name="description"]');
        var amount = form.querySelector('[name="amount"]');
        var entryDate = form.querySelector('[name="entry_date"]');
        var amountVal = amount ? parseFloat(String(amount.value || '').replace(/,/g, '')) : NaN;

        if (!desc || !String(desc.value || '').trim()) {
            return 'กรุณากรอกรายละเอียดการจ่าย/รับ';
        }
        if (!entryDate || !String(entryDate.value || '').trim()) {
            return 'กรุณาเลือกวันที่';
        }
        if (!Number.isFinite(amountVal) || amountVal <= 0) {
            return 'กรุณากรอกจำนวนเงินมากกว่า 0';
        }
        return '';
    }

    function bindLedgerForm(form) {
        if (!form || form.getAttribute('data-tnc-ledger-bound') === '1') {
            return;
        }
        form.setAttribute('data-tnc-ledger-bound', '1');
        form.setAttribute('data-tnc-fullnav', '1');

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();

            var collapse = document.getElementById('ledgerFormCollapse');
            if (collapse && !collapse.classList.contains('show')) {
                collapse.classList.add('show');
            }

            var err = validateLedgerForm(form);
            if (err) {
                showLedgerMsg('warning', 'กรอกข้อมูลไม่ครบ', err);
                return;
            }

            var fd = new FormData(form);
            fd.set('_tnc_ajax', '1');

            if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.show === 'function') {
                window.TncLoadingOverlay.show();
            }

            fetch(form.getAttribute('action') || '', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Tnc-Ajax': '1',
                    Accept: 'application/json',
                },
            })
                .then(function (res) {
                    var ct = res.headers.get('content-type') || '';
                    if (ct.indexOf('application/json') !== -1) {
                        return res.json().then(function (j) {
                            return { json: j };
                        });
                    }
                    return res.text().then(function (t) {
                        return { text: t };
                    });
                })
                .then(function (res) {
                    if (res.json) {
                        var j = res.json;
                        if (j.ok && j.url) {
                            window.location.href = j.url;
                            return;
                        }
                        showLedgerMsg(j.ok ? 'success' : 'error', j.message || (j.ok ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ')).then(function () {
                            if (j.url) {
                                window.location.href = j.url;
                            }
                        });
                        return;
                    }
                    showLedgerMsg('error', 'บันทึกไม่สำเร็จ', 'เซิร์ฟเวอร์ตอบกลับผิดปกติ — ลองรีเฟรชหน้าแล้วบันทึกใหม่');
                })
                .catch(function () {
                    showLedgerMsg('error', 'บันทึกไม่สำเร็จ', 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้');
                })
                .finally(function () {
                    if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.hide === 'function') {
                        window.TncLoadingOverlay.hide();
                    }
                });
        }, true);
    }

    function init() {
        bindLedgerForm(document.getElementById('ledgerForm'));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
