<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/database.php';

try {
    $pdo = require_database();
    if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0) {
        fwrite(STDERR, "An administrator already exists. This setup command only creates the first admin.\n");
        exit(1);
    }

    $email = strtolower(trim($argv[1] ?? ''));
    $name = trim($argv[2] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || mb_strlen($name) > 120) {
        fwrite(STDERR, "Usage: php scripts/create-admin.php admin@example.com \"Admin Name\"\n");
        exit(2);
    }

    fwrite(STDOUT, "New admin password (12+ characters): ");
    $isTty = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($isTty) {
        system('stty -echo');
    }
    $password = trim((string) fgets(STDIN));
    if ($isTty) {
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    if (strlen($password) < 12 || strlen($password) > 72) {
        fwrite(STDERR, "Password must be between 12 and 72 characters.\n");
        exit(2);
    }

    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role, email_verified_at)
         VALUES (:name, :email, :password_hash, 'admin', :verified)"
    );
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'verified' => gmdate('Y-m-d H:i:s'),
    ]);
    fwrite(STDOUT, "First administrator created. Sign in at /login.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Could not create administrator: " . $exception->getMessage() . "\n");
    exit(1);
}
