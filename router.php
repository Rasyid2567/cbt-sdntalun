<?php
/**
 * Router Script untuk PHP Built-in Development Server (php -S)
 * Penggunaan: php -S localhost:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$path = __DIR__ . $uri;

// 1. Jika URL menunjuk langsung ke file statis yang ada (CSS, JS, Gambar, SVG, dll)
if ($uri !== '/' && file_exists($path) && !is_dir($path)) {
    return false; // Biarkan web server internal melayani file statis secara native
}

// 2. Jika URL menunjuk ke file .php tanpa ekstensi (clean URL)
if (file_exists($path . '.php') && !is_dir($path . '.php')) {
    require $path . '.php';
    return true;
}

// 3. Jika URL adalah direktori yang memiliki index.php
if (is_dir($path) && file_exists($path . '/index.php')) {
    require $path . '/index.php';
    return true;
}

// 4. Default fallback ke root index.php
require __DIR__ . '/index.php';
