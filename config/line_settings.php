<?php

declare(strict_types=1);

/**
 * LINE Messaging API settings
 * - Set real values in your server environment (recommended)
 * - Fallback values below are safe defaults (disabled)
 */
if (!defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN')) {
    define('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', getenv('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ?: 'GePvjm1ySBL8XJcfkMxKlxPIwcI/0w+gEsnaH2w1IdJwTizveuc7F48KbnKpv/4Og/tZ+Qwpp3Sh2aNZ6P2b2TnT3MxnBMiSksHJBe8XRymw4C26MKiaC3a+Y3uVNBoELVt2X4vm5YJldaXLLFAztwdB04t89/1O/w1cDnyilFU=');
}

if (!defined('LINE_MESSAGING_CHANNEL_SECRET')) {
    define('LINE_MESSAGING_CHANNEL_SECRET', getenv('LINE_MESSAGING_CHANNEL_SECRET') ?: '5ecd2b1b5f1fc792a4e15b57ee1fc23b');
}

if (!defined('LINE_TARGET_USER_ID')) {
    define('LINE_TARGET_USER_ID', getenv('LINE_TARGET_USER_ID') ?: '');
}

if (!defined('LINE_TARGET_GROUP_ID')) {
    define('LINE_TARGET_GROUP_ID', getenv('LINE_TARGET_GROUP_ID') ?: 'C6bda6fbecd62df0c3d1048051e1733a5');
}

if (!defined('LINE_BOT_USER_ID')) {
    define('LINE_BOT_USER_ID', getenv('LINE_BOT_USER_ID') ?: '');
}

/**
 * LINE userId ผู้มีสิทธิ์อนุมัติจากปุ่มในกลุ่ม (คนเดียว)
 * หากว่าง = ไม่บังคับล็อกผู้อนุมัติ
 */
if (!defined('LINE_APPROVER_USER_ID')) {
    define('LINE_APPROVER_USER_ID', getenv('LINE_APPROVER_USER_ID') ?: 'Ua0ce1416d25e68624cfbbf9e9fc6a8a5');
}

