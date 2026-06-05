/**
 * Navbar hub panels — Bootstrap popover look, click-to-toggle (not dropdown).
 */
(function () {
    'use strict';

    function closeAll() {
        document.querySelectorAll('.tnc-nav-popover.show').forEach(function (panel) {
            panel.classList.remove('show');
            window.setTimeout(function () {
                if (!panel.classList.contains('show')) {
                    panel.classList.add('d-none');
                }
            }, 180);
        });
        document.querySelectorAll('.nav-hub-block.show').forEach(function (block) {
            block.classList.remove('show');
        });
        document.querySelectorAll('[data-tnc-nav-popover-toggle][aria-expanded="true"]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    function openPanel(toggle, panel) {
        closeAll();
        panel.classList.remove('d-none');
        window.requestAnimationFrame(function () {
            panel.classList.add('show');
        });
        var block = toggle.closest('.nav-hub-block');
        if (block) {
            block.classList.add('show');
        }
        toggle.setAttribute('aria-expanded', 'true');
    }

    function initPair(toggleId, panelId) {
        var toggle = document.getElementById(toggleId);
        var panel = document.getElementById(panelId);
        if (!toggle || !panel) {
            return;
        }
        toggle.setAttribute('data-tnc-nav-popover-toggle', '1');
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var isOpen = panel.classList.contains('show');
            if (isOpen) {
                closeAll();
                return;
            }
            openPanel(toggle, panel);
        });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.tnc-nav-popover') || e.target.closest('[data-tnc-nav-popover-toggle]')) {
            return;
        }
        closeAll();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAll();
        }
    });

    initPair('tncNotifToggle', 'tncNotifPopover');
    initPair('userDropdown', 'tncUserPopover');

    window.TncNavPopover = { closeAll: closeAll };
})();
