/**
 * ย้ายข้อมูล MySQL (theelincon_database) ทุกตาราง → Firebase Realtime Database
 * โครง: /theelincon_mirror/{table}/{rowKey}/...
 *
 * รัน: node scripts/migrate-all-mysql-to-rtdb.js
 *
 * ต้องมี: mysql2, firebase-admin; ไฟล์ Service Account ใน config/
 */
const path = require('path');
const mysql = require('mysql2/promise');
const admin = require('firebase-admin');

const SERVICE_ACCOUNT_FILE = 'theelincon-db-firebase-adminsdk-fbsvc-febd82eb4b.json';
const DATABASE_URL =
  'https://theelincon-db-default-rtdb.asia-southeast1.firebasedatabase.app';
const RT_ROOT = 'theelincon_mirror';
const MYSQL_DB = 'theelincon_database';

const BATCH_PATHS = 150;

const serviceAccount = require(path.join(__dirname, '..', 'config', SERVICE_ACCOUNT_FILE));

admin.initializeApp({
  credential: admin.credential.cert(serviceAccount),
  databaseURL: DATABASE_URL,
});

function sanitizeKeyPart(v) {
  return String(v).replace(/[.#$\[\]/]/g, '_');
}

function serializeValue(val) {
  if (val === null || val === undefined) return null;
  if (Buffer.isBuffer(val)) {
    return { _binary: true, base64: val.toString('base64') };
  }
  if (val instanceof Date) {
    return val.toISOString();
  }
  if (typeof val === 'bigint') {
    return val.toString();
  }
  return val;
}

function rowToPlain(row) {
  const out = {};
  for (const [k, v] of Object.entries(row)) {
    out[k] = serializeValue(v);
  }
  return out;
}

function buildRowKey(pks, row) {
  if (pks.length === 0) return null;
  if (pks.length === 1) return sanitizeKeyPart(row[pks[0]]);
  return pks.map((pk) => sanitizeKeyPart(row[pk])).join('__');
}

async function getTables(conn) {
  const [rows] = await conn.query(
    'SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = ? ORDER BY TABLE_NAME',
    [MYSQL_DB]
  );
  return rows.map((r) => r.TABLE_NAME);
}

async function getPrimaryKeys(conn, table) {
  const [rows] = await conn.query(
    `SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
     ORDER BY ORDINAL_POSITION`,
    [MYSQL_DB, table]
  );
  return rows.map((r) => r.COLUMN_NAME);
}

async function migrateTable(conn, table, pks, rootRef) {
  const [rows] = await conn.query(`SELECT * FROM \`${table}\``);
  let batch = {};
  let count = 0;
  let total = 0;

  const flush = async () => {
    if (Object.keys(batch).length === 0) return;
    await rootRef.update(batch);
    total += Object.keys(batch).length;
    batch = {};
  };

  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    let key = buildRowKey(pks, row);
    if (!key) {
      key = `row_${i}`;
    }
    const relPath = `${RT_ROOT}/${table}/${key}`;
    batch[relPath] = rowToPlain(row);
    count++;
    if (count >= BATCH_PATHS) {
      await flush();
      count = 0;
    }
  }
  await flush();
  return { rows: rows.length, nodes: total };
}

async function main() {
  const conn = await mysql.createConnection({
    host: 'localhost',
    user: 'root',
    password: '',
    database: MYSQL_DB,
    dateStrings: false,
  });

  const rootRef = admin.database().ref();

  const tables = await getTables(conn);
  const summary = {};

  await rootRef.child(`${RT_ROOT}/_meta`).set({
    source: MYSQL_DB,
    migratedAt: new Date().toISOString(),
    tables: tables.length,
  });

  for (const table of tables) {
    const pks = await getPrimaryKeys(conn, table);
    process.stdout.write(`Migrating ${table} ... `);
    const r = await migrateTable(conn, table, pks, rootRef);
    summary[table] = r;
    console.log(`${r.rows} rows OK`);
  }

  await conn.end();
  console.log('\nDone. Path root: /' + RT_ROOT);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
