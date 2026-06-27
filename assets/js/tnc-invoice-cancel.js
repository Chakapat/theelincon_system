/**
 * ยกเลิก Invoice / Tax Invoice — ฟอร์ม POST พร้อมเหตุผล (SweetAlert2)
 */
(function () {
    'use strict';

    function getCsrfToken() {
        if (typeof window.tncCsrfToken === 'string' && window.tncCsrfToken !== '') {
            return window.tncCsrfToken;
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            return meta.getAttribute('content') || '';
        }
        return '';
    }

    function submitCancelForm(action, fields) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = action;
        form.style.display = 'none';

        Object.keys(fields).forEach(function (key) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        });

        var csrf = getCsrfToken();
        if (csrf !== '') {
            var csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_csrf';
            csrfInput.value = csrf;
            form.appendChild(csrfInput);
        }

        document.body.appendChild(form);
        form.submit();
    }

    async function promptCancelReason(options) {
        if (typeof Swal === 'undefined') {
            var reasonFallback = window.prompt(options.promptText || 'กรุณาระบุเหตุผลการยกเลิก');
            if (reasonFallback === null) {
                return null;
            }
            reasonFallback = String(reasonFallback).trim();
            if (reasonFallback === '') {
                window.alert('กรุณาระบุเหตุผลการยกเลิก');
                return null;
            }
            return reasonFallback;
        }

        var result = await Swal.fire({
            title: options.title || 'ยืนยันยกเลิกเอกสาร',
            input: 'textarea',
            inputLabel: 'เหตุผลการยกเลิก',
            inputPlaceholder: 'ระบุเหตุผล…',
            inputAttributes: {
                maxlength: '500',
                rows: '4',
                'aria-label': 'เหตุผลการยกเลิก'
            },
            text: options.text || 'สถานะจะเปลี่ยนเป็น ยกเลิก และจะแสดงประทับบนใบพิมพ์',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: options.confirmText || 'ยืนยันยกเลิก',
            cancelButtonText: 'ปิด',
            inputValidator: function (value) {
                if (!value || !String(value).trim()) {
                    return 'กรุณาระบุเหตุผลการยกเลิก';
                }
                return null;
            }
        });

        if (!result.isConfirmed) {
            return null;
        }

        return String(result.value || '').trim();
    }

    window.tncCancelInvoice = async function (invoiceId, returnTo) {
        var id = parseInt(String(invoiceId || '0'), 10);
        if (!id) {
            return;
        }
        var reason = await promptCancelReason({
            title: 'ยกเลิกใบแจ้งหนี้?',
            text: 'ใบกำกับภาษีที่เชื่อมโยง (ถ้ามี) จะถูกยกเลิกด้วย'
        });
        if (reason === null) {
            return;
        }
        var actionUrl = (typeof window.tncActionHandlerUrl === 'string' ? window.tncActionHandlerUrl : '') + '?action=cancel_invoice';
        var fields = {
            invoice_id: String(id),
            cancellation_reason: reason
        };
        if (returnTo) {
            fields.return_to = returnTo;
        }
        submitCancelForm(actionUrl, fields);
    };

    window.tncCancelTaxInvoice = async function (taxId, invoiceId, returnTo) {
        var tid = parseInt(String(taxId || '0'), 10);
        if (!tid) {
            return;
        }
        var reason = await promptCancelReason({
            title: 'ยกเลิกใบกำกับภาษี?'
        });
        if (reason === null) {
            return;
        }
        var actionUrl = (typeof window.tncActionHandlerUrl === 'string' ? window.tncActionHandlerUrl : '') + '?action=cancel_tax_invoice';
        var fields = {
            tax_id: String(tid),
            cancellation_reason: reason
        };
        if (invoiceId) {
            fields.invoice_id = String(invoiceId);
        }
        if (returnTo) {
            fields.return_to = returnTo;
        }
        submitCancelForm(actionUrl, fields);
    };

    document.addEventListener('click', function (ev) {
        var invBtn = ev.target.closest('[data-tnc-cancel-invoice]');
        if (invBtn) {
            ev.preventDefault();
            window.tncCancelInvoice(
                invBtn.getAttribute('data-invoice-id'),
                invBtn.getAttribute('data-return-to') || ''
            );
            return;
        }
        var taxBtn = ev.target.closest('[data-tnc-cancel-tax-invoice]');
        if (taxBtn) {
            ev.preventDefault();
            window.tncCancelTaxInvoice(
                taxBtn.getAttribute('data-tax-id'),
                taxBtn.getAttribute('data-invoice-id') || '',
                taxBtn.getAttribute('data-return-to') || ''
            );
        }
    });
})();
