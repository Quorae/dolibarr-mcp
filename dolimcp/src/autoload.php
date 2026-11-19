<?php

declare(strict_types=1);

// Loads DoliMcp\A\B from src/A/B.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'DoliMcp\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
