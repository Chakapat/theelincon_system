/**
 * Move document toolbar actions into bottom dock + "more" sheet on mobile.
 * Important: print preview can make max-width media queries match — never reshuffle
 * DOM while printing, and always restore nodes to their real toolbar home.
 */
(function () {
    'use strict';

    var mq = window.matchMedia('(max-width: 991.98px)');
    var printMq = window.matchMedia('print');

    function isPrinting() {
        try {
            return printMq.matches;
        } catch (e) {
            return false;
        }
    }

    function ensureChrome() {
        var dock = document.getElementById('tncDocActionDock');
        if (!dock) {
            dock = document.createElement('nav');
            dock.id = 'tncDocActionDock';
            dock.className = 'tnc-doc-action-dock no-print';
            dock.setAttribute('aria-label', 'การดำเนินการเอกสาร');
            dock.hidden = true;
            dock.innerHTML =
                '<div class="tnc-doc-dock-slot" data-dock-slot="back"></div>' +
                '<div class="tnc-doc-dock-slot" data-dock-slot="edit"></div>' +
                '<div class="tnc-doc-dock-slot" data-dock-slot="print"></div>' +
                '<button type="button" class="tnc-doc-dock-more" id="tncDocDockMore" data-bs-toggle="offcanvas" data-bs-target="#tncDocMoreSheet" aria-label="เพิ่มเติม">' +
                '<i class="bi bi-three-dots" aria-hidden="true"></i><span>เพิ่มเติม</span></button>';
            document.body.appendChild(dock);
        }

        if (!document.getElementById('tncDocMoreSheet')) {
            var sheet = document.createElement('div');
            sheet.className = 'offcanvas offcanvas-bottom no-print';
            sheet.id = 'tncDocMoreSheet';
            sheet.setAttribute('tabindex', '-1');
            sheet.setAttribute('aria-labelledby', 'tncDocMoreSheetLabel');
            sheet.innerHTML =
                '<div class="offcanvas-header border-0 pb-0">' +
                '<h5 class="offcanvas-title fw-bold" id="tncDocMoreSheetLabel">การดำเนินการ</h5>' +
                '<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="ปิด"></button>' +
                '</div>' +
                '<div class="offcanvas-body" id="tncDocMoreSheetBody"></div>';
            document.body.appendChild(sheet);
        }

        return dock;
    }

    function rememberHome(el) {
        if (!el._tncDocHome) {
            el._tncDocHome = {
                parent: el.parentNode,
                next: el.nextSibling,
                html: el.innerHTML,
                className: el.className,
            };
        }
    }

    function restoreHome(el) {
        if (!el._tncDocHome || !el._tncDocHome.parent) {
            return;
        }
        var home = el._tncDocHome;
        if (home.html != null) {
            el.innerHTML = home.html;
        }
        if (home.className != null) {
            el.className = home.className;
        }
        // Must compare parentNode — nextSibling alone misses "last child" cases in the dock
        if (el.parentNode !== home.parent || el.nextSibling !== home.next) {
            home.parent.insertBefore(el, home.next);
        }
    }

    function labelForAction(el) {
        var primary = el.getAttribute('data-dock-primary');
        if (primary === 'back') return 'กลับ';
        if (primary === 'print') return 'พิมพ์';
        if (primary === 'edit') return 'แก้ไข';
        var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
        return text.length > 20 ? text.slice(0, 18) + '…' : (text || 'จัดการ');
    }

    function iconForAction(el) {
        var icon = el.querySelector('i.bi');
        if (icon) return icon.className;
        var primary = el.getAttribute('data-dock-primary');
        if (primary === 'back') return 'bi bi-arrow-left';
        if (primary === 'print') return 'bi bi-printer';
        if (primary === 'edit') return 'bi bi-pencil-square';
        return 'bi bi-chevron-right';
    }

    function styleDockControl(el) {
        if (!el.classList.contains('btn')) {
            el.classList.add('btn');
        }
        el.classList.remove('btn-sm', 'rounded-pill', 'px-3', 'shadow-sm', 'ms-2');
        el.innerHTML = '<i class="' + iconForAction(el) + '" aria-hidden="true"></i><span>' + labelForAction(el) + '</span>';
    }

    function clearDockSlots() {
        ['back', 'edit', 'print'].forEach(function (slot) {
            var holder = document.querySelector('[data-dock-slot="' + slot + '"]');
            if (holder) {
                holder.innerHTML = '';
            }
        });
        var sheetBody = document.getElementById('tncDocMoreSheetBody');
        if (sheetBody) {
            sheetBody.innerHTML = '';
        }
    }

    function restoreDesktop() {
        document.body.classList.remove('tnc-layout-doc');
        var dock = document.getElementById('tncDocActionDock');
        if (dock) {
            dock.hidden = true;
        }
        document.querySelectorAll('.js-tnc-doc-action').forEach(restoreHome);
        clearDockSlots();
    }

    function applyMobile() {
        var toolbar = document.querySelector('.js-tnc-doc-toolbar');
        if (!toolbar) {
            return;
        }

        document.body.classList.add('tnc-layout-doc');
        var dock = ensureChrome();
        var sheetBody = document.getElementById('tncDocMoreSheetBody');
        var moreBtn = document.getElementById('tncDocDockMore');

        ['back', 'edit', 'print'].forEach(function (slot) {
            var holder = dock.querySelector('[data-dock-slot="' + slot + '"]');
            if (holder) {
                holder.innerHTML = '';
            }
        });
        if (sheetBody) {
            sheetBody.innerHTML = '';
        }

        var moreCount = 0;
        toolbar.querySelectorAll('.js-tnc-doc-action').forEach(function (action) {
            rememberHome(action);
            var primary = action.getAttribute('data-dock-primary');
            var slot = primary ? dock.querySelector('[data-dock-slot="' + primary + '"]') : null;
            if (slot && !slot.firstElementChild) {
                styleDockControl(action);
                slot.appendChild(action);
                return;
            }
            if (sheetBody) {
                if (action.classList.contains('btn')) {
                    action.classList.add('w-100', 'text-start');
                }
                sheetBody.appendChild(action);
                moreCount += 1;
            }
        });

        if (moreBtn) {
            moreBtn.style.display = moreCount > 0 ? '' : 'none';
        }
        dock.hidden = false;
    }

    function apply() {
        // Print preview often flips max-width media queries; do not move toolbar nodes then.
        if (isPrinting()) {
            return;
        }
        if (mq.matches) {
            applyMobile();
        } else {
            restoreDesktop();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }

    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', apply);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(apply);
    }

    window.addEventListener('beforeprint', function () {
        // Keep actions in the real toolbar; print CSS already hides .no-print
        restoreDesktop();
    });
    window.addEventListener('afterprint', function () {
        apply();
    });
})();
