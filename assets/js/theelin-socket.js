/**
 * เชื่อม Socket.IO ทุกหน้าที่มี navbar — อัปเดต badge แชท + เก็บ instance ใน window.__THEELIN_SOCKET__
 */
(function () {
    var cfg = window.__THEELIN_SOCKET_CFG__;
    if (!cfg || !cfg.tokenUrl) return;
    if (window.__THEELIN_SOCKET__) return;

    function updateChatBadge(total) {
        var el = document.getElementById('chatNavUnread');
        if (!el) return;
        var n = parseInt(total, 10) || 0;
        el.textContent = n > 99 ? '99+' : String(n);
        el.classList.toggle('d-none', n < 1);
    }

    function pollUnreadFallback() {
        if (!cfg.chatUnreadUrl) return;
        fetch(cfg.chatUnreadUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) updateChatBadge(d.total);
            })
            .catch(function () {});
    }

    fetch(cfg.tokenUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok || !d.token || !d.url || typeof io === 'undefined') {
                pollUnreadFallback();
                setInterval(pollUnreadFallback, 25000);
                return;
            }
            var socket = io(d.url, {
                auth: { token: d.token },
                transports: ['websocket', 'polling'],
                reconnectionAttempts: 15,
                reconnectionDelay: 1500,
                reconnectionDelayMax: 8000,
            });
            window.__THEELIN_SOCKET__ = socket;

            socket.on('connect', function () {
                window.__THEELIN_SOCKET_READY__ = true;
                try {
                    window.dispatchEvent(new CustomEvent('theelin-socket-ready', { detail: { socket: socket } }));
                } catch (e) {}
            });
            socket.on('disconnect', function () {
                window.__THEELIN_SOCKET_READY__ = false;
            });
            socket.on('connect_error', function () {
                pollUnreadFallback();
            });
            socket.on('chat:unread', function (o) {
                if (o && typeof o.total !== 'undefined') updateChatBadge(o.total);
            });
        })
        .catch(function () {
            pollUnreadFallback();
            setInterval(pollUnreadFallback, 25000);
        });
})();
