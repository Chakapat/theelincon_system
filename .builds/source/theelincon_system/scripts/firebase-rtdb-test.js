/**
 * ทดสอบ Firebase Admin + Realtime Database หลังวางไฟล์ Service Account ใน config/
 *
 * รัน: node scripts/firebase-rtdb-test.js
 *
 * ถ้าดาวน์โหลด key ใหม่และชื่อไฟล์เปลี่ยน ให้แก้ SERVICE_ACCOUNT_FILE ด้านล่าง
 */
const path = require('path');
const admin = require('firebase-admin');

const SERVICE_ACCOUNT_FILE = 'theelincon-db-firebase-adminsdk-fbsvc-febd82eb4b.json';
const DATABASE_URL =
  'https://theelincon-db-default-rtdb.asia-southeast1.firebasedatabase.app';

const serviceAccountPath = path.join(__dirname, '..', 'config', SERVICE_ACCOUNT_FILE);
// eslint-disable-next-line import/no-dynamic-require, global-require
const serviceAccount = require(serviceAccountPath);

admin.initializeApp({
  credential: admin.credential.cert(serviceAccount),
  databaseURL: DATABASE_URL,
});

async function main() {
  const ref = admin.database().ref('migration_test');
  await ref.set({
    message: 'ทดสอบจาก Node',
    at: new Date().toISOString(),
  });
  console.log('สำเร็จ: เขียนข้อมูลที่ /migration_test แล้ว — เปิด Firebase Console → Realtime Database → Data เพื่อตรวจสอบ');
  process.exit(0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
