<?php

declare(strict_types=1);

/**
 * Modal จัดการหลักฐานการจ่าย PO — ดู/เพิ่ม/ลบ/เปลี่ยนสลิป (หลายไฟล์)
 * ต้องมี window.tncPoFetchActionRow (จาก purchase-order-list.php) หรือกำหนด fetch เอง
 */
$poSlipActionBase = app_path('actions/action-handler.php');
$poSlipDefaultReturnTo = (string) ($poSlipDefaultReturnTo ?? 'list');
?>
<div class="modal fade" id="poSlipManageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0">
                    หลักฐานการจ่าย: <span id="poSlipPoNumber">-</span>
                    <span class="badge rounded-pill text-bg-secondary ms-2 d-none" id="poSlipCountBadge">0 ไฟล์</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div id="poSlipPayMeta" class="text-start small text-muted mb-3 d-none"></div>

                <div id="poSlipThumbNav" class="d-flex flex-wrap gap-2 mb-3 d-none" role="tablist" aria-label="เลือกไฟล์หลักฐาน"></div>

                <div id="poSlipViewerWrap" class="text-center mb-3">
                    <img id="poSlipViewerImage" src="" alt="หลักฐานการจ่ายเงิน" class="img-fluid rounded border d-none" style="max-height:52vh; object-fit:contain;">
                    <div id="poSlipViewerPdf" class="text-muted py-4 d-none">
                        <i class="bi bi-file-earmark-pdf fs-1 text-danger d-block mb-2"></i>
                        ไฟล์นี้เป็น PDF — ใช้ปุ่ม «เปิดไฟล์เต็ม» เพื่อดู
                    </div>
                    <div id="poSlipViewerEmpty" class="text-muted py-4 d-none">ไม่พบไฟล์หลักฐานการจ่ายเงิน</div>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-center mb-3">
                    <a id="poSlipOpenLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-orange btn-sm rounded-pill px-3 d-none">
                        <i class="bi bi-box-arrow-up-right me-1"></i>เปิดไฟล์เต็ม
                    </a>
                </div>

                <div id="poSlipManageForms" class="border-top pt-3">
                    <form
                        action="<?= htmlspecialchars($poSlipActionBase, ENT_QUOTES, 'UTF-8') ?>?action=add_po_payment_slips"
                        method="POST"
                        enctype="multipart/form-data"
                        id="poSlipAddForm"
                        class="mb-3"
                    >
                        <?php csrf_field(); ?>
                        <input type="hidden" name="po_id" id="poSlipAddPoId" value="">
                        <input type="hidden" name="return_to" id="poSlipAddReturnTo" value="<?= htmlspecialchars($poSlipDefaultReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                        <label class="form-label fw-semibold mb-1" for="poSlipAddFiles">
                            <i class="bi bi-plus-circle me-1"></i>เพิ่มสลิป
                        </label>
                        <input type="file" name="payment_slips[]" id="poSlipAddFiles" class="form-control form-control-sm mb-2" accept="image/*,.pdf" multiple required>
                        <button type="submit" class="btn btn-orange btn-sm rounded-pill px-3">
                            <i class="bi bi-upload me-1"></i>เพิ่มไฟล์
                        </button>
                    </form>

                    <form
                        action="<?= htmlspecialchars($poSlipActionBase, ENT_QUOTES, 'UTF-8') ?>?action=replace_po_payment_slip"
                        method="POST"
                        enctype="multipart/form-data"
                        id="poSlipReplaceForm"
                        class="mb-3 d-none"
                        onsubmit="return confirm('ยืนยันเปลี่ยนไฟล์หลักฐานรายการนี้? ไฟล์เดิมจะถูกแทนที่');"
                    >
                        <?php csrf_field(); ?>
                        <input type="hidden" name="po_id" id="poSlipReplacePoId" value="">
                        <input type="hidden" name="slip_path" id="poSlipReplacePath" value="">
                        <input type="hidden" name="return_to" id="poSlipReplaceReturnTo" value="<?= htmlspecialchars($poSlipDefaultReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                        <label class="form-label fw-semibold mb-1" for="poSlipReplaceFile">
                            <i class="bi bi-arrow-repeat me-1"></i>เปลี่ยนไฟล์ที่เลือก
                        </label>
                        <input type="file" name="payment_slip" id="poSlipReplaceFile" class="form-control form-control-sm mb-2" accept="image/*,.pdf" required>
                        <button type="submit" class="btn btn-warning btn-sm rounded-pill px-3">
                            <i class="bi bi-arrow-repeat me-1"></i>บันทึกไฟล์ใหม่
                        </button>
                    </form>

                    <form
                        action="<?= htmlspecialchars($poSlipActionBase, ENT_QUOTES, 'UTF-8') ?>?action=remove_po_payment_slip"
                        method="POST"
                        id="poSlipRemoveForm"
                        class="d-none"
                        onsubmit="return confirm('ยืนยันลบไฟล์หลักฐานรายการนี้?');"
                    >
                        <?php csrf_field(); ?>
                        <input type="hidden" name="po_id" id="poSlipRemovePoId" value="">
                        <input type="hidden" name="slip_path" id="poSlipRemovePath" value="">
                        <input type="hidden" name="return_to" id="poSlipRemoveReturnTo" value="<?= htmlspecialchars($poSlipDefaultReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                            <i class="bi bi-trash me-1"></i>ลบไฟล์ที่เลือก
                        </button>
                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const modalEl = document.getElementById('poSlipManageModal');
    if (!modalEl || typeof bootstrap === 'undefined') {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const poNumberEl = document.getElementById('poSlipPoNumber');
    const countBadgeEl = document.getElementById('poSlipCountBadge');
    const payMetaEl = document.getElementById('poSlipPayMeta');
    const thumbNavEl = document.getElementById('poSlipThumbNav');
    const viewerImageEl = document.getElementById('poSlipViewerImage');
    const viewerPdfEl = document.getElementById('poSlipViewerPdf');
    const viewerEmptyEl = document.getElementById('poSlipViewerEmpty');
    const openLinkEl = document.getElementById('poSlipOpenLink');
    const replaceFormEl = document.getElementById('poSlipReplaceForm');
    const removeFormEl = document.getElementById('poSlipRemoveForm');
    const addFormEl = document.getElementById('poSlipAddForm');
    const addPoIdEl = document.getElementById('poSlipAddPoId');
    const replacePoIdEl = document.getElementById('poSlipReplacePoId');
    const replacePathEl = document.getElementById('poSlipReplacePath');
    const removePoIdEl = document.getElementById('poSlipRemovePoId');
    const removePathEl = document.getElementById('poSlipRemovePath');
    const addFilesEl = document.getElementById('poSlipAddFiles');
    const replaceFileEl = document.getElementById('poSlipReplaceFile');

    let slipItems = [];
    let activeIndex = 0;
    let currentPoId = '';
    let currentReturnTo = <?= json_encode($poSlipDefaultReturnTo, JSON_UNESCAPED_UNICODE) ?>;

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setReturnTo(value) {
        currentReturnTo = value || 'list';
        ['poSlipAddReturnTo', 'poSlipReplaceReturnTo', 'poSlipRemoveReturnTo'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) {
                el.value = currentReturnTo;
            }
        });
    }

    function renderSlip(index) {
        if (!slipItems.length) {
            activeIndex = 0;
            if (viewerImageEl) {
                viewerImageEl.src = '';
                viewerImageEl.classList.add('d-none');
            }
            viewerPdfEl?.classList.add('d-none');
            viewerEmptyEl?.classList.remove('d-none');
            openLinkEl?.classList.add('d-none');
            replaceFormEl?.classList.add('d-none');
            removeFormEl?.classList.add('d-none');
            thumbNavEl?.classList.add('d-none');
            return;
        }

        if (index < 0 || index >= slipItems.length) {
            index = 0;
        }
        activeIndex = index;
        const slip = slipItems[index];
        const slipUrl = slip.url || '';
        const slipPath = slip.path || '';
        const isPdf = !!slip.is_pdf;

        if (thumbNavEl) {
            thumbNavEl.innerHTML = slipItems.map(function (item, i) {
                const label = (item.is_pdf ? 'PDF ' : 'รูป ') + (i + 1);
                const active = i === activeIndex ? ' active' : '';
                return '<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill po-slip-thumb' + active + '" data-slip-index="' + i + '">' + escHtml(label) + '</button>';
            }).join('');
            thumbNavEl.classList.remove('d-none');
            thumbNavEl.querySelectorAll('.po-slip-thumb').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    renderSlip(parseInt(btn.getAttribute('data-slip-index') || '0', 10) || 0);
                });
            });
        }

        viewerEmptyEl?.classList.add('d-none');
        if (slipUrl !== '') {
            if (openLinkEl) {
                openLinkEl.href = slipUrl;
                openLinkEl.classList.remove('d-none');
            }
            if (isPdf) {
                viewerImageEl?.classList.add('d-none');
                viewerPdfEl?.classList.remove('d-none');
            } else {
                if (viewerImageEl) {
                    viewerImageEl.src = slipUrl;
                    viewerImageEl.classList.remove('d-none');
                }
                viewerPdfEl?.classList.add('d-none');
            }
        } else {
            viewerImageEl?.classList.add('d-none');
            viewerPdfEl?.classList.add('d-none');
            viewerEmptyEl?.classList.remove('d-none');
            openLinkEl?.classList.add('d-none');
        }

        if (replacePoIdEl) replacePoIdEl.value = currentPoId;
        if (replacePathEl) replacePathEl.value = slipPath;
        if (removePoIdEl) removePoIdEl.value = currentPoId;
        if (removePathEl) removePathEl.value = slipPath;
        if (replaceFileEl) replaceFileEl.value = '';
        replaceFormEl?.classList.remove('d-none');
        removeFormEl?.classList.remove('d-none');
    }

    function renderModal(row) {
        slipItems = Array.isArray(row.slip_items) ? row.slip_items.slice() : [];
        currentPoId = String(row.id || '');
        if (poNumberEl) {
            poNumberEl.textContent = row.po_number || '-';
        }
        if (countBadgeEl) {
            if (slipItems.length > 0) {
                countBadgeEl.textContent = slipItems.length + ' ไฟล์';
                countBadgeEl.classList.remove('d-none');
            } else {
                countBadgeEl.classList.add('d-none');
            }
        }
        if (addPoIdEl) addPoIdEl.value = currentPoId;
        if (addFilesEl) addFilesEl.value = '';

        const pm = (row.payment_method || 'transfer').toLowerCase();
        const paidBy = row.payment_cash_paid_by || '';
        if (payMetaEl) {
            if (pm === 'cash') {
                const extra = paidBy.trim() !== '' ? (' · <strong>จ่ายโดย:</strong> ' + escHtml(paidBy)) : '';
                payMetaEl.innerHTML = '<strong>ชำระ:</strong> เงินสด' + extra;
            } else {
                payMetaEl.innerHTML = '<strong>ชำระ:</strong> โอน';
            }
            payMetaEl.classList.remove('d-none');
        }

        renderSlip(0);
        modal.show();
    }

    window.tncOpenPoSlipModal = function (poId, options) {
        options = options || {};
        if (options.returnTo) {
            setReturnTo(options.returnTo);
        }
        const fetchRow = typeof window.tncPoFetchActionRow === 'function'
            ? window.tncPoFetchActionRow
            : null;
        if (!fetchRow) {
            alert('ไม่พร้อมโหลดข้อมูล PO');
            return;
        }
        if (typeof window.tncPoShowWait === 'function') {
            window.tncPoShowWait('กำลังโหลดหลักฐาน…', 'กรุณารอสักครู่');
        }
        fetchRow(poId)
            .then(function (row) {
                renderModal(row);
            })
            .catch(function () {
                alert('โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่');
            })
            .finally(function () {
                if (!window.__tncPoReloading && typeof window.tncPoHideWait === 'function') {
                    window.tncPoHideWait();
                }
            });
    };

    document.addEventListener('click', function (e) {
        const showBtn = e.target.closest('.js-show-slip');
        if (showBtn) {
            e.preventDefault();
            window.tncOpenPoSlipModal(showBtn.getAttribute('data-po-id') || '', { returnTo: 'list' });
            return;
        }
        const manageBtn = e.target.closest('.js-manage-slips');
        if (manageBtn) {
            e.preventDefault();
            window.tncOpenPoSlipModal(manageBtn.getAttribute('data-po-id') || '', {
                returnTo: manageBtn.getAttribute('data-return-to') || 'view',
            });
        }
    });
})();
</script>
