(function () {
    'use strict';

    var cfg = window.TncHubFabConfig || {};
    var root = document.getElementById('tncHubFabRoot');
    var mainBtn = document.getElementById('tncHubFabMain');
    var backdrop = document.getElementById('tncHubFabBackdrop');
    var pinsWrap = document.getElementById('tncHubFabPins');
    if (!root || !mainBtn || !backdrop || !pinsWrap) {
        return;
    }

    var storageKey = cfg.storageKey || 'tnc_hub_favorites_v1';
    var maxFav = cfg.maxFavorites || 6;
    var defaultPins = Array.isArray(cfg.pins) ? cfg.pins.slice() : [];
    var hideOnIndexDesktop = !!cfg.hideOnIndexDesktop;

    function readFavorites() {
        try {
            var raw = localStorage.getItem(storageKey);
            if (!raw) {
                return [];
            }
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed.filter(function (k) { return typeof k === 'string' && k; }) : [];
        } catch (e) {
            return [];
        }
    }

    function pinByKey(key) {
        var i;
        for (i = 0; i < defaultPins.length; i++) {
            if (defaultPins[i].key === key) {
                return defaultPins[i];
            }
        }
        return null;
    }

    function resolvedPins() {
        var favs = readFavorites();
        var out = [];
        var seen = {};
        var i;
        var p;

        if (favs.length === 0) {
            return defaultPins.slice(0, maxFav);
        }

        for (i = 0; i < favs.length; i++) {
            p = pinByKey(favs[i]);
            if (p && !seen[p.key]) {
                seen[p.key] = true;
                out.push(p);
            }
        }
        return out.slice(0, maxFav);
    }

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

    function renderPins() {
        var pins = resolvedPins();
        var total = pins.length;
        pinsWrap.innerHTML = '';

        pins.forEach(function (pin, index) {
            var el = document.createElement('a');
            el.className = 'tnc-hub-fab-pin' + (pin.active ? ' is-active' : '');
            el.href = pin.url;
            el.setAttribute('data-page-key', pin.key);
            el.setAttribute('aria-label', pin.label);
            el.title = pin.label;
            el.style.setProperty('--fab-arc', arcOffset(index, total));
            el.innerHTML =
                '<span class="tnc-hub-fab-pin-label">' + escapeHtml(pin.label) + '</span>' +
                '<span class="tnc-hub-fab-pin-btn"><i class="bi ' + escapeHtml(pin.icon) + '" aria-hidden="true"></i></span>';
            el.addEventListener('click', function () {
                closeDial();
            });
            pinsWrap.appendChild(el);
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
            closeDial();
            mainBtn.focus();
        }
    });

    renderPins();
    updateIndexDesktopVisibility();
    window.addEventListener('resize', updateIndexDesktopVisibility);
})();
