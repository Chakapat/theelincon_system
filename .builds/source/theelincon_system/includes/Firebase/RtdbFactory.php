<?php

declare(strict_types=1);

namespace Theelincon\Firebase;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Database;

/**
 * สร้าง Database (Realtime DB) ครั้งเดียวต่อ request
 */
final class RtdbFactory
{
    private static ?Database $db = null;

    public static function database(): Database
    {
        if (self::$db !== null) {
            return self::$db;
        }

        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        require_once dirname(__DIR__, 2) . '/config/firebase_settings.php';
        require_once dirname(__DIR__, 2) . '/config/firebase_service_account.php';

        $jsonPath = THEELINCON_FIREBASE_CONFIG_DIR . '/' . THEELINCON_FIREBASE_SERVICE_ACCOUNT_BASENAME;
        if (!is_readable($jsonPath)) {
            throw new \RuntimeException('Firebase service account not found: ' . $jsonPath);
        }

        $factory = (new Factory())
            ->withServiceAccount($jsonPath)
            ->withDatabaseUri(THEELINCON_RTDB_DATABASE_URL);

        self::$db = $factory->createDatabase();

        return self::$db;
    }
}
