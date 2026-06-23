/**
 * TNC Mobile shell — bottom nav + menu sheet + dropdown helpers
 */
(function () {
    'use strict';

    var moreBtn = document.getElementById('tncMobileNavMore');
    var menuSheet = document.getElementById('tncMobileMenuSheet');
    var menuBackdrop = document.getElementById('tncMobileMenuBackdrop');
    var menuClose = document.getElementById('tncMobileMenuClose');

    function isMobileNavContext() {
        return window.matchMedia('(max-width: 991.98px)').matches;
    }

    function openMobileMenu() {
        if (!menuSheet || !isMobileNavContext()) {
            return;
        }
        menuSheet.hidden = false;
        menuSheet.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function () {
            menuSheet.classList.add('is-open');
        });
        if (moreBtn) {
            moreBtn.setAttribute('aria-expanded', 'true');
            moreBtn.classList.add('is-active');
        }
        document.body.classList.add('tnc-mobile-menu-open');
    }

    function closeMobileMenu() {
        if (!menuSheet) {
            return;
        }
        menuSheet.classList.remove('is-open');
        menuSheet.setAttribute('aria-hidden', 'true');
        if (moreBtn) {
            moreBtn.setAttribute('aria-expanded', 'false');
            if (!moreBtn.dataset.forceActive) {
                moreBtn.classList.remove('is-active');
            }
        }
        document.body.classList.remove('tnc-mobile-menu-open');
        window.setTimeout(function () {
            if (!menuSheet.classList.contains('is-open')) {
                menuSheet.hidden = true;
            }
        }, 280);
    }

    if (moreBtn) {
        moreBtn.addEventListener('click', function () {
            if (!menuSheet) {
                return;
            }
            if (menuSheet.classList.contains('is-open')) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        });
    }

    if (menuBackdrop) {
        menuBackdrop.addEventListener('click', closeMobileMenu);
    }

    if (menuClose) {
        menuClose.addEventListener('click', closeMobileMenu);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && menuSheet && menuSheet.classList.contains('is-open')) {
            closeMobileMenu();
        }
    });

    window.addEventListener('resize', function () {
        if (!isMobileNavContext()) {
            closeMobileMenu();
        }
    });

    function isMobileTableContext() {
        return isMobileNavContext();
    }

    function ensureDropdownBackdrop() {
        var el = document.getElementById('tncMobileDropdownBackdrop');
        if (el) {
            return el;
        }
        el = document.createElement('div');
        el.id = 'tncMobileDropdownBackdrop';
        el.className = 'tnc-mobile-dropdown-backdrop';
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        document.body.appendChild(el);
        el.addEventListener('click', function () {
            document.querySelectorAll('.tnc-mobile-table .dropdown-menu.show').forEach(function (menu) {
                var toggle = menu.previousElementSibling;
                if (toggle && window.bootstrap && bootstrap.Dropdown) {
                    bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
                }
            });
        });
        return el;
    }

    document.addEventListener('show.bs.dropdown', function (e) {
        if (!isMobileTableContext()) {
            return;
        }
        var toggle = e.target;
        if (!toggle || !toggle.closest('.tnc-mobile-table')) {
            return;
        }
        var backdrop = ensureDropdownBackdrop();
        backdrop.hidden = false;
        backdrop.classList.add('is-visible');
    });

    document.addEventListener('hide.bs.dropdown', function (e) {
        if (!isMobileTableContext()) {
            return;
        }
        var toggle = e.target;
        if (!toggle || !toggle.closest('.tnc-mobile-table')) {
            return;
        }
        var backdrop = document.getElementById('tncMobileDropdownBackdrop');
        if (backdrop) {
            backdrop.classList.remove('is-visible');
            backdrop.hidden = true;
        }
    });

    function syncStickyTotal(sourceId, targetId) {
        var source = document.getElementById(sourceId);
        var target = document.getElementById(targetId);
        if (!source || !target) {
            return;
        }
        var sync = function () {
            target.textContent = source.textContent || '0.00';
        };
        sync();
        if (typeof MutationObserver === 'function') {
            var obs = new MutationObserver(sync);
            obs.observe(source, { childList: true, characterData: true, subtree: true });
        }
        setInterval(sync, 800);
    }

    syncStickyTotal('grand_total', 'grand_total_sticky');
    syncStickyTotal('grand_total', 'grand_total_mobile_sticky');
    syncStickyTotal('po_pay_grand_total', 'grand_total_sticky');
    syncStickyTotal('grand_total_display', 'grand_total_sticky');
})();
