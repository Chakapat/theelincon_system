/**
 * Socket.IO server — แชทเรียลไทม์ + แจ้งจำนวนข้อความยังไม่อ่าน
 * ใช้ Firebase Realtime Database ชุดเดียวกับ PHP (theelincon_mirror/…) — ไม่ใช้ MySQL
 *
 * รัน: npm install && npm run socket
 * ตั้งค่า: คัดลอก server/env.example.txt เป็น server/.env
 */

'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const express = require('express');
const cors = require('cors');
const { Server } = require('socket.io');
const admin = require('firebase-admin');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const PORT = parseInt(process.env.PORT || '3001', 10);
const SOCKET_SECRET = process.env.SOCKET_IO_SECRET || 'theelincon_dev_socket_CHANGE_ME';

const DEFAULT_DB_URL = 'https://theelincon-db-default-rtdb.asia-southeast1.firebasedatabase.app';
const DEFAULT_MIRROR_ROOT = 'theelincon_mirror';
const DEFAULT_SA_REL = path.join('..', 'config', 'theelincon-db-firebase-adminsdk-fbsvc-febd82eb4b.json');

const DATABASE_URL = process.env.FIREBASE_DATABASE_URL || DEFAULT_DB_URL;
const MIRROR_ROOT = process.env.RTDB_MIRROR_ROOT || DEFAULT_MIRROR_ROOT;

const serviceAccountPath = path.resolve(
    __dirname,
    process.env.FIREBASE_SERVICE_ACCOUNT_PATH || DEFAULT_SA_REL
);

if (!fs.existsSync(serviceAccountPath)) {
    console.error('[socket] Firebase service account not found:', serviceAccountPath);
    console.error('Set FIREBASE_SERVICE_ACCOUNT_PATH in server/.env or place the JSON under config/');
    process.exit(1);
}

const serviceAccount = JSON.parse(fs.readFileSync(serviceAccountPath, 'utf8'));

admin.initializeApp({
    credential: admin.credential.cert(serviceAccount),
    databaseURL: DATABASE_URL,
});

const rtdb = admin.database();

