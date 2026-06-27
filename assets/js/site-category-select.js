/**
 * เติม <select name="cost_category_id"> จาก catMap ที่ tnc_site_categories_map_by_site() ส่งมา
 * รองรับหมวดย่อย: optgroup ตามหมวดหลัก + option หมวดย่อยที่เลือกได้
 */
(function (global) {
    'use strict';

    function resolveSiteList(catMap, siteId) {
        if (!catMap || typeof catMap !== 'object') {
            return [];
        }
        var sid = parseInt(String(siteId || '0'), 10) || 0;
        if (sid > 0 && Array.isArray(catMap[sid])) {
            return catMap[sid];
        }
        return Array.isArray(catMap[0]) ? catMap[0] : [];
    }

    function appendPlaceholder(selectEl, text) {
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.textContent = text || '— เลือกหมวด —';
        selectEl.appendChild(placeholder);
        return placeholder;
    }

    function populateSiteCategorySelect(selectEl, catMap, siteId, prevId, emptyText) {
        if (!selectEl) {
            return;
        }
        var prev = parseInt(String(prevId || selectEl.value || '0'), 10) || 0;
        selectEl.innerHTML = '';
        var sid = parseInt(String(siteId || '0'), 10) || 0;
        if (sid <= 0) {
            selectEl.disabled = true;
            appendPlaceholder(selectEl, emptyText || '— เลือกไซต์ก่อน —');
            return;
        }
        selectEl.disabled = false;
        var placeholder = appendPlaceholder(selectEl);
        var list = resolveSiteList(catMap, sid);
        var hasPrev = false;

        list.forEach(function (entry) {
            if (!entry || typeof entry !== 'object') {
                return;
            }
            if (entry.type === 'group' && Array.isArray(entry.items)) {
                var group = document.createElement('optgroup');
                group.label = String(entry.label || entry.name || '');
                entry.items.forEach(function (item) {
                    var opt = document.createElement('option');
                    opt.value = String(item.id || '');
                    opt.textContent = String(item.label || item.name || '');
                    if (parseInt(opt.value, 10) === prev) {
                        opt.selected = true;
                        hasPrev = true;
                    }
                    group.appendChild(opt);
                });
                if (group.children.length > 0) {
                    selectEl.appendChild(group);
                }
                return;
            }
            if (entry.type === 'option' || entry.id) {
                var leaf = document.createElement('option');
                leaf.value = String(entry.id || '');
                leaf.textContent = String(entry.label || entry.name || '');
                if (parseInt(leaf.value, 10) === prev) {
                    leaf.selected = true;
                    hasPrev = true;
                }
                selectEl.appendChild(leaf);
            }
        });

        if (!hasPrev) {
            placeholder.selected = true;
        }
    }

    global.tncPopulateSiteCategorySelect = populateSiteCategorySelect;
})(typeof window !== 'undefined' ? window : this);
