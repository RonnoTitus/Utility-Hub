<?php
declare(strict_types=1);

// Many hosts and reverse proxies terminate HTTPS before PHP receives the
// request, so honor X-Forwarded-Proto when deciding whether cookies are secure.
$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header_remove('X-Powered-By');
if (getenv('APP_ENV') === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
if ($https) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}

/** Cache-busting URL for a file under public/ (changes whenever the file changes). */
function asset_url(string $path): string
{
    $file = dirname(__DIR__) . '/public/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return '/' . ltrim($path, '/') . '?v=' . $version;
}

/** Escape untrusted values for HTML text or quoted attributes. */
function h(mixed $value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_text(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

/** Return the canonical public URL only when the host owner has configured it. */
function public_url(string $path = ''): string
{
    $base = rtrim((string) (getenv('APP_URL') ?: ''), '/');
    return $base === '' ? '' : $base . '/' . ltrim($path, '/');
}

/** Generate a session-bound CSRF token for form submissions. */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_is_valid(): bool
{
    $sent = $_POST['_csrf'] ?? '';
    return is_string($sent)
        && isset($_SESSION['_csrf'])
        && hash_equals((string) $_SESSION['_csrf'], $sent);
}

/** Reusable supported currency list; UGX is a zero-decimal currency. */
function supported_currencies(): array
{
    return [
        'UGX' => ['name' => 'Ugandan shilling', 'symbol' => 'UGX', 'digits' => 0],
        'USD' => ['name' => 'US dollar', 'symbol' => '$', 'digits' => 2],
        'EUR' => ['name' => 'Euro', 'symbol' => '€', 'digits' => 2],
        'GBP' => ['name' => 'British pound', 'symbol' => '£', 'digits' => 2],
        'KES' => ['name' => 'Kenyan shilling', 'symbol' => 'KSh', 'digits' => 2],
    ];
}

/** Format a currency amount from its smallest unit (cents, or whole UGX). */
function format_minor(int $minor, string $currency): string
{
    $currencies = supported_currencies();
    $currency = isset($currencies[$currency]) ? $currency : 'USD';
    $negative = $minor < 0;
    $minor = abs($minor);
    $digits = $currencies[$currency]['digits'];
    $scale = $digits === 0 ? 1 : 100;
    $whole = intdiv($minor, $scale);
    $fraction = $digits === 0 ? '' : '.' . str_pad((string) ($minor % $scale), 2, '0', STR_PAD_LEFT);
    $number = number_format($whole, 0, '.', ',') . $fraction;
    $symbol = $currencies[$currency]['symbol'];
    $formatted = in_array($currency, ['USD', 'EUR', 'GBP'], true)
        ? $symbol . $number
        : $number . ' ' . $symbol;
    return ($negative ? '−' : '') . $formatted;
}

function render_layout(string $title, string $description, string $content, string $path, array $crumbs = [], string $head = ''): never
{
    $siteTitle = 'UtilityHub';
    $canonical = public_url($path);
    $activePath = $path;
    $crumbMarkup = '';
    if ($crumbs !== []) {
        $crumbMarkup = '<nav aria-label="Breadcrumb" class="small text-secondary mb-3"><ol class="breadcrumb mb-0">';
        foreach ($crumbs as $index => $crumb) {
            $last = $index === array_key_last($crumbs);
            $crumbMarkup .= $last
                ? '<li class="breadcrumb-item active" aria-current="page">' . h($crumb['label']) . '</li>'
                : '<li class="breadcrumb-item"><a href="' . h($crumb['url']) . '">' . h($crumb['label']) . '</a></li>';
        }
        $crumbMarkup .= '</ol></nav>';
    }

    header('Content-Type: text/html; charset=utf-8');
    header_remove('X-Powered-By');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        header('Cache-Control: no-store, private');
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data: blob:; font-src 'self' https://cdn.jsdelivr.net data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="description" content="' . h($description) . '">';
    echo '<meta name="theme-color" content="#145b4a">';
    echo '<meta property="og:title" content="' . h($title . ' · ' . $siteTitle) . '">';
    echo '<meta property="og:description" content="' . h($description) . '">';
    echo '<meta property="og:type" content="website">';
    echo '<meta property="og:site_name" content="' . h($siteTitle) . '">';
    echo '<meta name="twitter:card" content="summary">';
    echo '<meta name="twitter:title" content="' . h($title . ' · ' . $siteTitle) . '">';
    echo '<meta name="twitter:description" content="' . h($description) . '">';
    if ($canonical !== '') {
        echo '<link rel="canonical" href="' . h($canonical) . '">';
        echo '<meta property="og:url" content="' . h($canonical) . '">';
    }
    echo '<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">';
    echo '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">';
    echo '<link rel="stylesheet" href="' . h(asset_url('assets/site.css')) . '">';
    echo '<title>' . h($title . ' · ' . $siteTitle) . '</title>';
    echo $head;
    echo '</head><body>';
    echo '<a class="skip-link" href="#main-content">Skip to content</a>';
    echo '<nav class="navbar navbar-expand-lg navbar-dark hub-nav"><div class="container">';
    echo '<a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="/" aria-label="UtilityHub home"><span class="brand-mark">U</span>UtilityHub</a>';
    echo '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-nav" aria-controls="main-nav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>';
    echo '<div class="collapse navbar-collapse" id="main-nav"><ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">';
    $navItems = [
        ['/tools', 'All tools'],
        ['/tools/qr-code-generator', 'QR generator'],
        ['/tools/invoice-generator', 'Invoice'],
        ['/articles', 'Guides'],
        ['/about', 'About'],
        [isset($_SESSION['user_id']) ? '/dashboard' : '/login', isset($_SESSION['user_id']) ? 'My account' : 'Sign in'],
    ];
    if (($_SESSION['role'] ?? '') === 'admin') {
        $navItems[] = ['/admin', 'Admin'];
    }
    foreach ($navItems as [$url, $label]) {
        $active = $activePath === $url || ($url === '/tools' && str_starts_with($activePath, '/tools/'));
        echo '<li class="nav-item"><a class="nav-link' . ($active ? ' active' : '') . '" href="' . h($url) . '"' . ($active ? ' aria-current="page"' : '') . '>' . h($label) . '</a></li>';
    }
    echo '<li class="nav-item ms-lg-2"><button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle light or dark mode">◐</button></li>';
    echo '</ul></div></div></nav>';

    echo '<!-- ADSENSE TOP BANNER -->';
    $announcement = database_configured() ? site_setting('site_announcement') : '';
    if ($announcement !== '') {
        echo '<div class="container pt-3"><div class="alert alert-info mb-0" role="status">' . h($announcement) . '</div></div>';
    }
    echo '<main id="main-content" class="container hub-main">';
    echo $crumbMarkup;
    echo $content;
    echo '</main>';

    echo '<footer class="hub-footer"><div class="container py-5"><div class="row g-4">';
    echo '<div class="col-md-5"><a class="footer-brand" href="/">UtilityHub</a><p class="mt-2 mb-0 text-secondary">Free, practical tools for everyday work. Designed to be quick, clear, and private.</p></div>';
    echo '<div class="col-6 col-md-3"><h2 class="footer-heading">Explore</h2><a href="/tools">All tools</a><a href="/articles">Guides</a><a href="/about">About us</a><a href="/contact">Contact</a></div>';
    echo '<div class="col-6 col-md-4"><h2 class="footer-heading">Information</h2><a href="/faq">FAQ</a><a href="/privacy">Privacy</a><a href="/terms">Terms</a><a href="/cookies">Cookie policy</a><a href="/disclaimer">Disclaimer</a></div>';
    echo '</div><div class="footer-bottom mt-4 pt-3">© ' . date('Y') . ' UtilityHub. Tools are free to use.</div></div></footer>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '<script src="' . h(asset_url('assets/site.js')) . '" defer></script>';
    echo '</body></html>';
    exit;
}

function render_error(int $status, string $title, string $message): never
{
    http_response_code($status);
    $content = '<section class="empty-state"><p class="eyebrow">Error ' . h($status) . '</p><h1>' . h($title) . '</h1><p>' . h($message) . '</p><a class="btn btn-brand mt-3" href="/">Return home</a></section>';
    render_layout($title, $message, $content, '/error');
}

function render_tool_header(string $title, string $description, string $category, string $route): string
{
    return '<div class="tool-heading"><p class="eyebrow">' . h($category) . '</p><h1>' . h($title) . '</h1><p class="lead">' . h($description) . '</p></div>';
}

function form_notice(array $errors, ?string $success = null): string
{
    $html = '';
    if ($success !== null) {
        $html .= '<div class="alert alert-success" role="status">' . h($success) . '</div>';
    }
    if ($errors !== []) {
        $html .= '<div class="alert alert-danger" role="alert"><strong>Please check the form:</strong><ul class="mb-0 mt-2">';
        foreach (array_unique($errors) as $error) {
            $html .= '<li>' . h($error) . '</li>';
        }
        $html .= '</ul></div>';
    }
    return $html;
}
