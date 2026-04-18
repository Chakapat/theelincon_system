<?php

declare(strict_types=1);

/**
 * LINE Messaging API settings
 * - Set real values in your server environment (recommended)
 * - Fallback values below are safe defaults (disabled)
 */
if (!defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN')) {
    define('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', getenv('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ?: '');
}

if (!defined('LINE_MESSAGING_CHANNEL_SECRET')) {
    define('LINE_MESSAGING_CHANNEL_SECRET', getenv('LINE_MESSAGING_CHANNEL_SECRET') ?: '');
}

if (!defined('LINE_TARGET_USER_ID')) {
    define('LINE_TARGET_USER_ID', getenv('LINE_TARGET_USER_ID') ?: '');
}

if (!defined('LINE_TARGET_GROUP_ID')) {
    define('LINE_TARGET_GROUP_ID', getenv('LINE_TARGET_GROUP_ID') ?: '');
}

if (!defined('LINE_BOT_USER_ID')) {
    define('LINE_BOT_USER_ID', getenv('LINE_BOT_USER_ID') ?: '');
}

