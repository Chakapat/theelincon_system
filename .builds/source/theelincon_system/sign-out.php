<?php
session_start();
require_once __DIR__ . '/config/bootstrap.php';
session_unset();
session_destroy();
header('Location: ' . app_path('sign-in.php'));
exit();