/**
 * Socket.IO server — แชทเรียลไทม์ + แจ้งจำนวนข้อความยังไม่อ่าน
 *
 * รัน: npm install && npm run socket
 * ตั้งค่า: คัดลอก server/env.example.txt เป็น server/.env แล้วแก้ DB / SOCKET_IO_SECRET
 */

'use strict';

const http = require('http');
const crypto = require('crypto');
const express = require('express');
const cors = require('cors');
const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
require('dotenv').config({ path: require('path').join(__dirname, '.env') });

const PORT = parseInt(process.env.PORT || '3001', 10);
const SOCKET_SECRET = process.env.SOCKET_IO_SECRET || 'theelincon_dev_socket_CHANGE_ME';

const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'theelincon_database',
    charset: 'utf8mb4',
    waitForConnections: true,
    connectionLimit: 20,
});

function b64urlDecode(s) {
    let x = String(s).replace(/-/g, '+').replace(/_/g, '/');
    while (x.length % 4) x += '=';
    return Buffer.from(x, 'base64').toString('utf8');
}

function verifyToken(token) {
    try {
        const raw = b64urlDecode(token);
        const parts = raw.split('|');
        if (parts.length !== 3) return null;
        const userId = parseInt(parts[0], 10);
        const exp = parseInt(parts[1], 10);
        const sig = parts[2];
        if (!userId || !exp || !sig) return null;
        if (Math.floor(Date.now() / 1000) > exp) return null;
        const check = crypto.createHmac('sha256', SOCKET_SECRET).update(`${userId}|${exp}`).digest('hex');
        if (sig !== check) return null;
        return userId;
    } catch {
        return null;
    }
}

async function unreadTotal(userId) {
    const [[row]] = await pool.query(
        'SELECT COUNT(*) AS c FROM chat_messages WHERE recipient_id = ? AND read_at IS NULL',
        [userId]
    );
    return parseInt(row.c, 10) || 0;
}

const app = express();
app.use(cors({ origin: true, credentials: true }));
app.get('/health', (req, res) => res.json({ ok: true }));

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
            if (typeof cb === 'function') cb(obj);
        };
        try {
            const to = parseInt(payload && payload.to, 10);
            let body = String((payload && payload.body) || '').trim();
            if (!to || to === uid || !body) {
                return reply({ ok: false, error: 'invalid' });
            }
            if (body.length > 5000) body = body.slice(0, 5000);

            const [userRows] = await pool.query('SELECT userid FROM users WHERE userid = ? LIMIT 1', [to]);
            if (!userRows.length) {
                return reply({ ok: false, error: 'user_not_found' });
            }

            const [insResult] = await pool.query(
                'INSERT INTO chat_messages (sender_id, recipient_id, body) VALUES (?, ?, ?)',
                [uid, to, body]
            );
            const id = insResult.insertId;
            const [[row]] = await pool.query(
                'SELECT id, sender_id, recipient_id, body, created_at FROM chat_messages WHERE id = ? LIMIT 1',
                [id]
            );

            io.to(`user:${uid}`).emit('chat:message', row);
            io.to(`user:${to}`).emit('chat:message', row);

            const total = await unreadTotal(to);
            io.to(`user:${to}`).emit('chat:unread', { total });

            io.to(`user:${uid}`).emit('chat:threads');
            io.to(`user:${to}`).emit('chat:threads');

            reply({ ok: true, message: row });
        } catch (e) {
            console.error('chat:send', e);
            reply({ ok: false, error: 'server' });
        }
    });

    socket.on('chat:read', async (payload, cb) => {
        const reply = (obj) => {
            if (typeof cb === 'function') cb(obj);
        };
        try {
            const from = parseInt(payload && payload.from, 10);
            if (!from || from === uid) {
                return reply({ ok: false, error: 'invalid' });
            }
            await pool.query(
                'UPDATE chat_messages SET read_at = NOW() WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL',
                [uid, from]
            );
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
    console.log(`Socket.IO listening on ${PORT}`);
});
