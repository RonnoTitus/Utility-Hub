<?php
declare(strict_types=1);

function current_user(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
        return null;
    }
    if (!database_configured()) {
        throw new RuntimeException('Your session is signed in, but the database connection is unavailable.');
    }
    $statement = db()->prepare('SELECT id, name, email, role, email_verified_at FROM users WHERE id = :id');
    $statement->execute(['id' => (int) $userId]);
    $user = $statement->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: /login');
        exit;
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        render_error(403, 'Admin access required', 'You do not have permission to view this page.');
    }
    return $user;
}

function create_action_token(): string
{
    return bin2hex(random_bytes(32));
}

function save_invoice_for_user(array $invoice): int
{
    $user = require_user();
    $pdo = require_database();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO saved_invoices
             (user_id, invoice_number, currency, issue_date, due_date, business_name, business_contact_json,
              client_name, client_contact_json, subtotal_minor, discount_minor, discount_type, discount_basis_points,
              tax_minor, tax_basis_points, total_minor, notes, payment_instructions)
             VALUES (:user_id, :invoice_number, :currency, :issue_date, :due_date, :business_name, :business_contact,
              :client_name, :client_contact, :subtotal, :discount, :discount_type, :discount_rate,
              :tax, :tax_rate, :total, :notes, :payment)'
        );
        $statement->execute([
            'user_id' => $user['id'],
            'invoice_number' => $invoice['invoice_number'],
            'currency' => $invoice['currency'],
            'issue_date' => $invoice['issue_date'],
            'due_date' => $invoice['due_date'] !== '' ? $invoice['due_date'] : null,
            'business_name' => $invoice['business_name'],
            'business_contact' => json_encode([
                'email' => $invoice['business_email'],
                'phone' => $invoice['business_phone'],
                'address' => $invoice['business_address'],
            ], JSON_THROW_ON_ERROR),
            'client_name' => $invoice['client_name'],
            'client_contact' => json_encode([
                'email' => $invoice['client_email'],
                'phone' => $invoice['client_phone'],
                'address' => $invoice['client_address'],
            ], JSON_THROW_ON_ERROR),
            'subtotal' => $invoice['subtotal_minor'],
            'discount' => $invoice['discount_minor'],
            'discount_type' => $invoice['discount_type'],
            'discount_rate' => $invoice['discount_basis_points'],
            'tax' => $invoice['tax_minor'],
            'tax_rate' => $invoice['tax_basis_points'],
            'total' => $invoice['total_minor'],
            'notes' => $invoice['notes'],
            'payment' => $invoice['payment_instructions'],
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $itemStatement = $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, position, description, quantity_hundredths, unit_price_minor, amount_minor)
             VALUES (:invoice_id, :position, :description, :quantity, :price, :amount)'
        );
        foreach ($invoice['rows'] as $position => $item) {
            $itemStatement->execute([
                'invoice_id' => $invoiceId,
                'position' => $position + 1,
                'description' => $item['description'],
                'quantity' => $item['quantity_hundredths'],
                'price' => $item['rate_minor'],
                'amount' => $item['amount_minor'],
            ]);
        }
        $pdo->commit();
        return $invoiceId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function write_admin_audit(array $actor, string $action, string $entityType = '', string $entityId = ''): void
{
    $statement = require_database()->prepare(
        'INSERT INTO admin_audit_logs (actor_user_id, action, entity_type, entity_id)
         VALUES (:actor, :action, :type, :entity)'
    );
    $statement->execute([
        'actor' => $actor['id'],
        'action' => $action,
        'type' => $entityType !== '' ? $entityType : null,
        'entity' => $entityId !== '' ? $entityId : null,
    ]);
}

function auth_attempt_allowed(PDO $pdo, string $action, string $identifier, int $limit, int $windowSeconds): bool
{
    $hash = hash('sha256', strtolower(trim($identifier)));
    $now = time();
    $statement = $pdo->prepare('SELECT window_started, attempts FROM auth_rate_limits WHERE action = :action AND identifier_hash = :hash');
    $statement->execute(['action' => $action, 'hash' => $hash]);
    $existing = $statement->fetch();
    if ($existing && ($now - strtotime((string) $existing['window_started'] . ' UTC')) < $windowSeconds) {
        if ((int) $existing['attempts'] >= $limit) {
            return false;
        }
        $pdo->prepare('UPDATE auth_rate_limits SET attempts = attempts + 1 WHERE action = :action AND identifier_hash = :hash')
            ->execute(['action' => $action, 'hash' => $hash]);
    } else {
        $pdo->prepare(
            'INSERT INTO auth_rate_limits (action, identifier_hash, window_started, attempts)
             VALUES (:action, :hash, :started, 1)
             ON DUPLICATE KEY UPDATE window_started = VALUES(window_started), attempts = 1'
        )->execute(['action' => $action, 'hash' => $hash, 'started' => gmdate('Y-m-d H:i:s', $now)]);
    }
    return true;
}

