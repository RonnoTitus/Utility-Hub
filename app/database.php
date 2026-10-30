<?php
declare(strict_types=1);

function database_configured(): bool
{
    return trim((string) getenv('DB_DSN')) !== '';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = trim((string) getenv('DB_DSN'));
    if ($dsn === '') {
        throw new RuntimeException('Database is not configured. Set DB_DSN, DB_USER, and DB_PASSWORD in the host environment.');
    }
    $pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function require_database(): PDO
{
    if (!database_configured()) {
        throw new RuntimeException('Database features are unavailable until a MySQL or MariaDB connection is configured.');
    }
    return db();
}

function mail_is_configured(): bool
{
    return filter_var(trim((string) getenv('MAIL_FROM')), FILTER_VALIDATE_EMAIL) !== false;
}

function send_site_email(string $to, string $subject, string $body): bool
{
    $from = trim((string) getenv('MAIL_FROM'));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = str_replace(["\r", "\n"], '', $subject);
    $headers = "From: UtilityHub <{$from}>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return mail($to, $subject, $body, $headers);
}

function site_setting(string $key, string $default = ''): string
{
    if (!database_configured()) {
        return $default;
    }
    try {
        $statement = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (Throwable $exception) {
        error_log('Could not read site setting ' . $key . ': ' . $exception);
        return $default;
    }
}

function save_site_setting(string $key, string $value): void
{
    $statement = require_database()->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute(['key' => $key, 'value' => $value]);
}
