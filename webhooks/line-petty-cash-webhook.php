<?php

declare(strict_types=1);

/**
 * ใช้ endpoint เดียวกับบอทหลักแนะนำให้ตั้ง Webhook ใน LINE Developers เป็น:
 *   .../actions/line-webhook.php
 *
 * ไฟล์นี้ส่งต่อไปที่ line-webhook.php เพื่อให้ URL เก่าที่ชี้มาที่ webhooks/ ยังใช้ได้
 */
require dirname(__DIR__) . '/actions/line-webhook.php';