function auth_form(string $action, string $contents, string $submit): string
{
    return '<form method="post" action="' . h($action) . '" class="tool-form">' . csrf_field() . $contents
        . '<button class="btn btn-brand w-100 mt-3" type="submit">' . h($submit) . '</button></form>';
}

function render_account_page(string $title, string $description, string $content, string $path): never
{
    render_layout(
        $title,
        $description,
        '<section class="content-page account-page"><p class="eyebrow">Your UtilityHub account</p><h1>' . h($title) . '</h1><p class="lead">' . h($description) . '</p><div class="tool-panel">' . $content . '</div></section>',
        $path
    );
}

function account_route(string $path, bool $isPost, array $postErrors): never
{
    $errors = $postErrors;
    $status = '';

    try {
        if ($path === '/logout') {
            if ($isPost && $errors === []) {
                unset($_SESSION['user_id'], $_SESSION['role']);
                session_regenerate_id(true);
                $_SESSION['_csrf'] = bin2hex(random_bytes(32));
            }
            header('Location: /');
            exit;
        }
        $pdo = require_database();

        if ($path === '/register') {
            if (!mail_is_configured() || public_url('/') === '') {
                throw new RuntimeException('Account registration requires APP_URL, a valid MAIL_FROM address, and working PHP mail delivery so verification links can be sent.');
            }
            if ($isPost && $errors === []) {
                $name = safe_text($_POST['name'] ?? '');
                $email = strtolower(safe_text($_POST['email'] ?? ''));
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
                if ($name === '' || mb_strlen($name) > 120) $errors[] = 'Enter a name up to 120 characters.';
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) $errors[] = 'Enter a valid email address.';
                if (strlen($password) < 12 || strlen($password) > 72) $errors[] = 'Use at least 12 characters and keep the password within 72 bytes.';
                if ($password !== $confirmation) $errors[] = 'The passwords do not match.';
                if (!auth_attempt_allowed($pdo, 'register', $email, 3, 3600)) $errors[] = 'Too many account attempts. Wait an hour and try again.';
                if ($errors === []) {
                    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email');
                    $check->execute(['email' => $email]);
                    if ($check->fetch()) {
                        $errors[] = 'An account could not be created with those details. Try signing in or resetting your password.';
                    } else {
                        $token = create_action_token();
                        $pdo->beginTransaction();
                        try {
                            $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :hash, :role)');
                            $insert->execute([
                                'name' => $name, 'email' => $email,
                                'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'user',
                            ]);
                            $userId = (int) $pdo->lastInsertId();
                            $tokenInsert = $pdo->prepare('INSERT INTO email_verification_tokens (token_hash, user_id, expires_at) VALUES (:hash, :user, :expires)');
                            $tokenInsert->execute([
                                'hash' => hash('sha256', $token), 'user' => $userId,
                                'expires' => gmdate('Y-m-d H:i:s', time() + 86400),
                            ]);
                            $link = public_url('/verify-email?token=' . rawurlencode($token));
                            if ($link === '' || !send_site_email($email, 'Verify your UtilityHub account', "Open this link within 24 hours to verify your email:\n\n{$link}\n")) {
                                throw new RuntimeException('The verification email could not be sent. Check APP_URL, MAIL_FROM, and your host mail configuration.');
                            }
                            $pdo->commit();
                            $status = 'Account created. Check your email for the verification link before signing in.';
                        } catch (Throwable $exception) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            throw $exception;
                        }
                    }
                }
            }
            $fields = '<label class="form-label" for="name">Name</label><input class="form-control mb-3" id="name" name="name" maxlength="120" required value="' . h(text_input('name')) . '">'
                . '<label class="form-label" for="email">Email</label><input class="form-control mb-3" type="email" id="email" name="email" maxlength="254" required autocomplete="email" value="' . h(text_input('email')) . '">'
                . '<label class="form-label" for="password">Password</label><input class="form-control mb-3" type="password" id="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password">'
                . '<label class="form-label" for="password_confirmation">Confirm password</label><input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" required autocomplete="new-password">';
            render_account_page('Create an account', 'Register for optional saved invoices and tool favourites.', form_notice($errors, $status) . auth_form($path, $fields, 'Create account') . '<p class="mt-3">Already registered? <a href="/login">Sign in</a>.</p>', $path);
        }

        if ($path === '/verify-email') {
            $token = safe_text($_GET['token'] ?? '');
            if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
                $statement = $pdo->prepare('SELECT user_id FROM email_verification_tokens WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now');
                $statement->execute(['hash' => hash('sha256', $token), 'now' => gmdate('Y-m-d H:i:s')]);
                $verification = $statement->fetch();
                if ($verification) {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE users SET email_verified_at = :now WHERE id = :id')->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $verification['user_id']]);
                    $pdo->prepare('UPDATE email_verification_tokens SET used_at = :now WHERE user_id = :id AND used_at IS NULL')->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $verification['user_id']]);
                    $pdo->commit();
                    $status = 'Email verified. You can now sign in.';
                } else {
                    $errors[] = 'That verification link is invalid, expired, or already used.';
                }
            } else {
                $errors[] = 'A valid verification link is required.';
            }
            render_account_page('Email verification', 'Confirm your email before signing in.', form_notice($errors, $status) . '<p><a href="/login">Go to sign in</a></p>', $path);
        }

        if ($path === '/login') {
            if ($isPost && $errors === []) {
                $email = strtolower(safe_text($_POST['email'] ?? ''));
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $allowed = auth_attempt_allowed($pdo, 'login', $email, 8, 900);
                if (!$allowed) {
                    $errors[] = 'Too many sign-in attempts. Wait 15 minutes and try again.';
                }
                $statement = $pdo->prepare('SELECT id, role, password_hash, email_verified_at FROM users WHERE email = :email LIMIT 1');
                $statement->execute(['email' => $email]);
                $user = $statement->fetch();
                if (!$allowed) {
                    // Keep the lockout response stable and do not verify a password.
                } elseif (!$user || !password_verify($password, $user['password_hash'])) {
                    $errors[] = 'Email or password was not recognized.';
                } elseif ($user['email_verified_at'] === null) {
                    $errors[] = 'Verify your email using the link we sent before signing in.';
                } else {
                    $pdo->prepare('DELETE FROM auth_rate_limits WHERE action = :action AND identifier_hash = :hash')
                        ->execute(['action' => 'login', 'hash' => hash('sha256', $email)]);
                    session_regenerate_id(true);
                    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['role'] = $user['role'];
                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([
                            'hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id'],
                        ]);
                    }
                    header('Location: /dashboard');
                    exit;
                }
            }
            $fields = '<label class="form-label" for="email">Email</label><input class="form-control mb-3" type="email" id="email" name="email" required autocomplete="email" value="' . h(text_input('email')) . '"><label class="form-label" for="password">Password</label><input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">';
            render_account_page('Sign in', 'Sign in to view saved invoices and favourite tools.', form_notice($errors, $status) . auth_form($path, $fields, 'Sign in') . '<div class="d-flex justify-content-between mt-3"><a href="/register">Create account</a><a href="/password-reset">Forgot password?</a></div>', $path);
        }

        if ($path === '/password-reset') {
            if (!mail_is_configured() || public_url('/') === '') {
                throw new RuntimeException('Password reset requires APP_URL, a valid MAIL_FROM address, and working PHP mail delivery.');
            }
            if ($isPost && $errors === []) {
                $email = strtolower(safe_text($_POST['email'] ?? ''));
                $allowed = auth_attempt_allowed($pdo, 'reset', $email, 4, 3600);
                if ($allowed && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email');
                    $statement->execute(['email' => $email]);
                    $user = $statement->fetch();
                    if ($user) {
                        $token = create_action_token();
                        $hash = hash('sha256', $token);
                        $pdo->prepare('INSERT INTO password_reset_tokens (token_hash, user_id, expires_at) VALUES (:hash, :user, :expires)')->execute([
                            'hash' => $hash, 'user' => $user['id'], 'expires' => gmdate('Y-m-d H:i:s', time() + 3600),
                        ]);
                        $link = public_url('/password-reset?token=' . rawurlencode($token));
                        if ($link === '' || !send_site_email($email, 'Reset your UtilityHub password', "Open this link within one hour to change your password:\n\n{$link}\n")) {
                            error_log('UtilityHub password reset email delivery failed for identifier hash ' . hash('sha256', $email));
                        }
                    }
                }
                $status = 'If an account matches that email, a reset link will be sent.';
            }
            $token = safe_text($_GET['token'] ?? '');
            if ($token !== '') {
                $fields = '<input type="hidden" name="token" value="' . h($token) . '"><label class="form-label" for="password">New password</label><input class="form-control mb-3" type="password" id="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"><label class="form-label" for="password_confirmation">Confirm password</label><input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" required autocomplete="new-password">';
                if ($isPost && $errors === []) {
                    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                    $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
                    if (strlen($password) < 12 || strlen($password) > 72) $errors[] = 'Use at least 12 characters and keep the password within 72 bytes.';
                    if ($password !== $confirmation) $errors[] = 'The passwords do not match.';
                    $check = $pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now');
                    $check->execute(['hash' => hash('sha256', $token), 'now' => gmdate('Y-m-d H:i:s')]);
                    $reset = $check->fetch();
                    if (!$reset) $errors[] = 'That reset link is invalid, expired, or already used.';
                    if ($errors === []) {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE users SET password_hash = :password WHERE id = :id')->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $reset['user_id']]);
                        $pdo->prepare('UPDATE password_reset_tokens SET used_at = :now WHERE user_id = :id AND used_at IS NULL')->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $reset['user_id']]);
                        $pdo->commit();
                        $status = 'Password changed. Sign in with your new password.';
                    }
                }
                render_account_page('Choose a new password', 'Reset your account password.', form_notice($errors, $status) . auth_form($path . '?token=' . rawurlencode($token), $fields, 'Save new password'), $path);
            }
            $fields = '<label class="form-label" for="email">Account email</label><input class="form-control" type="email" id="email" name="email" required autocomplete="email" value="' . h(text_input('email')) . '">';
            render_account_page('Reset password', 'We’ll send a short-lived reset link if the email is registered.', form_notice($errors, $status) . auth_form($path, $fields, 'Send reset link') . '<p class="mt-3"><a href="/login">Back to sign in</a></p>', $path);
        }

        if ($path === '/dashboard') {
            $user = require_user();
            if ($isPost && $errors === [] && safe_text($_POST['action'] ?? '') === 'delete-invoice') {
                $delete = $pdo->prepare('DELETE FROM saved_invoices WHERE id = :id AND user_id = :user');
                $delete->execute(['id' => (int) ($_POST['invoice_id'] ?? 0), 'user' => $user['id']]);
                $status = 'Saved invoice removed.';
            }
            $statement = $pdo->prepare('SELECT id, invoice_number, currency, issue_date, client_name, total_minor FROM saved_invoices WHERE user_id = :user ORDER BY issue_date DESC, id DESC');
            $statement->execute(['user' => $user['id']]);
            $rows = $statement->fetchAll();
            $items = '';
            foreach ($rows as $row) {
                $items .= '<tr><td><a href="/invoices/' . (int) $row['id'] . '">' . h($row['invoice_number']) . '</a></td><td>' . h($row['client_name']) . '</td><td>' . h($row['issue_date']) . '</td><td>' . h(format_minor((int) $row['total_minor'], $row['currency'])) . '</td><td><form method="post" action="/dashboard" data-confirm="Delete this saved invoice?">' . csrf_field() . '<input type="hidden" name="action" value="delete-invoice"><input type="hidden" name="invoice_id" value="' . (int) $row['id'] . '"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form></td></tr>';
            }
            $content = '<section class="content-page"><p class="eyebrow">Your account</p><h1>Welcome, ' . h($user['name']) . '</h1><p class="lead">Saved invoice data is private to your account. Save is optional; public calculator inputs are not stored.</p>'
                . form_notice($errors, $status) . '<div class="d-flex gap-2 my-3"><a class="btn btn-outline-brand" href="/favorites">Favourite tools</a><form method="post" action="/logout">' . csrf_field() . '<button class="btn btn-link" type="submit">Sign out</button></form></div><h2>Saved invoices</h2>';
            $content .= $rows ? '<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Invoice</th><th>Client</th><th>Date</th><th>Total</th><th></th></tr></thead><tbody>' . $items . '</tbody></table></div>' : '<p>You have no saved invoices yet. Use the optional save checkbox when generating an invoice.</p>';
            $content .= '</section>';
            render_layout('Your dashboard', 'Your saved invoices and favourite tools.', $content, $path);
        }

        if (preg_match('~^/invoices/([0-9]+)$~', $path, $invoiceMatch)) {
            $user = require_user();
            $statement = $pdo->prepare('SELECT * FROM saved_invoices WHERE id = :id AND user_id = :user LIMIT 1');
            $statement->execute(['id' => (int) $invoiceMatch[1], 'user' => $user['id']]);
            $saved = $statement->fetch();
            if (!$saved) {
                render_error(404, 'Invoice not found', 'This saved invoice is unavailable in your account.');
            }
            $itemsQuery = $pdo->prepare('SELECT description, quantity_hundredths, unit_price_minor, amount_minor FROM invoice_items WHERE invoice_id = :id ORDER BY position');
            $itemsQuery->execute(['id' => $saved['id']]);
            $savedItems = $itemsQuery->fetchAll();
            $businessContact = json_decode((string) $saved['business_contact_json'], true) ?: [];
            $clientContact = json_decode((string) $saved['client_contact_json'], true) ?: [];
            $clientDetails = implode(' · ', array_filter([$clientContact['email'] ?? '', $clientContact['phone'] ?? '']));
            $content = '<section class="content-page"><div class="invoice-actions no-print"><button type="button" class="btn btn-brand" data-print>Print / Save as PDF</button><a class="btn btn-outline-brand" href="/dashboard">Back to dashboard</a></div><article class="invoice-paper"><header class="invoice-top"><div><small>FROM</small><h2>' . h($saved['business_name']) . '</h2><p>' . nl2br(h($businessContact['address'] ?? '')) . '</p><p>' . h(implode(' · ', array_filter([$businessContact['email'] ?? '', $businessContact['phone'] ?? '']))) . '</p></div><div class="text-end"><p class="invoice-word">INVOICE</p><strong>' . h($saved['invoice_number']) . '</strong><p>Issued ' . h($saved['issue_date']) . '</p>' . ($saved['due_date'] ? '<p>Due ' . h($saved['due_date']) . '</p>' : '') . '</div></header><div class="invoice-billto"><small>BILL TO</small><strong>' . h($saved['client_name']) . '</strong><p>' . h($clientDetails) . '</p><p>' . nl2br(h($clientContact['address'] ?? '')) . '</p></div><div class="table-responsive"><table class="table invoice-table"><thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Amount</th></tr></thead><tbody>';
            foreach ($savedItems as $item) {
                $content .= '<tr><td>' . h($item['description']) . '</td><td class="text-end">' . h(number_format((int) $item['quantity_hundredths'] / 100, 2)) . '</td><td class="text-end">' . h(format_minor((int) $item['unit_price_minor'], $saved['currency'])) . '</td><td class="text-end">' . h(format_minor((int) $item['amount_minor'], $saved['currency'])) . '</td></tr>';
            }
            $content .= '</tbody></table></div><div class="invoice-totals"><div><span>Subtotal</span><strong>' . h(format_minor((int) $saved['subtotal_minor'], $saved['currency'])) . '</strong></div><div><span>Discount</span><strong>−' . h(format_minor((int) $saved['discount_minor'], $saved['currency'])) . '</strong></div><div><span>Tax (' . number_format((int) $saved['tax_basis_points'] / 100, 2) . '%)</span><strong>' . h(format_minor((int) $saved['tax_minor'], $saved['currency'])) . '</strong></div><div class="invoice-total"><span>Total due</span><strong>' . h(format_minor((int) $saved['total_minor'], $saved['currency'])) . '</strong></div></div>';
            if ($saved['payment_instructions'] || $saved['notes']) {
                $content .= '<div class="invoice-footer">' . ($saved['payment_instructions'] ? '<div><small>PAYMENT INSTRUCTIONS</small><p>' . nl2br(h($saved['payment_instructions'])) . '</p></div>' : '') . ($saved['notes'] ? '<div><small>NOTES</small><p>' . nl2br(h($saved['notes'])) . '</p></div>' : '') . '</div>';
            }
            $content .= '<p class="invoice-thanks">Thank you for your business.</p></article></section>';
            render_layout('Saved invoice ' . $saved['invoice_number'], 'View and print an invoice saved to your account.', $content, $path);
        }

        if ($path === '/favorites') {
            $user = require_user();
            if ($isPost && $errors === []) {
                $slug = safe_text($_POST['tool_slug'] ?? '');
                $action = safe_text($_POST['action'] ?? '');
                $validSlugs = ['qr-code-generator', 'invoice-generator', 'bmi-calculator', 'income-budget-calculator', 'percentage-calculator', 'word-counter'];
                if (in_array($slug, $validSlugs, true) && $action === 'toggle') {
                    $exists = $pdo->prepare('SELECT 1 FROM user_favorites WHERE user_id = :user AND tool_slug = :slug');
                    $exists->execute(['user' => $user['id'], 'slug' => $slug]);
                    if ($exists->fetchColumn()) {
                        $pdo->prepare('DELETE FROM user_favorites WHERE user_id = :user AND tool_slug = :slug')->execute(['user' => $user['id'], 'slug' => $slug]);
                        $status = 'Tool removed from favourites.';
                    } else {
                        $pdo->prepare('INSERT INTO user_favorites (user_id, tool_slug) VALUES (:user, :slug)')->execute(['user' => $user['id'], 'slug' => $slug]);
                        $status = 'Tool added to favourites.';
                    }
                } elseif (in_array($slug, $validSlugs, true) && $action === 'remove') {
                    $pdo->prepare('DELETE FROM user_favorites WHERE user_id = :user AND tool_slug = :slug')->execute(['user' => $user['id'], 'slug' => $slug]);
                    $status = 'Tool removed from favourites.';
                }
            }
            $statement = $pdo->prepare('SELECT tool_slug FROM user_favorites WHERE user_id = :user ORDER BY created_at DESC');
            $statement->execute(['user' => $user['id']]);
            $slugs = $statement->fetchAll(PDO::FETCH_COLUMN);
            $cards = '';
            foreach ($slugs as $slug) {
                $slug = safe_text($slug);
                $cards .= '<div class="col-md-6"><article class="tool-card"><h2>' . h(ucwords(str_replace('-', ' ', $slug))) . '</h2><a class="tool-link" href="/tools/' . h($slug) . '">Open tool →</a><form method="post" action="/favorites" class="mt-2">' . csrf_field() . '<input type="hidden" name="action" value="remove"><input type="hidden" name="tool_slug" value="' . h($slug) . '"><button class="btn btn-sm btn-outline-danger" type="submit">Remove favourite</button></form></article></div>';
            }
            $content = '<section class="content-page"><p class="eyebrow">Your account</p><h1>Favourite tools</h1>' . form_notice($errors, $status) . ($cards ? '<div class="row g-3">' . $cards . '</div>' : '<p>No favourites yet. Add tools from their pages while signed in.</p>') . '<p class="mt-4"><a href="/dashboard">Back to dashboard</a></p></section>';
            render_layout('Favourite tools', 'Tools saved to your account.', $content, $path);
        }

        if ($path === '/admin') {
            $admin = require_admin();
            if ($isPost && $errors === []) {
                $action = safe_text($_POST['action'] ?? '');
                if ($action === 'update-message') {
                    $statusValue = safe_text($_POST['message_status'] ?? '');
                    if (in_array($statusValue, ['new', 'read', 'replied', 'archived'], true)) {
                        $messageId = (int) ($_POST['message_id'] ?? 0);
                        $pdo->prepare('UPDATE contact_messages SET status = :status WHERE id = :id')->execute(['status' => $statusValue, 'id' => $messageId]);
                        write_admin_audit($admin, 'contact_status_changed', 'contact_message', (string) $messageId);
                    }
                } elseif ($action === 'delete-message') {
                    $messageId = (int) ($_POST['message_id'] ?? 0);
                    $pdo->prepare('DELETE FROM contact_messages WHERE id = :id')->execute(['id' => $messageId]);
                    write_admin_audit($admin, 'contact_message_deleted', 'contact_message', (string) $messageId);
                    $status = 'Contact message deleted.';
                } elseif ($action === 'save-setting') {
                    save_site_setting('site_announcement', mb_substr(safe_text($_POST['announcement'] ?? ''), 0, 300));
                    save_site_setting('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0');
                    write_admin_audit($admin, 'site_settings_updated', 'settings');
                    $status = 'Site settings updated.';
                } elseif ($action === 'publish-post') {
                    $id = (int) ($_POST['post_id'] ?? 0);
                    $state = safe_text($_POST['state'] ?? '');
                    if (in_array($state, ['draft', 'published'], true)) {
                        $pdo->prepare('UPDATE blog_posts SET status = :status, published_at = CASE WHEN :publish = 1 THEN COALESCE(published_at, UTC_TIMESTAMP()) ELSE NULL END WHERE id = :id')
                            ->execute(['status' => $state, 'publish' => $state === 'published' ? 1 : 0, 'id' => $id]);
                        write_admin_audit($admin, 'blog_status_changed', 'blog_post', (string) $id);
                    }
                } elseif ($action === 'delete-post') {
                    $id = (int) ($_POST['post_id'] ?? 0);
                    $pdo->prepare('DELETE FROM blog_posts WHERE id = :id')->execute(['id' => $id]);
                    write_admin_audit($admin, 'blog_post_deleted', 'blog_post', (string) $id);
                    $status = 'Article deleted.';
                } elseif ($action === 'update-post') {
                    $id = (int) ($_POST['post_id'] ?? 0);
                    $title = safe_text($_POST['title'] ?? '');
                    $excerpt = safe_text($_POST['excerpt'] ?? '');
                    $body = safe_text($_POST['body'] ?? '');
                    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
                    if ($title === '' || mb_strlen($title) > 220 || $excerpt === '' || mb_strlen($excerpt) > 400 || $body === '' || $slug === '') {
                        $errors[] = 'Add a title, short excerpt, and article body.';
                    } else {
                        try {
                            $pdo->prepare('UPDATE blog_posts SET slug = :slug, title = :title, excerpt = :excerpt, body_markdown = :body WHERE id = :id')
                                ->execute(['slug' => $slug, 'title' => $title, 'excerpt' => $excerpt, 'body' => $body, 'id' => $id]);
                            write_admin_audit($admin, 'blog_post_updated', 'blog_post', (string) $id);
                            $status = 'Guide updated.';
                        } catch (PDOException) {
                            $errors[] = 'That title may already be in use. Choose a different title.';
                        }
                    }
                } elseif ($action === 'create-post') {
                    $title = safe_text($_POST['title'] ?? '');
                    $excerpt = safe_text($_POST['excerpt'] ?? '');
                    $body = safe_text($_POST['body'] ?? '');
                    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
                    if ($title === '' || mb_strlen($title) > 220 || $excerpt === '' || mb_strlen($excerpt) > 400 || $body === '' || $slug === '') {
                        $errors[] = 'Add a title, short excerpt, and article body.';
                    } else {
                        try {
                            $pdo->prepare('INSERT INTO blog_posts (author_user_id, slug, title, excerpt, body_markdown) VALUES (:author, :slug, :title, :excerpt, :body)')
                                ->execute(['author' => $admin['id'], 'slug' => $slug, 'title' => $title, 'excerpt' => $excerpt, 'body' => $body]);
                            write_admin_audit($admin, 'blog_post_created', 'blog_post', (string) $pdo->lastInsertId());
                            $status = 'Draft article created.';
                        } catch (PDOException) {
                            $errors[] = 'That article title may already be in use. Choose a different title.';
                        }
                    }
                }
            }
            $messageRows = $pdo->query('SELECT id, name, email, message, status, created_at FROM contact_messages ORDER BY created_at DESC LIMIT 50')->fetchAll();
            $messages = '';
            foreach ($messageRows as $message) {
                $messages .= '<article class="article-card mb-3"><div class="d-flex justify-content-between gap-2"><strong>' . h($message['name']) . ' · ' . h($message['email']) . '</strong><small>' . h($message['created_at']) . '</small></div><p class="mt-2">' . nl2br(h($message['message'])) . '</p><div class="d-flex gap-2"><form method="post" action="/admin" class="d-flex gap-2">' . csrf_field() . '<input type="hidden" name="action" value="update-message"><input type="hidden" name="message_id" value="' . (int) $message['id'] . '"><select class="form-select form-select-sm" name="message_status"><option value="new"' . select_option($message['status'], 'new') . '>New</option><option value="read"' . select_option($message['status'], 'read') . '>Read</option><option value="replied"' . select_option($message['status'], 'replied') . '>Replied</option><option value="archived"' . select_option($message['status'], 'archived') . '>Archived</option></select><button class="btn btn-sm btn-outline-brand">Update</button></form><form method="post" action="/admin" data-confirm="Permanently delete this contact message?">' . csrf_field() . '<input type="hidden" name="action" value="delete-message"><input type="hidden" name="message_id" value="' . (int) $message['id'] . '"><button class="btn btn-sm btn-outline-danger">Delete</button></form></div></article>';
            }
            $posts = $pdo->query('SELECT id, title, slug, status FROM blog_posts ORDER BY created_at DESC LIMIT 50')->fetchAll();
            $postList = '';
            foreach ($posts as $post) {
                $postList .= '<li>' . h($post['title']) . ' <small>(' . h($post['status']) . ')</small> <a class="btn btn-sm btn-link" href="/admin?edit_post=' . (int) $post['id'] . '">Edit</a><form method="post" action="/admin" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="publish-post"><input type="hidden" name="post_id" value="' . (int) $post['id'] . '"><input type="hidden" name="state" value="' . ($post['status'] === 'published' ? 'draft' : 'published') . '"><button class="btn btn-sm btn-link">' . ($post['status'] === 'published' ? 'Unpublish' : 'Publish') . '</button></form><form method="post" action="/admin" class="d-inline" data-confirm="Delete this guide?">' . csrf_field() . '<input type="hidden" name="action" value="delete-post"><input type="hidden" name="post_id" value="' . (int) $post['id'] . '"><button class="btn btn-sm btn-link text-danger">Delete</button></form></li>';
            }
            $editPost = null;
            if (isset($_GET['edit_post']) && ctype_digit((string) $_GET['edit_post'])) {
                $editStatement = $pdo->prepare('SELECT id, title, excerpt, body_markdown FROM blog_posts WHERE id = :id');
                $editStatement->execute(['id' => (int) $_GET['edit_post']]);
                $editPost = $editStatement->fetch() ?: null;
            }
            $postEditor = $editPost
                ? '<h2>Edit guide</h2><form method="post" action="/admin" class="tool-panel mb-4">' . csrf_field() . '<input type="hidden" name="action" value="update-post"><input type="hidden" name="post_id" value="' . (int) $editPost['id'] . '"><label class="form-label">Title</label><input class="form-control mb-2" name="title" maxlength="220" required value="' . h($editPost['title']) . '"><label class="form-label">Short description</label><input class="form-control mb-2" name="excerpt" maxlength="400" required value="' . h($editPost['excerpt']) . '"><label class="form-label">Article text</label><textarea class="form-control mb-2" name="body" rows="7" required>' . h($editPost['body_markdown']) . '</textarea><button class="btn btn-brand">Update guide</button> <a href="/admin">Cancel</a></form>'
                : '';
            $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $userRows = $pdo->query('SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 25')->fetchAll();
            $usageRows = $pdo->query('SELECT tool_slug, COUNT(*) AS opens FROM tool_usage_events WHERE event_date >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY) GROUP BY tool_slug ORDER BY opens DESC')->fetchAll();
            $usageList = '';
            foreach ($usageRows as $usage) {
                $usageList .= '<li>' . h(ucwords(str_replace('-', ' ', $usage['tool_slug']))) . ': ' . (int) $usage['opens'] . ' opens</li>';
            }
            $userList = '';
            foreach ($userRows as $account) {
                $userList .= '<tr><td>' . (int) $account['id'] . '</td><td>' . h($account['name']) . '</td><td>' . h($account['email']) . '</td><td>' . h($account['role']) . '</td><td>' . h($account['created_at']) . '</td></tr>';
            }
            $auditRows = $pdo->query('SELECT action, entity_type, entity_id, created_at FROM admin_audit_logs ORDER BY id DESC LIMIT 15')->fetchAll();
            $auditList = '';
            foreach ($auditRows as $audit) {
                $auditList .= '<li>' . h($audit['created_at']) . ' · ' . h($audit['action']) . ' · ' . h($audit['entity_type'] ?? '') . ' ' . h($audit['entity_id'] ?? '') . '</li>';
            }
            $content = '<section class="content-page"><p class="eyebrow">Administration · ' . h($admin['email']) . '</p><h1>Site dashboard</h1>' . form_notice($errors, $status)
                . '<div class="row g-3 mb-4"><div class="col-md-4"><div class="benefit-card"><b>' . $users . '</b><p>Registered accounts</p></div></div><div class="col-md-4"><div class="benefit-card"><b>' . count($messageRows) . '</b><p>Contact messages shown</p></div></div><div class="col-md-4"><div class="benefit-card"><b>' . count($posts) . '</b><p>Articles</p></div></div></div>'
                . '<h2>Site settings</h2><form method="post" action="/admin" class="tool-panel mb-4">' . csrf_field() . '<input type="hidden" name="action" value="save-setting"><label class="form-label" for="announcement">Public announcement (optional)</label><input class="form-control mb-3" id="announcement" name="announcement" maxlength="300" value="' . h(site_setting('site_announcement')) . '"><label><input type="checkbox" name="maintenance_mode" value="1"' . (site_setting('maintenance_mode') === '1' ? ' checked' : '') . '> Enable maintenance mode</label><button class="btn btn-brand d-block mt-3">Save settings</button></form>'
                . $postEditor . '<h2>Create a guide</h2><form method="post" action="/admin" class="tool-panel mb-4">' . csrf_field() . '<input type="hidden" name="action" value="create-post"><label class="form-label">Title</label><input class="form-control mb-2" name="title" maxlength="220" required><label class="form-label">Short description</label><input class="form-control mb-2" name="excerpt" maxlength="400" required><label class="form-label">Article text</label><textarea class="form-control mb-2" name="body" rows="7" required></textarea><button class="btn btn-brand">Save draft</button></form><ul>' . $postList . '</ul>'
                . '<h2 class="mt-4">Accounts</h2><div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Created</th></tr></thead><tbody>' . $userList . '</tbody></table></div><h2>Tool opens · last 30 days</h2><ul>' . ($usageList ?: '<li>No anonymous tool usage events yet.</li>') . '</ul>'
                . '<h2 class="mt-4">Contact inbox</h2>' . ($messages ?: '<p>No messages received.</p>') . '<h2 class="mt-4">Recent admin activity</h2><ul>' . ($auditList ?: '<li>No admin actions recorded yet.</li>') . '</ul><form method="post" action="/logout">' . csrf_field() . '<button class="btn btn-link">Sign out</button></form></section>';
            render_layout('Administration', 'Manage site settings, articles, and contact messages.', $content, $path);
        }
    } catch (Throwable $exception) {
        error_log('UtilityHub account route failed: ' . $exception);
        http_response_code(503);
        $message = database_configured()
            ? 'The account service could not complete that request. Review the database/mail settings and server error log.'
            : 'Accounts and administration need MySQL/MariaDB settings. Registration and password recovery also need working email delivery.';
        $content = '<section class="empty-state"><p class="eyebrow">Account services</p><h1>Not available right now</h1><p>' . h($message) . '</p><p>See the project setup guide for configuration steps.</p></section>';
        render_layout('Account service unavailable', 'Database or mail services need configuration.', $content, $path);
    }
    render_error(404, 'Page not found', 'This account page does not exist.');
}
