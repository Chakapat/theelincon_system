(function (global) {
    'use strict';

    var tableApis = typeof WeakMap !== 'undefined' ? new WeakMap() : null;
    var tableApiFallback = [];

    function parseNum(v) {
        return parseFloat(String(v || '').replace(/,/g, '')) || 0;
    }

    function fmtNum(n) {
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function calcLine(qty, material, labor) {
        var q = Math.max(0, parseNum(qty));
        var m = Math.max(0, Math.round(parseNum(material) * 100) / 100);
        var l = Math.max(0, Math.round(parseNum(labor) * 100) / 100);
        var unit = Math.round((m + l) * 100) / 100;
        var total = Math.round(q * unit * 100) / 100;
        return { qty: q, material: m, labor: l, unit: unit, total: total };
    }

    function fieldNames(prefix) {
        if (prefix === 'item') {
            return {
                type: 'item_line_type',
                desc: 'item_description',
                qty: 'item_qty',
                unit: 'item_unit',
                material: 'item_material',
                labor: 'item_labor',
            };
        }
        return {
            type: 'hire_line_type',
            desc: 'hire_description',
            qty: 'hire_qty',
            unit: 'hire_unit',
            material: 'hire_material',
            labor: 'hire_labor',
        };
    }

    function isGroupRow(row) {
        if (row.classList.contains('hire-row-group')) {
            return true;
        }
        var typeEl = row.querySelector('.hire-line-type');
        return typeEl && typeEl.value === 'group';
    }

    function recomputeLineNumbers(table) {
        var major = 0;
        var minor = 0;
        table.querySelectorAll('tbody tr').forEach(function (row) {
            var noEl = row.querySelector('.hire-line-no');
            if (!noEl) {
                return;
            }
            if (isGroupRow(row)) {
                major += 1;
                minor = 0;
                noEl.textContent = String(major);
            } else {
                if (major > 0) {
                    minor += 1;
                    noEl.textContent = major + '.' + minor;
                } else {
                    major += 1;
                    noEl.textContent = String(major);
                }
            }
        });
    }

    function bindRow(row, recalcAll) {
        row.querySelectorAll('.hire-qty, .hire-material, .hire-labor, .hire-desc, .hire-unit, .hire-desc-group').forEach(function (el) {
            el.addEventListener('input', recalcAll);
            el.addEventListener('change', recalcAll);
        });
        var removeBtn = row.querySelector('.hire-remove-row');
        if (removeBtn) {
            removeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var tbody = row.closest('tbody');
                if (!tbody || tbody.querySelectorAll('tr').length <= 1) {
                    return;
                }
                row.remove();
                recalcAll();
            });
        }
    }

    function updateRemoveButtons(table) {
        var rows = table.querySelectorAll('tbody tr');
        var disableRemove = rows.length <= 1;
        rows.forEach(function (row) {
            var btn = row.querySelector('.hire-remove-row');
            if (btn) {
                btn.disabled = disableRemove;
            }
        });
    }

    function recalcTable(table, onSubtotal) {
        var subtotal = 0;
        table.querySelectorAll('tbody tr').forEach(function (row) {
            if (isGroupRow(row)) {
                return;
            }
            var qtyEl = row.querySelector('.hire-qty');
            var matEl = row.querySelector('.hire-material');
            var laborEl = row.querySelector('.hire-labor');
            var unitEl = row.querySelector('.hire-unit-price');
            var totalEl = row.querySelector('.hire-line-total');
            var line = calcLine(qtyEl && qtyEl.value, matEl && matEl.value, laborEl && laborEl.value);
            if (unitEl) {
                unitEl.value = fmtNum(line.unit);
            }
            if (totalEl) {
                totalEl.value = fmtNum(line.total);
            }
            subtotal += line.total;
        });
        subtotal = Math.round(subtotal * 100) / 100;
        recomputeLineNumbers(table);
        if (typeof onSubtotal === 'function') {
            onSubtotal(subtotal);
        }
        updateRemoveButtons(table);
        return subtotal;
    }

    function rowHtmlGroup(prefix) {
        var f = fieldNames(prefix);
        return ''
            + '<td class="hire-line-no text-center fw-bold align-middle">1</td>'
            + '<td colspan="7" class="py-2">'
            + '<input type="hidden" name="' + f.type + '[]" class="hire-line-type" value="group">'
            + '<input type="hidden" name="' + f.qty + '[]" value="0">'
            + '<input type="hidden" name="' + f.unit + '[]" value="">'
            + '<input type="hidden" name="' + f.material + '[]" value="0">'
            + '<input type="hidden" name="' + f.labor + '[]" value="0">'
            + '<input type="text" name="' + f.desc + '[]" class="form-control hire-desc-group fw-semibold" required placeholder="หัวข้อหลัก เช่น งาน Steel">'
            + '</td>'
            + '<td class="text-center align-middle"><button type="button" class="hire-btn-remove hire-remove-row" title="ลบหัวข้อ"><i class="bi bi-trash3"></i></button></td>';
    }

    function rowHtmlItem(prefix) {
        var f = fieldNames(prefix);
        return ''
            + '<td class="hire-line-no text-center align-middle">1.1</td>'
            + '<td class="hire-col-desc-cell"><input type="hidden" name="' + f.type + '[]" class="hire-line-type" value="item">'
            + '<div class="hire-desc-wrap"><span class="hire-desc-indent" aria-hidden="true"></span>'
            + '<input type="text" name="' + f.desc + '[]" class="form-control hire-desc" required placeholder="รายการย่อย เช่น ค่าแรงเชื่อมประกอบ"></div></td>'
            + '<td><input type="number" name="' + f.qty + '[]" class="form-control hire-qty text-end" min="0" step="0.01" value="1"></td>'
            + '<td><input type="text" name="' + f.unit + '[]" class="form-control hire-unit text-end" placeholder="ชุด"></td>'
            + '<td><input type="number" name="' + f.material + '[]" class="form-control hire-material text-end" min="0" step="0.01" value="0"></td>'
            + '<td><input type="number" name="' + f.labor + '[]" class="form-control hire-labor text-end" min="0" step="0.01" value="0"></td>'
            + '<td class="hire-unit-price-sum"><input type="text" class="form-control hire-unit-price text-end bg-light" readonly value="0.00"></td>'
            + '<td><input type="text" class="form-control hire-line-total text-end bg-light" readonly value="0.00"></td>'
            + '<td class="text-center"><button type="button" class="hire-btn-remove hire-remove-row" title="ลบรายการ"><i class="bi bi-trash3"></i></button></td>';
    }

    function focusNewRow(tr) {
        var focusEl = tr.querySelector('.hire-desc-group, .hire-desc');
        if (focusEl && !focusEl.disabled) {
            try {
                focusEl.focus({ preventScroll: true });
            } catch (err) {
                focusEl.focus();
            }
            if (typeof tr.scrollIntoView === 'function') {
                tr.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }
    }

    function appendRow(table, html, recalcAll) {
        var tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }
        var tr = document.createElement('tr');
        if (html.indexOf('hire-row-group') >= 0 || html.indexOf('value="group"') >= 0) {
            tr.className = 'hire-row-group';
        } else {
            tr.className = 'hire-row-item';
        }
        tr.innerHTML = html;
        tbody.appendChild(tr);
        bindRow(tr, recalcAll);
        recalcAll();
        focusNewRow(tr);
    }

    function storeApi(table, api) {
        if (tableApis) {
            tableApis.set(table, api);
        } else {
            tableApiFallback.push({ table: table, api: api });
        }
        table.setAttribute('data-tnc-hire-bound', '1');
    }

    function getApi(table) {
        if (tableApis) {
            return tableApis.get(table) || null;
        }
        for (var i = 0; i < tableApiFallback.length; i++) {
            if (tableApiFallback[i].table === table) {
                return tableApiFallback[i].api;
            }
        }
        return null;
    }

    function findTableForButton(btn) {
        var root = btn.closest('[data-tnc-hire-root]');
        if (root) {
            return root.querySelector('table.table-hire-lines');
        }
        var section = btn.closest('.hire-lines-section, .section-card, .pr-table-card');
        if (section) {
            return section.querySelector('table.table-hire-lines');
        }
        var panel = btn.closest('.hire-table-panel');
        if (panel) {
            return panel.querySelector('table.table-hire-lines');
        }
        return document.querySelector('table.table-hire-lines');
    }

    function bindClickButton(btn, handler) {
        if (!btn) {
            return;
        }
        if (btn.dataset.tncHireClickBound === '1') {
            return;
        }
        btn.dataset.tncHireClickBound = '1';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            handler();
        });
    }

    function bindToolbarButtons(table, api) {
        var root = table.closest('[data-tnc-hire-root]')
            || table.closest('.hire-lines-section, .section-card, .pr-table-card');
        if (!root) {
            return;
        }
        root.querySelectorAll('[data-tnc-hire-add="group"]').forEach(function (btn) {
            bindClickButton(btn, api.addGroup);
        });
        root.querySelectorAll('[data-tnc-hire-add="item"]').forEach(function (btn) {
            bindClickButton(btn, api.addItem);
        });
    }

    function bindTable(table, options) {
        if (!table) {
            return {
                recalc: function () { return 0; },
                addGroup: function () {},
                addItem: function () {},
            };
        }
        var opts = options || {};
        var prefix = opts.fieldPrefix || 'hire';
        var recalcAll = function () {
            return recalcTable(table, opts.onSubtotal);
        };

        table.querySelectorAll('tbody tr').forEach(function (row) {
            if (row.querySelector('.hire-line-type') && row.querySelector('.hire-line-type').value === 'group') {
                row.classList.add('hire-row-group');
            } else {
                row.classList.add('hire-row-item');
            }
            bindRow(row, recalcAll);
        });

        var addGroupRow = function () {
            appendRow(table, rowHtmlGroup(prefix), recalcAll);
        };
        var addItemRow = function () {
            appendRow(table, rowHtmlItem(prefix), recalcAll);
        };

        var api = {
            recalc: recalcAll,
            calcLine: calcLine,
            addGroup: addGroupRow,
            addItem: addItemRow,
            recomputeLineNumbers: function () { recomputeLineNumbers(table); },
        };

        storeApi(table, api);
        bindClickButton(opts.addGroupButton, addGroupRow);
        bindClickButton(opts.addItemButton || opts.addButton, addItemRow);
        bindToolbarButtons(table, api);

        recalcAll();
        return api;
    }

    if (!global.__tncHireLineDelegation) {
        global.__tncHireLineDelegation = true;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-tnc-hire-add]');
            if (!btn || btn.disabled) {
                return;
            }
            var table = findTableForButton(btn);
            if (!table) {
                return;
            }
            var api = getApi(table);
            if (!api) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var action = btn.getAttribute('data-tnc-hire-add');
            if (action === 'group' && typeof api.addGroup === 'function') {
                api.addGroup();
            } else if (action === 'item' && typeof api.addItem === 'function') {
                api.addItem();
            }
        }, true);
    }

    global.TncHireLineTable = {
        calcLine: calcLine,
        bindTable: bindTable,
        rowHtmlGroup: rowHtmlGroup,
        rowHtmlItem: rowHtmlItem,
        rowHtml: function () { return rowHtmlItem('hire'); },
        rowHtmlPoDirect: function () { return rowHtmlItem('item'); },
    };
})(window);
