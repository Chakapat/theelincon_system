<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/foundation.php';
session_unset();
session_destroy();
header('Location: ' . app_path('sign-in.php'));
exit();