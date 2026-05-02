<?php

declare(strict_types=1);

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'create';
if (!in_array($action, ['create', 'edit', 'view'], true)) {
    $action = 'create';
}

$viewFile = match ($action) {
    'edit' => 'invoice-edit.php',
    'view' => 'invoice-view.php',
    default => 'invoice-create.php',
};

require __DIR__ . '/' . $viewFile;
