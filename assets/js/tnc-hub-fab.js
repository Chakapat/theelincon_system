(function () {
    'use strict';

    var cfg = window.TncHubFabConfig || {};
    var root = document.getElementById('tncHubFabRoot');
    var mainBtn = document.getElementById('tncHubFabMain');
    var backdrop = document.getElementById('tncHubFabBackdrop');
    var hubsWrap = document.getElementById('tncHubFabHubs');
    var flyout = document.getElementById('tncHubFabFlyout');
    var flyoutHead = document.getElementById('tncHubFabFlyoutHead');
    var flyoutLinks = document.getElementById('tncHubFabFlyoutLinks');
    var flyoutBridge = document.getElementById('tncHubFabFlyoutBridge');
    if (!root || !mainBtn || !backdrop || !hubsWrap || !flyout || !flyoutHead || !flyoutLinks) {
        return;
    }

    var hubs = Array.isArray(cfg.hubs) ? cfg.hubs.slice() : [];
    var hideOnIndexDesktop = !!cfg.hideOnIndexDesktop;
    var activeHubKey = null;
    var hubButtons = {};

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function arcOffset(index, total) {
        if (total <= 1) {
            return '0';
        }
        var mid = (total - 1) / 2;
        var dist = Math.abs(index - mid) / mid;
        return (-0.15 - dist * 0.85).toFixed(2) + 'rem';
    }

    function closeFlyout() {
        activeHubKey = null;
        flyout.hidden = true;
        flyout.setAttribute('aria-hidden', 'true');
        flyout.classList.remove('is-visible');
        root.classList.remove('has-flyout');
        var panel = document.getElementById('tncHubFabFlyoutPanel');
        if (panel) {
            panel.style.maxHeight = '';
        }
        Object.keys(hubButtons).forEach(function (key) {
            hubButtons[key].classList.remove('is-expanded');
            hubButtons[key].setAttribute('aria-expanded', 'false');
        });
    }

    function positionFlyout(hubBtn) {
        var stack = root.querySelector('.tnc-hub-fab-stack');
        var panel = document.getElementById('tncHubFabFlyoutPanel');
        if (!hubBtn || !stack) {
            return;
        }

        var pad = 12;
        var stackRect = stack.getBoundingClientRect();
        var btnRect = hubBtn.getBoundingClientRect();
        var hubCenter = btnRect.top + btnRect.height / 2;
        var anchorY = hubCenter - stackRect.top;

        flyout.style.setProperty('--flyout-anchor-y', anchorY + 'px');
        if (panel) {
            panel.style.maxHeight = '';
        }

        window.requestAnimationFrame(function () {
            var flyRect = flyout.getBoundingClientRect();
            var flyHeight = flyRect.height;
            var maxFlyHeight = window.innerHeight - pad * 2;

            if (panel && flyHeight > maxFlyHeight) {
                panel.style.maxHeight = maxFlyHeight + 'px';
                flyRect = flyout.getBoundingClientRect();
                flyHeight = flyRect.height;
            }

            var idealTop = hubCenter - flyHeight / 2;
            if (idealTop < pad) {
                idealTop = pad;
            }
            if (idealTop + flyHeight > window.innerHeight - pad) {
                idealTop = Math.max(pad, window.innerHeight - pad - flyHeight);
            }

            anchorY = idealTop + flyHeight / 2 - stackRect.top;
            flyout.style.setProperty('--flyout-anchor-y', anchorY + 'px');
        });
    }

    function renderFlyout(hub) {
        flyoutHead.textContent = hub.short_label || hub.label;
        flyoutLinks.innerHTML = '';

        hub.pages.forEach(function (page) {
            var link = document.createElement('a');
            link.className = 'tnc-hub-fab-flyout-link' + (page.active ? ' is-current' : '');
            link.href = page.url;
            if (page.link_class) {
                page.link_class.split(/\s+/).forEach(function (cls) {
                    if (cls) {
                        link.classList.add(cls);
                    }
                });
            }
            link.textContent = page.short_label || page.label;
            link.setAttribute('aria-label', page.label);
            link.addEventListener('click', function () {
                closeDial();
            });
            flyoutLinks.appendChild(link);
        });

        flyout.hidden = false;
        flyout.setAttribute('aria-hidden', 'false');
        flyout.classList.add('is-visible');
        root.classList.add('has-flyout');
    }

    function toggleHub(hub) {
        var btn = hubButtons[hub.key];
        if (!btn) {
            return;
        }

        if (activeHubKey === hub.key) {
            closeFlyout();
            return;
        }

        closeFlyout();
        activeHubKey = hub.key;
        btn.classList.add('is-expanded');
        btn.setAttribute('aria-expanded', 'true');
        renderFlyout(hub);
        positionFlyout(btn);
        window.requestAnimationFrame(function () {
            positionFlyout(btn);
        });
    }

    function renderHubs() {
        hubsWrap.innerHTML = '';
        hubButtons = {};
        var total = hubs.length;

        hubs.forEach(function (hub, index) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'tnc-hub-fab-hub' + (hub.active ? ' is-current' : '');
            el.setAttribute('data-hub-key', hub.key);
            el.setAttribute('aria-expanded', 'false');
            el.setAttribute('aria-label', hub.label);
            el.title = hub.short_label || hub.label;
            el.style.setProperty('--fab-arc', arcOffset(index, total));
            el.innerHTML =
                '<span class="tnc-hub-fab-hub-label">' + escapeHtml(hub.short_label || hub.label) + '</span>' +
                '<span class="tnc-hub-fab-hub-btn"><i class="bi ' + escapeHtml(hub.icon) + '" aria-hidden="true"></i></span>';
            el.addEventListener('click', function () {
                toggleHub(hub);
            });
            hubsWrap.appendChild(el);
            hubButtons[hub.key] = el;
        });
    }

    function isDialOpen() {
        return root.classList.contains('is-open');
    }

    function showBackdrop() {
        backdrop.hidden = false;
        backdrop.classList.add('is-visible');
        backdrop.setAttribute('aria-hidden', 'false');
    }

    function hideBackdrop() {
        backdrop.classList.remove('is-visible');
        backdrop.setAttribute('aria-hidden', 'true');
        window.setTimeout(function () {
            if (!isDialOpen()) {
                backdrop.hidden = true;
            }
        }, 220);
    }

    function openDial() {
        root.classList.add('is-open');
        mainBtn.setAttribute('aria-expanded', 'true');
        showBackdrop();
    }

    function closeDial() {
        closeFlyout();
        root.classList.remove('is-open');
        mainBtn.setAttribute('aria-expanded', 'false');
        hideBackdrop();
    }

    function toggleDial() {
        if (isDialOpen()) {
            closeDial();
            mainBtn.focus();
        } else {
            openDial();
        }
    }

    function updateIndexDesktopVisibility() {
        if (!hideOnIndexDesktop) {
            root.style.display = '';
            return;
        }
        var desktop = window.matchMedia('(min-width: 992px)').matches;
        root.style.display = desktop ? 'none' : '';
    }

    mainBtn.addEventListener('click', toggleDial);
    backdrop.addEventListener('click', closeDial);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isDialOpen()) {
            e.preventDefault();
            if (activeHubKey) {
                closeFlyout();
            } else {
                closeDial();
                mainBtn.focus();
            }
        }
    });

    window.addEventListener('resize', function () {
        if (activeHubKey && hubButtons[activeHubKey]) {
            positionFlyout(hubButtons[activeHubKey]);
        }
        updateIndexDesktopVisibility();
    });

    renderHubs();
    updateIndexDesktopVisibility();
})();
