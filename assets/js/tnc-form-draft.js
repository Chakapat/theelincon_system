/**
 * Autosave ร่างฟอร์ม PR/PO ลง localStorage
 * — ใช้ร่วมหน้าเต็มและ iframe (Site Hub) เพราะ origin เดียวกัน
 *
 * เปิดใช้: <form data-tnc-draft="1" data-tnc-draft-key="..." data-tnc-draft-table="#prTable">
 */
(function (global) {
    'use strict';

    var STORAGE_PREFIX = 'tnc_form_draft:v1:';
    var MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
    var DEBOUNCE_MS = 700;
    var SKIP_NAMES = {
        csrf_token: 1,
        _token: 1,
        embed: 1,
        return_to: 1,
        return_site_id: 1,
        send_line_after_save: 1,
        requested_by: 1,
        pr_id: 1,
        'payment_slips[]': 1,
        confirm_over_contract: 1,
        total_amount: 1,
        pr_number: 1,
        po_number: 1
    };
    var EXTRA_IDS = ['supplier_search'];

    function storageKey(form) {
        var key = (form.getAttribute('data-tnc-draft-key') || '').trim();
        if (!key) {
            return '';
        }
        return STORAGE_PREFIX + key;
    }

    function canUseStorage() {
        try {
            var k = STORAGE_PREFIX + '__probe';
            localStorage.setItem(k, '1');
            localStorage.removeItem(k);
            return true;
        } catch (e) {
            return false;
        }
    }

    function readDraft(form) {
        var sk = storageKey(form);
        if (!sk) {
            return null;
        }
        try {
            var raw = localStorage.getItem(sk);
            if (!raw) {
                return null;
            }
            var data = JSON.parse(raw);
            if (!data || typeof data !== 'object' || !data.savedAt) {
                return null;
            }
            if (Date.now() - Number(data.savedAt) > MAX_AGE_MS) {
                localStorage.removeItem(sk);
                return null;
            }
            return data;
        } catch (e) {
            return null;
        }
    }

    function writeDraft(form, payload) {
        var sk = storageKey(form);
        if (!sk) {
            return false;
        }
        try {
            localStorage.setItem(sk, JSON.stringify(payload));
            return true;
        } catch (e) {
            return false;
        }
    }

    function clearDraft(form) {
        var sk = storageKey(form);
        if (!sk) {
            return;
        }
        try {
            localStorage.removeItem(sk);
        } catch (e) { /* ignore */ }
    }

    function isSkipControl(el) {
        if (!el || !el.name) {
            return true;
        }
        if (SKIP_NAMES[el.name]) {
            return true;
        }
        if (el.type === 'file' || el.type === 'submit' || el.type === 'button' || el.type === 'image') {
            return true;
        }
        if (el.readOnly && (el.classList.contains('row-total') || el.classList.contains('bg-light'))) {
            return true;
        }
        return false;
    }

    function serializeForm(form) {
        var fields = {};
        var extras = {};
        var elements = form.elements;
        var i;
        var el;
        var name;
        var val;

        for (i = 0; i < elements.length; i++) {
            el = elements[i];
            if (isSkipControl(el)) {
                continue;
            }
            name = el.name;
            if (el.type === 'checkbox') {
                if (name.slice(-2) === '[]') {
                    if (!Array.isArray(fields[name])) {
                        fields[name] = [];
                    }
                    // line VAT: hidden + checkbox — store checked state of apply box separately via class
                    if (el.classList.contains('line-vat-apply')) {
                        fields[name].push(el.checked ? '1' : '0');
                    } else {
                        fields[name].push(el.checked ? (el.value || '1') : '');
                    }
                } else {
                    fields[name] = el.checked ? (el.value || '1') : '';
                }
                continue;
            }
            if (el.type === 'radio') {
                if (el.checked) {
                    fields[name] = el.value;
                }
                continue;
            }
            if (el.tagName === 'SELECT' && el.multiple) {
                fields[name] = Array.prototype.map.call(el.selectedOptions, function (o) {
                    return o.value;
                });
                continue;
            }
            val = el.value;
            if (name.slice(-2) === '[]') {
                if (!Array.isArray(fields[name])) {
                    fields[name] = [];
                }
                // Prefer line-vat-exempt hidden over duplicate names
                if (el.classList.contains('line-vat-exempt-val')) {
                    fields[name].push(val);
                } else if (el.classList.contains('line-vat-apply')) {
                    // handled in checkbox branch
                } else {
                    fields[name].push(val);
                }
            } else {
                fields[name] = val;
            }
        }

        // line-vat-apply checkboxes don't have name item_vat_exempt — sync from apply state
        var applyBoxes = form.querySelectorAll('.line-vat-apply');
        if (applyBoxes.length) {
            fields['item_vat_exempt[]'] = Array.prototype.map.call(applyBoxes, function (cb) {
                return cb.checked ? '0' : '1';
            });
        }

        EXTRA_IDS.forEach(function (id) {
            var node = form.querySelector('#' + id) || document.getElementById(id);
            if (node && !node.name) {
                extras[id] = node.value || '';
            }
        });

        return { fields: fields, extras: extras };
    }

    function snapFingerprint(snap) {
        try {
            return JSON.stringify({
                fields: (snap && snap.fields) || {},
                extras: (snap && snap.extras) || {}
            });
        } catch (e) {
            return '';
        }
    }

    function snapsEqual(a, b) {
        return snapFingerprint(a) === snapFingerprint(b);
    }

    function isMeaningful(payload) {
        if (!payload || !payload.fields) {
            return false;
        }
        var f = payload.fields;
        var keys = Object.keys(f);
        var k;
        var v;
        var i;
        var meaningfulKeys = {
            details: 1,
            po_note: 1,
            quotation_note: 1,
            supplier_invoice_no: 1,
            payment_cash_paid_by: 1,
            billed_total_amount: 1,
            billed_vat_amount: 1,
            cost_category_id: 1,
            supplier_id: 1,
            site_id: 1
        };

        for (i = 0; i < keys.length; i++) {
            k = keys[i];
            v = f[k];
            if (k === 'item_description[]' && Array.isArray(v)) {
                if (v.some(function (s) { return String(s || '').trim() !== ''; })) {
                    return true;
                }
            }
            if (k === 'item_qty[]' && Array.isArray(v)) {
                if (v.some(function (s) {
                    var n = parseFloat(String(s || '').replace(/,/g, ''));
                    return Number.isFinite(n) && n > 0;
                })) {
                    return true;
                }
            }
            if (k === 'item_price[]' && Array.isArray(v)) {
                if (v.some(function (s) {
                    var n = parseFloat(String(s || '').replace(/,/g, ''));
                    return Number.isFinite(n) && n > 0;
                })) {
                    return true;
                }
            }
            if (meaningfulKeys[k] && String(v || '').trim() !== '' && String(v) !== '0') {
                return true;
            }
        }
        if (payload.extras && String(payload.extras.supplier_search || '').trim() !== '') {
            return true;
        }
        return false;
    }

    function lineRowCount(form) {
        var tableSel = form.getAttribute('data-tnc-draft-table') || '';
        var table = tableSel ? form.querySelector(tableSel) || document.querySelector(tableSel) : null;
        if (!table) {
            return 0;
        }
        var tbody = table.tBodies[0];
        if (!tbody) {
            return 0;
        }
        return tbody.querySelectorAll('tr:not(.po-line-empty)').length;
    }

    function ensureLineRows(form, needed) {
        needed = Math.max(1, parseInt(String(needed || 1), 10) || 1);
        var guard = 0;
        while (lineRowCount(form) < needed && typeof global.addRow === 'function' && guard < 80) {
            global.addRow();
            guard++;
        }
        // Trim extras (keep at least 1)
        var tableSel = form.getAttribute('data-tnc-draft-table') || '';
        var table = tableSel ? form.querySelector(tableSel) || document.querySelector(tableSel) : null;
        if (!table) {
            return;
        }
        var tbody = table.tBodies[0];
        if (!tbody) {
            return;
        }
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr:not(.po-line-empty)'));
        while (rows.length > needed && rows.length > 1) {
            var last = rows.pop();
            var del = last.querySelector('.po-row-delete-btn');
            if (del && typeof global.removeRow === 'function') {
                global.removeRow(del);
            } else {
                last.remove();
                if (typeof global.updateRowNumbers === 'function') {
                    global.updateRowNumbers();
                }
            }
            rows = Array.prototype.slice.call(tbody.querySelectorAll('tr:not(.po-line-empty)'));
        }
    }

    function setControlValue(el, value) {
        if (!el) {
            return;
        }
        if (el.type === 'checkbox') {
            if (el.classList.contains('line-vat-apply')) {
                el.checked = String(value) === '1' || value === true;
            } else {
                el.checked = value === true || value === el.value || value === '1' || value === 1;
            }
            return;
        }
        if (el.type === 'radio') {
            el.checked = String(el.value) === String(value);
            return;
        }
        el.value = value == null ? '' : String(value);
    }

    function applyFields(form, fields, extras) {
        var name;
        var value;
        var nodes;
        var i;

        form.setAttribute('data-tnc-draft-restoring', '1');

        // site_id first so category map can refresh
        if (Object.prototype.hasOwnProperty.call(fields, 'site_id')) {
            nodes = form.querySelectorAll('[name="site_id"]');
            for (i = 0; i < nodes.length; i++) {
                if (nodes[i].type !== 'hidden' || nodes.length === 1) {
                    setControlValue(nodes[i], fields.site_id);
                } else if (nodes[i].type === 'hidden') {
                    setControlValue(nodes[i], fields.site_id);
                }
            }
            var siteEl = form.querySelector('select#site_id') || form.querySelector('#site_id');
            if (siteEl) {
                siteEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        var desc = fields['item_description[]'];
        var neededRows = Array.isArray(desc) ? desc.length : 0;
        if (neededRows > 0) {
            ensureLineRows(form, neededRows);
        }

        Object.keys(fields).forEach(function (key) {
            if (key === 'site_id') {
                return;
            }
            value = fields[key];
            nodes = form.querySelectorAll('[name="' + key.replace(/"/g, '\\"') + '"]');
            if (!nodes.length) {
                return;
            }

            if (key === 'item_vat_exempt[]') {
                var applyList = form.querySelectorAll('.line-vat-apply');
                var hiddenList = form.querySelectorAll('.line-vat-exempt-val');
                var arr = Array.isArray(value) ? value : [value];
                for (i = 0; i < applyList.length; i++) {
                    var exempt = String(arr[i] != null ? arr[i] : '0') === '1';
                    applyList[i].checked = !exempt;
                    if (hiddenList[i]) {
                        hiddenList[i].value = exempt ? '1' : '0';
                    }
                    if (typeof global.tncPurchaseSyncVatApplyHidden === 'function') {
                        global.tncPurchaseSyncVatApplyHidden(applyList[i]);
                    }
                }
                return;
            }

            if (Array.isArray(value)) {
                var idx = 0;
                for (i = 0; i < nodes.length; i++) {
                    if (nodes[i].classList.contains('line-vat-apply')) {
                        continue;
                    }
                    if (idx < value.length) {
                        setControlValue(nodes[i], value[idx]);
                        idx++;
                    }
                }
                return;
            }

            if (nodes[0].type === 'radio') {
                for (i = 0; i < nodes.length; i++) {
                    setControlValue(nodes[i], value);
                }
                return;
            }

            if (nodes[0].type === 'checkbox') {
                setControlValue(nodes[0], value);
                return;
            }

            // Prefer enabled named control (site locked uses hidden + disabled select)
            var target = nodes[0];
            for (i = 0; i < nodes.length; i++) {
                if (!nodes[i].disabled) {
                    target = nodes[i];
                    break;
                }
            }
            setControlValue(target, value);
        });

        if (extras && typeof extras === 'object') {
            Object.keys(extras).forEach(function (id) {
                var node = form.querySelector('#' + id) || document.getElementById(id);
                if (node) {
                    node.value = extras[id] == null ? '' : String(extras[id]);
                    node.dispatchEvent(new Event('input', { bubbles: true }));
                    node.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        }

        // Re-apply category after options populate
        if (Object.prototype.hasOwnProperty.call(fields, 'cost_category_id')) {
            var catVal = fields.cost_category_id;
            var tries = 0;
            function applyCat() {
                var catEl = form.querySelector('#cost_category_id') || form.querySelector('[name="cost_category_id"]');
                if (!catEl) {
                    return;
                }
                setControlValue(catEl, catVal);
                if (String(catEl.value) !== String(catVal) && tries < 8) {
                    tries++;
                    setTimeout(applyCat, 50);
                }
            }
            setTimeout(applyCat, 30);
        }

        // Payment UI / VAT toggles
        form.querySelectorAll('[name="payment_method"]:checked').forEach(function (r) {
            r.dispatchEvent(new Event('change', { bubbles: true }));
        });
        var vatEnabled = form.querySelector('#vat_enabled');
        if (vatEnabled) {
            vatEnabled.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (typeof global.calculateTotal === 'function') {
            global.calculateTotal();
        }
        if (typeof global.updateRowNumbers === 'function') {
            global.updateRowNumbers();
        }

        setTimeout(function () {
            form.removeAttribute('data-tnc-draft-restoring');
        }, 200);
    }

    function formatWhen(ts) {
        try {
            var d = new Date(Number(ts));
            if (Number.isNaN(d.getTime())) {
                return '';
            }
            var dd = String(d.getDate()).padStart(2, '0');
            var mm = String(d.getMonth() + 1).padStart(2, '0');
            var yyyy = d.getFullYear();
            var hh = String(d.getHours()).padStart(2, '0');
            var mi = String(d.getMinutes()).padStart(2, '0');
            return dd + '/' + mm + '/' + yyyy + ' ' + hh + ':' + mi;
        } catch (e) {
            return '';
        }
    }

    function ensureBanner(form) {
        var existing = form.querySelector('.tnc-draft-banner');
        if (existing) {
            return existing;
        }
        var banner = document.createElement('div');
        banner.className = 'tnc-draft-banner alert border-0 shadow-sm mb-3 d-none';
        banner.setAttribute('role', 'status');
        banner.innerHTML =
            '<div class="d-flex flex-wrap align-items-center gap-2 justify-content-between">' +
            '<div class="tnc-draft-banner-msg small mb-0">' +
            '<i class="bi bi-journal-text me-1"></i>' +
            '<span class="tnc-draft-banner-text"></span>' +
            '</div>' +
            '<div class="d-flex flex-wrap gap-2">' +
            '<button type="button" class="btn btn-sm btn-orange rounded-pill px-3 tnc-draft-restore">กู้คืนร่าง</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 tnc-draft-discard">ล้างร่าง</button>' +
            '</div></div>';
        form.insertBefore(banner, form.firstChild);
        return banner;
    }

    function ensureStatus(form) {
        var existing = form.querySelector('.tnc-draft-status');
        if (existing) {
            return existing;
        }
        var status = document.createElement('div');
        status.className = 'tnc-draft-status text-muted small mt-2 mb-0 d-none';
        status.setAttribute('aria-live', 'polite');
        var actions = form.querySelector('.po-submit-bar, .pr-submit-bar, .po-actions-bar');
        var hero = form.querySelector('.po-create-hero');
        if (hero && hero.parentNode === form) {
            hero.appendChild(status);
        } else if (actions) {
            actions.parentNode.insertBefore(status, actions.nextSibling);
        } else {
            form.appendChild(status);
        }
        return status;
    }

    function showStatus(form, text) {
        var el = ensureStatus(form);
        el.textContent = text;
        el.classList.remove('d-none');
    }

    function hideBanner(banner) {
        if (banner) {
            banner.classList.add('d-none');
        }
    }

    function showRestoreBanner(form, draft) {
        var banner = ensureBanner(form);
        var when = formatWhen(draft.savedAt);
        var textEl = banner.querySelector('.tnc-draft-banner-text');
        if (textEl) {
            textEl.textContent = when
                ? ('พบร่างที่บันทึกไว้เมื่อ ' + when)
                : 'พบร่างที่บันทึกไว้ ';
        }
        banner.classList.remove('d-none');
        return banner;
    }

    function bindForm(form) {
        if (!form || form.getAttribute('data-tnc-draft') !== '1') {
            return;
        }
        if (form.getAttribute('data-tnc-draft-bound') === '1') {
            return;
        }
        if (!canUseStorage() || !storageKey(form)) {
            return;
        }
        form.setAttribute('data-tnc-draft-bound', '1');

        var timer = null;
        var banner = null;
        var pristineBaseline = null;
        var baselineReady = false;

        function capturePristineBaseline() {
            pristineBaseline = serializeForm(form);
            baselineReady = true;
        }

        function saveNow() {
            if (form.getAttribute('data-tnc-draft-restoring') === '1') {
                return;
            }
            var snap = serializeForm(form);
            // กลับเท่าค่าตอนโหลดหน้า = ไม่ต้องเก็บร่าง
            if (baselineReady && snapsEqual(snap, pristineBaseline)) {
                clearDraft(form);
                return;
            }
            var payload = {
                savedAt: Date.now(),
                fields: snap.fields,
                extras: snap.extras
            };
            if (!isMeaningful(payload)) {
                clearDraft(form);
                return;
            }
            if (writeDraft(form, payload)) {
                showStatus(form, 'บันทึกร่างอัตโนมัติแล้ว · ' + formatWhen(payload.savedAt));
            }
        }

        function scheduleSave() {
            if (form.getAttribute('data-tnc-draft-restoring') === '1') {
                return;
            }
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(saveNow, DEBOUNCE_MS);
        }

        form.addEventListener('input', scheduleSave, true);
        form.addEventListener('change', scheduleSave, true);

        form.addEventListener('submit', function () {
            if (timer) {
                clearTimeout(timer);
            }
            clearDraft(form);
            showStatus(form, '');
            var st = form.querySelector('.tnc-draft-status');
            if (st) {
                st.classList.add('d-none');
            }
            hideBanner(banner);
        });

        global.addEventListener('pagehide', function () {
            if (timer) {
                clearTimeout(timer);
            }
            saveNow();
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                if (timer) {
                    clearTimeout(timer);
                }
                saveNow();
            }
        });

        var draft = readDraft(form);

        // รอให้ select หมวด/flatpickr นิ่งก่อนจับ baseline แล้วค่อยเทียบร่าง
        setTimeout(function () {
            capturePristineBaseline();
            if (!draft || !isMeaningful(draft)) {
                return;
            }
            if (snapsEqual(draft, pristineBaseline)) {
                clearDraft(form);
                return;
            }
            banner = showRestoreBanner(form, draft);
            banner.querySelector('.tnc-draft-restore')?.addEventListener('click', function () {
                applyFields(form, draft.fields || {}, draft.extras || {});
                hideBanner(banner);
                showStatus(form, 'กู้คืนร่างแล้ว · ' + formatWhen(draft.savedAt));
                setTimeout(saveNow, 250);
            });
            banner.querySelector('.tnc-draft-discard')?.addEventListener('click', function () {
                clearDraft(form);
                hideBanner(banner);
                showStatus(form, 'ล้างร่างแล้ว');
            });
        }, 180);
    }

    function init() {
        document.querySelectorAll('form[data-tnc-draft="1"]').forEach(bindForm);
    }

    global.TncFormDraft = {
        init: init,
        bind: bindForm,
        clear: clearDraft,
        save: function (form) {
            if (!form) {
                return;
            }
            var snap = serializeForm(form);
            var payload = { savedAt: Date.now(), fields: snap.fields, extras: snap.extras };
            if (isMeaningful(payload)) {
                writeDraft(form, payload);
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(typeof window !== 'undefined' ? window : this);
