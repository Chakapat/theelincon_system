<?php

declare(strict_types=1);

/**
 * App shell landmarks — skip link, main region, chrome end (FAB + mobile nav).
 */

if (!function_exists('tnc_shell_skip_link')) {
    function tnc_shell_skip_link(): void
    {
        echo '<a class="tnc-skip-link" href="#main-content">ข้ามไปเนื้อหาหลัก</a>' . "\n";
    }
}

if (!function_exists('tnc_shell_main_open')) {
    function tnc_shell_main_open(): void
    {
        if (!empty($GLOBALS['tnc_shell_main_open'])) {
            return;
        }
        $GLOBALS['tnc_shell_main_open'] = true;
        echo '<main id="main-content" tabindex="-1">' . "\n";
    }
}

if (!function_exists('tnc_shell_main_close')) {
    function tnc_shell_main_close(): void
    {
        if (empty($GLOBALS['tnc_shell_main_open']) || !empty($GLOBALS['tnc_shell_main_closed'])) {
            return;
        }
        $GLOBALS['tnc_shell_main_closed'] = true;
        echo '</main>' . "\n";
    }
}

if (!function_exists('tnc_shell_chrome_end')) {
    function tnc_shell_chrome_end(): void
    {
        if (empty($GLOBALS['tnc_shell_main_open'])) {
            return;
        }

        tnc_shell_main_close();

        if (!isset($_SESSION['user_id'])) {
            return;
        }

        include dirname(__DIR__) . '/components/hub-fab.php';
        include dirname(__DIR__) . '/components/mobile-bottom-nav.php';
    }
}
