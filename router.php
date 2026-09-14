<?php
// Router for PHP's local development server; block database and implementation files.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('/\.(?:sqlite|sqlite3|db|json|py|txt|sql|md|log)$/i', $path) || str_contains($path, '..') || str_starts_with($path, '/build/')) {
    http_response_code(404); exit('Not found');
}
if ($path === '/') { require __DIR__ . '/index.php'; return true; }
return false;