function formatMysqlDatetime(d = new Date()) {
    const pad = (n) => String(n).padStart(2, '0');
    return (
        `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ` +
        `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
    );
}

function tableRef(name) {
    return rtdb.ref(`${MIRROR_ROOT}/${name}`);
}

async function loadTableKeyed(name) {
    const snap = await tableRef(name).once('value');
    const v = snap.val();
    if (!v || typeof v !== 'object') {
        return {};
    }
    const out = {};
    for (const pk of Object.keys(v)) {
        const row = v[pk];
        if (row && typeof row === 'object') {
            out[String(pk)] = row;
        }
    }
    return out;
}

async function nextNumericId(table, pkColumn = 'id') {
    const keyed = await loadTableKeyed(table);
    let max = 0;
    for (const key of Object.keys(keyed)) {
        const row = keyed[key];
        const kc = /^\d+$/.test(key) ? parseInt(key, 10) : 0;
        const kr =
            row[pkColumn] != null && !Number.isNaN(Number(row[pkColumn]))
                ? parseInt(String(row[pkColumn]), 10)
                : 0;
        max = Math.max(max, kc, kr);
    }
    return max + 1;
}

async function unreadTotal(userId) {
    const keyed = await loadTableKeyed('chat_messages');
    let c = 0;
    for (const pk of Object.keys(keyed)) {
        const m = keyed[pk];
        if (!m) {
            continue;
        }
        if (parseInt(String(m.recipient_id ?? 0), 10) === userId && !m.read_at) {
            ++c;
        }
    }
    return c;
}

async function userRowExists(userId) {
    const snap = await tableRef('users').child(String(userId)).once('value');
    return snap.exists();
}

function b64urlDecode(s) {
    let x = String(s).replace(/-/g, '+').replace(/_/g, '/');
    while (x.length % 4) {
        x += '=';
    }
    return Buffer.from(x, 'base64').toString('utf8');
}

function verifyToken(token) {
    try {
        const raw = b64urlDecode(token);
        const parts = raw.split('|');
        if (parts.length !== 3) {
            return null;
        }
        const userId = parseInt(parts[0], 10);
        const exp = parseInt(parts[1], 10);
        const sig = parts[2];
        if (!userId || !exp || !sig) {
            return null;
        }
        if (Math.floor(Date.now() / 1000) > exp) {
            return null;
        }
        const check = crypto.createHmac('sha256', SOCKET_SECRET).update(`${userId}|${exp}`).digest('hex');
        if (sig !== check) {
            return null;
        }
        return userId;
    } catch {
        return null;
    }
}

const app = express();
app.use(cors({ origin: true, credentials: true }));
app.get('/health', (req, res) => res.json({ ok: true, backend: 'firebase_rtdb' }));

const server = http.createServer(app);
const io = new Server(server, {
    cors: { origin: true, credentials: true },
    transports: ['websocket', 'polling'],
});

io.use((socket, next) => {
    const token = socket.handshake.auth && socket.handshake.auth.token;
    const userId = verifyToken(token);
    if (!userId) {
        return next(new Error('auth_failed'));
    }
    socket.data.userId = userId;
    next();
});

io.on('connection', async (socket) => {
    const uid = socket.data.userId;
    socket.join(`user:${uid}`);

    try {
        const total = await unreadTotal(uid);
        socket.emit('chat:unread', { total });
    } catch (e) {
        console.error('unreadTotal', e.message);
    }

    socket.on('chat:send', async (payload, cb) => {
        const reply = (obj) => {
            if (typeof cb === 'function') {
                cb(obj);
            }
        };
        try {
            const to = parseInt(payload && payload.to, 10);
            let body = String((payload && payload.body) || '').trim();
            if (!to || to === uid || !body) {
                return reply({ ok: false, error: 'invalid' });
            }
            if (body.length > 5000) {
                body = body.slice(0, 5000);
            }

            const exists = await userRowExists(to);
            if (!exists) {
                return reply({ ok: false, error: 'user_not_found' });
            }

            const newId = await nextNumericId('chat_messages', 'id');
            const pk = String(newId);
            const createdAt = formatMysqlDatetime();
            const row = {
                id: newId,
                sender_id: uid,
                recipient_id: to,
                body,
                created_at: createdAt,
            };

            await tableRef('chat_messages').child(pk).set(row);

            const snap = await tableRef('chat_messages').child(pk).once('value');
            const rowOut = snap.val() || row;

            io.to(`user:${uid}`).emit('chat:message', rowOut);
            io.to(`user:${to}`).emit('chat:message', rowOut);

            const totalTo = await unreadTotal(to);
            io.to(`user:${to}`).emit('chat:unread', { total: totalTo });

            io.to(`user:${uid}`).emit('chat:threads');
            io.to(`user:${to}`).emit('chat:threads');

            reply({ ok: true, message: rowOut });
        } catch (e) {
            console.error('chat:send', e);
            reply({ ok: false, error: 'server' });
        }
    });

    socket.on('chat:read', async (payload, cb) => {
        const reply = (obj) => {
            if (typeof cb === 'function') {
                cb(obj);
            }
        };
        try {
            const from = parseInt(payload && payload.from, 10);
            if (!from || from === uid) {
                return reply({ ok: false, error: 'invalid' });
            }

            const keyed = await loadTableKeyed('chat_messages');
            const nowStr = formatMysqlDatetime();
            /** @type {Record<string, unknown>} */
            const updates = {};

            for (const pk of Object.keys(keyed)) {
                const m = keyed[pk];
                if (!m) {
                    continue;
                }
                if (
                    parseInt(String(m.recipient_id ?? 0), 10) === uid &&
                    parseInt(String(m.sender_id ?? 0), 10) === from &&
                    !m.read_at
                ) {
                    updates[`${MIRROR_ROOT}/chat_messages/${pk}/read_at`] = nowStr;
                }
            }

            if (Object.keys(updates).length > 0) {
                await rtdb.ref().update(updates);
            }

            const total = await unreadTotal(uid);
            io.to(`user:${uid}`).emit('chat:unread', { total });
            io.to(`user:${uid}`).emit('chat:threads');
            io.to(`user:${from}`).emit('chat:threads');
            reply({ ok: true });
        } catch (e) {
            console.error('chat:read', e);
            reply({ ok: false, error: 'server' });
        }
    });
});

server.listen(PORT, () => {
    console.log(`Socket.IO listening on ${PORT} (Firebase RTDB mirror: ${MIRROR_ROOT})`);
});
