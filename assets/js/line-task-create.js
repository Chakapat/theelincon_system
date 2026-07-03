(function () {
    'use strict';

    var assigneeEl = document.getElementById('assignee_id');
    var siteEl = document.getElementById('site_id');
    var detailsEl = document.getElementById('details');
    var dueDateEl = document.getElementById('due_date');
    var dueTimeEl = document.getElementById('due_time');
    var charCountEl = document.getElementById('details-char-count');
    var formEl = document.getElementById('task-form');
    var submitBtn = document.getElementById('btn-send-task');

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function fmtDateTh(dateVal, timeVal) {
        if (!dateVal) {
            return '—';
        }

        var raw = String(dateVal).trim();
        var thaiMatch = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (thaiMatch) {
            return pad2(thaiMatch[1]) + '/' + pad2(thaiMatch[2]) + '/' + thaiMatch[3] + ' ' + (timeVal || '17:00');
        }

        var isoParts = raw.split('-');
        if (isoParts.length === 3) {
            return pad2(isoParts[2]) + '/' + pad2(isoParts[1]) + '/' + isoParts[0] + ' ' + (timeVal || '17:00');
        }

        return raw;
    }

    function parseThaiDateParts(raw) {
        var value = String(raw || '').trim();
        var thaiMatch = value.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (thaiMatch) {
            return {
                day: Number(thaiMatch[1]),
                month: Number(thaiMatch[2]),
                year: Number(thaiMatch[3]),
            };
        }

        var isoMatch = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (isoMatch) {
            return {
                day: Number(isoMatch[3]),
                month: Number(isoMatch[2]),
                year: Number(isoMatch[1]),
            };
        }

        return null;
    }

    function normalizeDueDateForSubmit() {
        if (!dueDateEl) {
            return true;
        }

        var parts = parseThaiDateParts(dueDateEl.value);
        if (!parts) {
            alert('กรุณากรอกวันสิ้นสุดเป็นรูปแบบ วัน/เดือน/ปี เช่น 04/07/2026');
            dueDateEl.focus();
            return false;
        }

        var check = new Date(parts.year, parts.month - 1, parts.day);
        if (
            check.getFullYear() !== parts.year
            || check.getMonth() !== (parts.month - 1)
            || check.getDate() !== parts.day
        ) {
            alert('วันสิ้นสุดไม่ถูกต้อง กรุณาตรวจสอบใหม่');
            dueDateEl.focus();
            return false;
        }

        dueDateEl.value = parts.year + '-' + pad2(parts.month) + '-' + pad2(parts.day);
        return true;
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = value || '—';
        }
    }

    function selectedSiteName() {
        if (!siteEl) {
            return '—';
        }

        var siteId = String(siteEl.value || '').trim();
        if (siteId === '') {
            return '—';
        }

        var opt = siteEl.options[siteEl.selectedIndex];
        if (opt) {
            var dataName = opt.getAttribute('data-name');
            if (dataName) {
                return dataName;
            }
            var optText = opt.textContent ? opt.textContent.trim() : '';
            if (optText !== '') {
                return optText;
            }
        }

        var siteMap = window.lineTaskSiteNames || {};
        if (Object.prototype.hasOwnProperty.call(siteMap, siteId) && siteMap[siteId]) {
            return String(siteMap[siteId]);
        }
        if (Object.prototype.hasOwnProperty.call(siteMap, Number(siteId)) && siteMap[Number(siteId)]) {
            return String(siteMap[Number(siteId)]);
        }

        return '—';
    }

    function syncPreview() {
        var assigneeName = '—';
        if (assigneeEl && assigneeEl.selectedIndex > 0) {
            var opt = assigneeEl.options[assigneeEl.selectedIndex];
            assigneeName = opt.getAttribute('data-name') || opt.textContent.trim();
        }

        setText('preview-assignee', assigneeName !== '—' ? '@' + assigneeName : '—');
        setText('preview-destination', selectedSiteName());
        setText('preview-details', detailsEl && detailsEl.value.trim() ? detailsEl.value.trim() : '—');
        setText('preview-due', fmtDateTh(dueDateEl ? dueDateEl.value : '', dueTimeEl ? dueTimeEl.value : ''));

        if (charCountEl && detailsEl) {
            charCountEl.textContent = String(detailsEl.value.length) + ' / 2000';
        }
    }

    if (dueDateEl && typeof flatpickr === 'function' && !dueDateEl.disabled) {
        flatpickr(dueDateEl, {
            dateFormat: 'd/m/Y',
            defaultDate: dueDateEl.value || 'today',
            allowInput: true,
            onChange: syncPreview,
        });
    }

    [assigneeEl, siteEl, detailsEl, dueDateEl, dueTimeEl].forEach(function (el) {
        if (!el) {
            return;
        }
        el.addEventListener('input', syncPreview);
        el.addEventListener('change', syncPreview);
    });

    if (formEl) {
        formEl.addEventListener('submit', function (event) {
            if (!normalizeDueDateForSubmit()) {
                event.preventDefault();
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.setAttribute('aria-busy', 'true');
                var label = submitBtn.querySelector('.label-default');
                var loading = submitBtn.querySelector('.label-loading');
                if (label) {
                    label.classList.add('d-none');
                }
                if (loading) {
                    loading.classList.remove('d-none');
                }
            }
        });
    }

    syncPreview();
})();
