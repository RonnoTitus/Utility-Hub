<?php
declare(strict_types=1);

// Compatibility entry point for hosts that cannot change the document root.
// In production, point the web server at public/ so application files remain
// outside the document root.
require __DIR__ . '/public/index.php';
