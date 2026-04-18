<?php

/**
 * Firebase Realtime Database — การตั้งค่าและค่าคงที่ (ไม่มีความลับในไฟล์นี้)
 *
 * โครงข้อมูลหลักอยู่ใต้ราก RTDB:
 *   /theelincon_mirror/{ชื่อตาราง}/{คีย์หลักหรือคีย์ผสม}/...
 * (การย้ายจาก MySQL ครั้งหนึ่งใช้สคริปต์ scripts/migrate-all-mysql-to-rtdb.js)
 */
declare(strict_types=1);

/** URL Realtime Database (ไม่เป็นความลับ — ควบคุมด้วย Rules + Service Account) */
const THEELINCON_RTDB_DATABASE_URL = 'https://theelincon-db-default-rtdb.asia-southeast1.firebasedatabase.app';

/** รากโฟลเดอร์ที่สคริปต์ย้ายข้อมูลเขียนไว้ (ต้องตรงกับ scripts/migrate-all-mysql-to-rtdb.js) */
const THEELINCON_RTDB_MIRROR_ROOT = 'theelincon_mirror';

/** โฟลเดอร์เก็บไฟล์ Service Account — ชื่อไฟล์จริงอยู่ใน firebase_service_account.php */
const THEELINCON_FIREBASE_CONFIG_DIR = __DIR__;
