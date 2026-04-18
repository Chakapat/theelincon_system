<?php
session_start();
require_once __DIR__ . '/../config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}

$me = (int) $_SESSION['user_id'];
$apiUrl = app_path('actions/chat-api.php');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แชทภายใน | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f5f0eb; min-height: 100vh; }
        .chat-shell {
            max-width: 1100px;
            margin: 0 auto;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,.08);
            background: #fff;
            min-height: calc(100vh - 7rem);
        }
        .chat-sidebar {
            border-right: 1px solid #eee;
            background: #fffaf7;
            max-height: calc(100vh - 7rem);
            display: flex;
            flex-direction: column;
        }
        .chat-sidebar-header {
            padding: 1rem 1rem 0.75rem;
            border-bottom: 1px solid #f0e6dc;
        }
        .chat-thread-list { overflow-y: auto; flex: 1; }
        .chat-thread-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f5f0eb;
            transition: background .15s;
        }
        .chat-thread-item:hover { background: #fff; }
        .chat-thread-item.active { background: #fff3e6; border-left: 3px solid #fd7e14; }
        .chat-main { display: flex; flex-direction: column; max-height: calc(100vh - 7rem); background: #fff; }
        .chat-main-header {
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid #eee;
            background: linear-gradient(90deg, #fff 0%, #fffaf5 100%);
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.25rem;
            background: #fafafa;
        }
        .chat-bubble {
            max-width: 78%;
            padding: 0.55rem 0.85rem;
            border-radius: 14px;
            margin-bottom: 0.5rem;
            word-break: break-word;
            white-space: pre-wrap;
            line-height: 1.5;
        }
        .chat-bubble.me {
            margin-left: auto;
            background: #fd7e14;
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        .chat-bubble.them {
            margin-right: auto;
            background: #fff;
            border: 1px solid #e8e8e8;
            border-bottom-left-radius: 4px;
        }
        .chat-meta { font-size: 0.7rem; opacity: 0.75; margin-top: 0.15rem; }
        .chat-input-bar {
            border-top: 1px solid #eee;
            padding: 0.75rem 1rem;
            background: #fff;
        }
        .user-pill { font-size: 0.75rem; }
        #userSearchList .chat-thread-item { cursor: pointer; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="container py-3">
    <div class="chat-shell row g-0">
        <div class="col-12 col-md-4 chat-sidebar">
            <div class="chat-sidebar-header">
                <h5 class="fw-bold mb-2"><i class="bi bi-chat-dots-fill text-warning me-2"></i>แชทภายใน</h5>
                <input type="search" id="userSearch" class="form-control form-control-sm rounded-3 border-0 bg-white shadow-sm" placeholder="ค้นหาพนักงานเพื่อเริ่มแชท...">
            </div>
            <div id="userSearchList" class="d-none border-bottom bg-white"></div>
            <div class="small text-muted px-3 py-2 fw-bold">การสนทนา</div>
            <div id="threadList" class="chat-thread-list"></div>
        </div>
        <div class="col-12 col-md-8 chat-main d-flex flex-column">
            <div id="chatEmpty" class="flex-grow-1 d-flex align-items-center justify-content-center text-muted p-5">
                <div class="text-center">
                    <i class="bi bi-chat-heart display-4 text-warning opacity-50"></i>
                    <p class="mt-3 mb-0">เลือกผู้สนทนาจากรายการด้านซ้าย<br>หรือค้นหาชื่อเพื่อเริ่มแชทใหม่</p>
                </div>
            </div>
            <div id="chatActive" class="flex-grow-1 d-none flex-column" style="min-height: 0;">
                <div class="chat-main-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold" id="peerTitle">—</div>
                        <div class="small text-muted" id="peerSub">—</div>
                    </div>
                </div>
                <div id="msgScroll" class="chat-messages"></div>
                <div class="chat-input-bar">
                    <form id="sendForm" class="d-flex gap-2">
                        <input type="hidden" id="peerId" value="">
                        <textarea id="msgBody" class="form-control rounded-3 border-0 bg-light" rows="1" placeholder="พิมพ์ข้อความ..." maxlength="5000" style="resize:none; min-height: 42px; max-height: 120px;"></textarea>
                        <button type="submit" class="btn btn-warning text-white fw-bold px-3 rounded-3"><i class="bi bi-send-fill"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const API = <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>;
    const ME = <?= (int) $me ?>;

    const threadList = document.getElementById('threadList');
    const userSearch = document.getElementById('userSearch');
    const userSearchList = document.getElementById('userSearchList');
    const chatEmpty = document.getElementById('chatEmpty');
    const chatActive = document.getElementById('chatActive');
    const peerTitle = document.getElementById('peerTitle');
    const peerSub = document.getElementById('peerSub');
    const peerIdInput = document.getElementById('peerId');
    const msgScroll = document.getElementById('msgScroll');
    const sendForm = document.getElementById('sendForm');
    const msgBody = document.getElementById('msgBody');

    let activePeer = 0;
    let lastMsgId = 0;
    let pollTimer = null;
    let pollIntervalMs = 1100;
    let socketHandlersBound = false;
    /** กันซ้ำเมื่อได้ทั้งจาก socket event และ callback / polling */
    const renderedMsgIds = new Set();

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function fullName(u) {
        return [u.fname, u.lname].filter(Boolean).join(' ').trim() || u.user_code || ('#' + u.userid);
    }

    function convMatch(peer, m) {
        const a = parseInt(m.sender_id, 10);
        const b = parseInt(m.recipient_id, 10);
        return (a === ME && b === peer) || (a === peer && b === ME);
    }

    function waitSocket(maxMs) {
        return new Promise(function (resolve) {
            var deadline = Date.now() + maxMs;
            (function tick() {
                var s = window.__THEELIN_SOCKET__;
                if (s && s.connected) return resolve(s);
                if (Date.now() >= deadline) return resolve(null);
                setTimeout(tick, 120);
            })();
        });
    }

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startPoll() {
        stopPoll();
        pollTimer = setInterval(function () { pollNew(); }, pollIntervalMs);
    }

    function setPollAggressive(on) {
        pollIntervalMs = on ? 1100 : 3200;
        if (pollTimer) {
            stopPoll();
            startPoll();
        }
    }

    document.addEventListener('visibilitychange', function () {
        setPollAggressive(document.visibilityState === 'visible');
    });

    function bindSocket(s) {
        if (socketHandlersBound || !s) return;
        socketHandlersBound = true;
        s.on('chat:message', function (msg) {
            if (!msg) return;
            var mid = parseInt(msg.id, 10) || 0;
            if (activePeer && convMatch(activePeer, msg)) {
                appendMessage(msg, true);
                if (mid) lastMsgId = Math.max(lastMsgId, mid);
            }
            loadThreads();
        });
        s.on('chat:threads', function () { loadThreads(); });
        s.on('connect', function () { stopPoll(); });
        s.on('disconnect', function () { startPoll(); });
    }

    async function jget(action, params) {
        params = params || {};
        const u = new URL(API, window.location.origin);
        u.searchParams.set('action', action);
        Object.keys(params).forEach(function (k) { u.searchParams.set(k, params[k]); });
        const r = await fetch(u.toString(), { credentials: 'same-origin' });
        return r.json();
    }

    async function jpost(action, body) {
        const u = new URL(API, window.location.origin);
        u.searchParams.set('action', action);
        const r = await fetch(u.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        });
        return r.json();
    }

    function renderThreads(threads) {
        threadList.innerHTML = '';
        if (!threads.length) {
            threadList.innerHTML = '<div class="text-muted small px-3 py-4 text-center">ยังไม่มีประวัติแชท<br>ค้นหาพนักงานเพื่อเริ่มคุย</div>';
            return;
        }
        threads.forEach(function (t) {
            const div = document.createElement('div');
            div.className = 'chat-thread-item' + (parseInt(t.userid, 10) === activePeer ? ' active' : '');
            div.dataset.userid = String(t.userid);
            const un = parseInt(t.unread, 10) || 0;
            const badge = un > 0 ? '<span class="badge bg-danger rounded-pill float-end">' + (un > 99 ? '99+' : un) + '</span>' : '';
            div.innerHTML =
                '<div class="fw-semibold">' + esc(fullName(t)) + ' ' + badge + '</div>' +
                '<div class="small text-muted text-truncate">' + esc(t.user_code || '') + ' · ' + esc(t.role || '') + '</div>';
            div.addEventListener('click', function () { openPeer(parseInt(t.userid, 10), t); });
            threadList.appendChild(div);
        });
    }

    async function loadThreads() {
        const d = await jget('threads');
        if (!d.ok) return;
        renderThreads(d.threads || []);
    }

    function appendMessage(m, scrollBottom) {
        const mid = parseInt(m.id, 10) || 0;
        if (mid && renderedMsgIds.has(mid)) return;
        if (mid) renderedMsgIds.add(mid);
        const row = document.createElement('div');
        const mine = parseInt(m.sender_id, 10) === ME;
        row.className = 'mb-2';
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (mine ? 'me' : 'them');
        bubble.textContent = m.body || '';
        const meta = document.createElement('div');
        meta.className = 'chat-meta ' + (mine ? 'text-end text-white' : 'text-muted');
        const dt = m.created_at ? new Date(String(m.created_at).replace(' ', 'T')) : new Date();
        meta.textContent = dt.toLocaleString('th-TH', { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' });
        row.appendChild(bubble);
        row.appendChild(meta);
        msgScroll.appendChild(row);
        if (scrollBottom) msgScroll.scrollTop = msgScroll.scrollHeight;
    }

    async function pollNew() {
        if (!activePeer) return;
        let d;
        if (lastMsgId > 0) {
            d = await jget('messages', { with: String(activePeer), after: String(lastMsgId) });
        } else {
            d = await jget('messages', { with: String(activePeer) });
        }
        if (!d.ok || !d.messages || !d.messages.length) return;
        let appended = false;
        d.messages.forEach(function (m) {
            const szBefore = renderedMsgIds.size;
            appendMessage(m, true);
            if (renderedMsgIds.size > szBefore) appended = true;
            const idn = parseInt(m.id, 10) || 0;
            if (idn) lastMsgId = Math.max(lastMsgId, idn);
        });
        if (!appended) return;
        await jpost('mark_read', { from: activePeer });
        loadThreads();
    }

    async function openPeer(uid, metaRow) {
        uid = parseInt(uid, 10);
        if (!uid || uid === ME) return;
        activePeer = uid;
        peerIdInput.value = String(uid);
        lastMsgId = 0;
        renderedMsgIds.clear();
        msgScroll.innerHTML = '';

        var title = fullName(metaRow || {});
        var sub = (metaRow && metaRow.user_code) ? metaRow.user_code + ' · ' + (metaRow.role || '') : '';
        if (!metaRow || !metaRow.fname) {
            const u = await jget('user', { id: String(uid) });
            if (u.ok && u.user) {
                title = fullName(u.user);
                sub = (u.user.user_code || '') + ' · ' + (u.user.role || '');
            }
        }
        peerTitle.textContent = title;
        peerSub.textContent = sub;

        chatEmpty.classList.add('d-none');
        chatActive.classList.remove('d-none');
        chatActive.classList.add('d-flex');

        var s = window.__THEELIN_SOCKET__;
        if (s && s.connected) {
            s.emit('chat:read', { from: uid }, function () { loadThreads(); });
        } else {
            await jpost('mark_read', { from: uid });
        }

        const d = await jget('messages', { with: String(uid) });
        if (!d.ok) return;
        (d.messages || []).forEach(function (m) {
            appendMessage(m, false);
            lastMsgId = Math.max(lastMsgId, parseInt(m.id, 10) || 0);
        });
        msgScroll.scrollTop = msgScroll.scrollHeight;

        if (s && s.connected) stopPoll();
        else startPoll();
        loadThreads();
    }

    sendForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const to = parseInt(peerIdInput.value, 10);
        const body = msgBody.value.trim();
        if (!to || !body) return;
        var s = window.__THEELIN_SOCKET__;
        if (s && s.connected) {
            s.emit('chat:send', { to: to, body: body }, function (res) {
                if (res && res.ok) {
                    msgBody.value = '';
                    if (res.message) {
                        appendMessage(res.message, true);
                        const mid = parseInt(res.message.id, 10) || 0;
                        if (mid) lastMsgId = Math.max(lastMsgId, mid);
                    }
                    loadThreads();
                } else {
                    alert(res && res.error ? 'ส่งไม่สำเร็จ' : 'ส่งไม่สำเร็จ');
                }
            });
        } else {
            jpost('send', { to: to, body: body }).then(function (d) {
                if (d.ok && d.message) {
                    msgBody.value = '';
                    appendMessage(d.message, true);
                    lastMsgId = Math.max(lastMsgId, parseInt(d.message.id, 10) || 0);
                    loadThreads();
                }
            });
        }
    });

    msgBody.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendForm.requestSubmit();
        }
    });

    var searchT = null;
    userSearch.addEventListener('input', function () {
        clearTimeout(searchT);
        var q = userSearch.value.trim();
        searchT = setTimeout(function () {
            if (q.length < 1) {
                userSearchList.classList.add('d-none');
                userSearchList.innerHTML = '';
                return;
            }
            jget('users', { q: q }).then(function (d) {
                if (!d.ok) return;
                userSearchList.innerHTML = '';
                userSearchList.classList.remove('d-none');
                (d.users || []).forEach(function (u) {
                    const div = document.createElement('div');
                    div.className = 'chat-thread-item';
                    div.innerHTML =
                        '<div class="fw-semibold">' + esc(fullName(u)) + '</div>' +
                        '<div class="small text-muted">' + esc(u.user_code || '') + ' · ' + esc(u.role || '') + '</div>';
                    div.addEventListener('click', function () {
                        userSearch.value = '';
                        userSearchList.classList.add('d-none');
                        openPeer(parseInt(u.userid, 10), u);
                    });
                    userSearchList.appendChild(div);
                });
                if (!d.users.length) {
                    userSearchList.innerHTML = '<div class="small text-muted px-3 py-2">ไม่พบรายชื่อ</div>';
                }
            });
        }, 280);
    });

    document.addEventListener('click', function (e) {
        if (!userSearchList.contains(e.target) && e.target !== userSearch) {
            userSearchList.classList.add('d-none');
        }
    });

    window.addEventListener('theelin-socket-ready', function (ev) {
        var s = ev.detail && ev.detail.socket;
        if (!s) return;
        bindSocket(s);
        stopPoll();
    });

    var s0 = window.__THEELIN_SOCKET__;
    if (s0 && s0.connected) {
        bindSocket(s0);
        stopPoll();
    } else {
        waitSocket(12000).then(function (s) {
            if (s) {
                bindSocket(s);
                if (s.connected) stopPoll();
                else s.once('connect', function () { stopPoll(); });
            } else {
                startPoll();
            }
        });
    }

    loadThreads();
    setInterval(loadThreads, 60000);
})();
</script>
</body>
</html>
