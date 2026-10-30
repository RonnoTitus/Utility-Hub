<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
if ($path !== '/') {
    $path = rtrim($path, '/');
}
$publicRoot = realpath(__DIR__);
$staticFile = realpath(__DIR__ . $path);
if (PHP_SAPI === 'cli-server' && $path !== '/' && $publicRoot && $staticFile
    && str_starts_with($staticFile, $publicRoot . DIRECTORY_SEPARATOR) && is_file($staticFile)) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/database.php';
require dirname(__DIR__) . '/app/calculations.php';
require dirname(__DIR__) . '/app/catalog.php';
require dirname(__DIR__) . '/app/qr.php';
require dirname(__DIR__) . '/app/auth.php';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isPost = $method === 'POST';
$postErrors = [];
$postResult = null;
$invoiceSaveStatus = null;

if (database_configured() && !in_array($path, ['/admin', '/login', '/logout', '/maintenance'], true) && site_setting('maintenance_mode') === '1') {
    http_response_code(503);
    render_layout('Maintenance', 'UtilityHub is temporarily unavailable.', '<section class="empty-state"><p class="eyebrow">Maintenance</p><h1>We’ll be back soon.</h1><p>UtilityHub is temporarily unavailable. Please try again later.</p></section>', '/maintenance');
}

$tools = tool_catalog();

$toolByPath = [];
foreach ($tools as $tool) {
    $toolByPath['/tools/' . $tool['slug']] = $tool;
}

function text_input(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? safe_text($_POST[$key]) : $default;
}

function select_option(string $selected, string $value): string
{
    return $selected === $value ? ' selected' : '';
}

function route_form_start(string $path, string $class = 'tool-form'): string
{
    return '<form method="post" action="' . h($path) . '" class="' . h($class) . '">' . csrf_field();
}

function currency_select(string $selected, string $id = 'currency'): string
{
    $html = '<label class="form-label" for="' . h($id) . '">Currency</label><select class="form-select" name="currency" id="' . h($id) . '">';
    foreach (supported_currencies() as $code => $currency) {
        $html .= '<option value="' . h($code) . '"' . select_option($selected, $code) . '>' . h($code . ' — ' . $currency['name']) . '</option>';
    }
    return $html . '</select>';
}

function tool_catalog_cards(array $tools, ?string $category = null, string $query = ''): string
{
    $query = mb_strtolower(trim($query));
    $html = '<div class="row g-3 tool-grid" id="tool-grid">';
    $shown = 0;
    foreach ($tools as $tool) {
        if ($category !== null && $category !== '' && $tool['category'] !== $category) {
            continue;
        }
        $haystack = mb_strtolower($tool['title'] . ' ' . $tool['short'] . ' ' . $tool['category'] . ' ' . ($tool['keywords'] ?? ''));
        if ($query !== '' && !str_contains($haystack, $query)) {
            continue;
        }
        $shown++;
        $href = '/tools/' . $tool['slug'];
        $html .= '<div class="col-md-6 col-xl-4 tool-card-wrap" data-tool-name="' . h($haystack) . '">';
        $html .= '<article class="tool-card h-100"><span class="tool-icon" aria-hidden="true">' . h($tool['icon']) . '</span>';
        $html .= '<p class="tool-category">' . h($tool['category']) . (!empty($tool['client']) ? ' · Runs in your browser' : '') . '</p><h2>' . h($tool['title']) . '</h2>';
        $html .= '<p class="tool-description">' . h($tool['short']) . '</p>';
        $html .= '<a class="tool-link" href="' . h($href) . '">Open tool <span aria-hidden="true">→</span></a></article></div>';
    }
    $html .= '</div>';
    if ($shown === 0) {
        $html .= '<p class="empty-search">No tools match that search. Try another word or <a href="/tools">view all tools</a>.</p>';
    }
    return $html;
}

/** Category filter chips (plain links, so they work without JavaScript). */
function category_chips(array $tools, ?string $active = null): string
{
    $html = '<nav class="category-tags mt-3" aria-label="Tool categories"><a href="/tools"' . (($active === null || $active === '') ? ' class="active" aria-current="true"' : '') . '>All <small>' . count($tools) . '</small></a>';
    foreach (tool_categories() as $category) {
        $count = count(array_filter($tools, static fn (array $t): bool => $t['category'] === $category));
        if ($count === 0) {
            continue;
        }
        $isActive = $active === $category;
        $html .= '<a href="/tools?category=' . rawurlencode($category) . '"' . ($isActive ? ' class="active" aria-current="true"' : '') . '>' . h($category) . ' <small>' . $count . '</small></a>';
    }
    return $html . '</nav>';
}

function info_page(string $title, string $lead, string $body, string $path): never
{
    $content = '<section class="content-page"><p class="eyebrow">UtilityHub</p><h1>' . h($title) . '</h1><p class="lead">' . h($lead) . '</p><div class="prose">' . $body . '</div></section>';
    render_layout($title, $lead, $content, $path, [
        ['label' => 'Home', 'url' => '/'],
        ['label' => $title, 'url' => $path],
    ]);
}

function tool_shell(string $title, string $description, string $category, string $path, string $form, string $result = ''): never
{
    $content = render_tool_header($title, $description, $category, $path)
        . (isset($_SESSION['user_id']) ? '<form method="post" action="/favorites" class="mb-3">' . csrf_field() . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="tool_slug" value="' . h(basename($path)) . '"><button class="btn btn-sm btn-outline-brand" type="submit">☆ Save to favourites</button></form>' : '')
        . '<div class="row g-4 align-items-start"><div class="col-lg-7"><section class="tool-panel">'
        . form_notice($GLOBALS['postErrors'] ?? [])
        . $form
        . '</section></div><aside class="col-lg-5"><!-- ADSENSE SIDEBAR --><div class="tool-aside">'
        . ($result !== '' ? $result : '<div class="aside-empty"><span class="aside-icon" aria-hidden="true">✦</span><h2>Your result appears here</h2><p>Enter your details and use the button to calculate. Your input is processed for this request only.</p></div>')
        . '</div></aside></div>'
        . ($GLOBALS['postResult'] === null && ($extraTool = tool_by_slug(basename($path))) !== null ? tool_extras($extraTool) : '');
    render_layout($title, $description, $content, $path, [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'All tools', 'url' => '/tools'],
        ['label' => $title, 'url' => $path],
    ]);
}

if ($isPost && !csrf_is_valid()) {
    http_response_code(419);
    $postErrors[] = 'Your form session expired. Reload the page and try again.';
}

// Count a tool open once per browser session per UTC day. Only an aggregate
// tool/date row is stored; no IP, account ID, form values, or generated output.
if (!$isPost && isset($toolByPath[$path]) && database_configured()) {
    $slug = $toolByPath[$path]['slug'];
    $today = gmdate('Y-m-d');
    if (($_SESSION['tool_usage_day'][$slug] ?? '') !== $today) {
        try {
            db()->prepare('INSERT INTO tool_usage_events (tool_slug, event_date) VALUES (:slug, :date)')
                ->execute(['slug' => $slug, 'date' => $today]);
            $_SESSION['tool_usage_day'][$slug] = $today;
        } catch (Throwable $exception) {
            error_log('Anonymous tool usage count failed: ' . $exception);
        }
    }
}

// Generate an image response on demand. Data is handled locally by PHP and is
// never sent to a QR service or written to disk.
if ($isPost && $path === '/tools/qr-code-generator' && $postErrors === []) {
    $qr = qr_payload($_POST);
    $postErrors = $qr['errors'];
    if ($postErrors === [] && is_array($qr['result'])) {
        try {
            $image = create_qr_result($qr['result']);
            $format = $qr['result']['format'];
            if (($_POST['download'] ?? '') === '1') {
                header('Content-Type: ' . $image->getMimeType());
                header('X-Content-Type-Options: nosniff');
                header('Content-Disposition: attachment; filename="utilityhub-qr.' . $format . '"');
                header('Cache-Control: no-store, private');
                echo $image->getString();
                exit;
            }
            $postResult = [
                'qr' => $qr['result'],
                'data_uri' => $image->getDataUri(),
            ];
        } catch (Throwable $exception) {
            error_log('QR generation failed: ' . $exception->getMessage());
            $postErrors[] = 'QR generation failed. Check the server’s GD extension and try again.';
        }
    }
}

if ($isPost && $path === '/tools/invoice-generator' && $postErrors === []) {
    $calculation = calculate_invoice($_POST);
    $postErrors = $calculation['errors'];
    if ($postErrors === []) {
        $postResult = $calculation['invoice'];
        if (isset($_POST['save_invoice'])) {
            if (!isset($_SESSION['user_id'])) {
                $postErrors[] = 'Sign in before saving this invoice to an account.';
            } else {
                try {
                    save_invoice_for_user($postResult);
                    $invoiceSaveStatus = 'Invoice saved to your account.';
                } catch (Throwable $exception) {
                    error_log('Saving invoice failed: ' . $exception);
                    $postErrors[] = 'The invoice was generated, but could not be saved. Check the account configuration or invoice number.';
                }
            }
        }
    }
}

if ($isPost && $path === '/tools/bmi-calculator' && $postErrors === []) {
    $calculation = calculate_bmi($_POST);
    $postErrors = $calculation['errors'];
    $postResult = $calculation['result'];
}

if ($isPost && $path === '/tools/income-budget-calculator' && $postErrors === []) {
    $calculation = calculate_budget($_POST);
    $postErrors = $calculation['errors'];
    if ($postErrors === []) {
        $postResult = $calculation['result'];
    }
}

if ($isPost && $path === '/tools/percentage-calculator' && $postErrors === []) {
    $calculation = calculate_percentage($_POST);
    $postErrors = $calculation['errors'];
    $postResult = $calculation['result'];
}

if ($path === '/') {
    $featured = '<section class="hero-section"><div class="hero-copy"><p class="eyebrow">Useful by design</p><h1>Free online tools<br><span>to get more done.</span></h1><p class="hero-lead">Practical helpers for work, study, and everyday decisions. Open a tool, get an answer, and keep moving.</p>';
    $featured .= '<form class="hero-search" action="/tools" method="get" role="search"><label class="visually-hidden" for="tool-search-home">Find a tool</label><input id="tool-search-home" name="q" type="search" placeholder="What do you need to do?" autocomplete="off"><button class="btn btn-brand" type="submit">Find a tool</button></form>';
    $featured .= '<div class="hero-note"><span aria-hidden="true">✓</span> Free to use <span aria-hidden="true">·</span> No account required <span aria-hidden="true">·</span> Private by default</div></div><div class="hero-art" aria-hidden="true"><div class="hero-orbit orbit-one"></div><div class="hero-orbit orbit-two"></div><div class="hero-tile tile-main"><span class="hero-qr"></span><small>Scan. Share. Done.</small></div><div class="hero-float float-left">Invoice ready <b>✓</b></div><div class="hero-float float-right"><b>%</b> Quick math</div></div></section>';
    $featured .= '<!-- ADSENSE TOP BANNER --><section class="section-block"><div class="section-heading"><div><p class="eyebrow">Start here</p><h2>Popular tools</h2></div><a href="/tools" class="subtle-link">Browse every tool <span aria-hidden="true">→</span></a></div>';
    $popularTools = array_values(array_filter($tools, static fn (array $t): bool => !empty($t['popular'])));
    $featured .= category_chips($tools) . '<div class="mt-3"></div>' . tool_catalog_cards($popularTools) . '</section>';
    $featured .= '<section class="feature-band"><div><p class="eyebrow">A better way to work</p><h2>Fast answers. No busywork.</h2><p>Use each tool right away. Calculations happen in your browser or are processed for the request, your private inputs are not saved by default.</p></div><div class="feature-points"><div><span>01</span><strong>Open a tool</strong><small>No account wall or setup.</small></div><div><span>02</span><strong>Enter your details</strong><small>Clear fields and helpful checks.</small></div><div><span>03</span><strong>Use your result</strong><small>Copy, download, or print where supported.</small></div></div></section>';
    $featured .= '<section class="section-block"><div class="section-heading"><div><p class="eyebrow">Made for real work</p><h2>Tools that keep things simple</h2></div></div><div class="row g-3"><div class="col-md-4"><div class="benefit-card"><b>Privacy first</b><p>Text counter runs in your browser. Other tools don’t keep submitted inputs.</p></div></div><div class="col-md-4"><div class="benefit-card"><b>Easy to use</b><p>Focused forms, clear results, and print-friendly outputs without unnecessary steps.</p></div></div><div class="col-md-4"><div class="benefit-card"><b>Built for mobile</b><p>Use the same quick tools on your phone, tablet, or desktop.</p></div></div></div></section>';
    $featured .= '<section class="faq-strip"><div><p class="eyebrow">Good to know</p><h2>Questions, answered</h2></div><div><h3>Do I need an account?</h3><p>No. The public tools are available to guests.</p><h3>Are my inputs stored?</h3><p>No. The tools don’t save submitted values. Word counting happens entirely in your browser.</p><a href="/faq" class="subtle-link">Read the FAQ →</a></div></section>';
    $siteUrl = public_url('/');
    if ($siteUrl !== '') {
        $schema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'UtilityHub',
            'description' => 'Free, practical online tools for everyday work.',
            'url' => $siteUrl,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $featured .= '<script type="application/ld+json">' . $schema . '</script>';
    }
    render_layout('Free online tools to get more done', 'Free, private tools for freelancers, students, small businesses, and everyday tasks.', $featured, '/');
}

if ($path === '/tools') {
    $query = trim((string) ($_GET['q'] ?? ''));
    $query = mb_substr($query, 0, 80);
    $categoryFilter = (string) ($_GET['category'] ?? '');
    if (!in_array($categoryFilter, tool_categories(), true)) {
        $categoryFilter = '';
    }
    $content = '<section class="directory-intro"><p class="eyebrow">The toolkit</p><h1>' . ($categoryFilter !== '' ? h($categoryFilter) . ' tools' : 'All tools') . '</h1><p class="lead">Choose a tool and get straight to the useful part.</p>';
    $content .= '<form action="/tools" method="get" role="search">' . ($categoryFilter !== '' ? '<input type="hidden" name="category" value="' . h($categoryFilter) . '">' : '') . '<label class="visually-hidden" for="tool-search">Search tools</label><input class="form-control directory-search" id="tool-search" name="q" type="search" value="' . h($query) . '" placeholder="Search by tool or task" autocomplete="off"></form>';
    $content .= category_chips($tools, $categoryFilter) . '</section>';
    $content .= tool_catalog_cards($tools, $categoryFilter, $query);
    $content .= '<p id="no-tools-found" class="empty-search d-none">No tools match that search. Try another word.</p>';
    render_layout($categoryFilter !== '' ? $categoryFilter . ' tools' : 'All tools', 'Browse free tools: QR codes, invoices, loan and BMI calculators, unit and colour converters, JSON and Base64 utilities, and more.', $content, '/tools', [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'All tools', 'url' => '/tools'],
    ]);
}

if (isset($toolByPath[$path])) {
    $tool = $toolByPath[$path];
    $title = $tool['title'];
    $description = $tool['short'];

    if (!empty($tool['client'])) {
        client_tool_page($tool, $path);
    }

    if ($tool['slug'] === 'qr-code-generator') {
        $qrDefaults = array_merge([
            'qr_type' => 'url', 'content' => '', 'email_subject' => '',
            'wifi_ssid' => '', 'wifi_password' => '', 'wifi_encryption' => 'WPA',
            'foreground' => '#145B4A', 'background' => '#FFFFFF', 'size' => '384', 'format' => 'png',
        ], $_POST);
        foreach ($qrDefaults as $key => $value) {
            if (!is_scalar($value)) {
                $qrDefaults[$key] = '';
            }
        }
        $form = route_form_start($path);
        $form .= '<div class="form-section"><h2>What should the QR code contain?</h2>';
        $form .= '<label class="form-label" for="qr-type">Content type</label><select class="form-select mb-3" name="qr_type" id="qr-type">';
        foreach (['url' => 'Website URL', 'text' => 'Plain text', 'email' => 'Email address', 'phone' => 'Phone number', 'wifi' => 'Wi-Fi network'] as $value => $label) {
            $form .= '<option value="' . h($value) . '"' . select_option((string) ($qrDefaults['qr_type'] ?? ''), $value) . '>' . h($label) . '</option>';
        }
        $form .= '</select><div id="qr-general-fields"><label class="form-label" for="qr-content">Content</label><textarea class="form-control" name="content" id="qr-content" rows="4" maxlength="2500" placeholder="https://example.com">' . h($qrDefaults['content']) . '</textarea><small class="form-hint">Up to 2,500 characters. This is processed by this site’s PHP server and is not saved.</small><label class="form-label mt-3" for="email-subject">Email subject <span class="optional">(optional)</span></label><input class="form-control" type="text" name="email_subject" id="email-subject" maxlength="120" value="' . h($qrDefaults['email_subject']) . '"></div>';
        $form .= '<div id="qr-wifi-fields" class="d-none"><label class="form-label" for="wifi-ssid">Network name (SSID)</label><input class="form-control mb-3" name="wifi_ssid" id="wifi-ssid" maxlength="32" value="' . h($qrDefaults['wifi_ssid']) . '"><label class="form-label" for="wifi-password">Password</label><input class="form-control mb-3" type="password" name="wifi_password" id="wifi-password" autocomplete="new-password" value="' . h($qrDefaults['wifi_password']) . '"><label class="form-label" for="wifi-encryption">Security</label><select class="form-select" name="wifi_encryption" id="wifi-encryption"><option value="WPA"' . select_option((string) $qrDefaults['wifi_encryption'], 'WPA') . '>WPA / WPA2</option><option value="WEP"' . select_option((string) $qrDefaults['wifi_encryption'], 'WEP') . '>WEP</option><option value="nopass"' . select_option((string) $qrDefaults['wifi_encryption'], 'nopass') . '>Open network</option></select><small class="form-hint">Wi-Fi details are only used to create the code and are not stored.</small></div></div>';
        $form .= '<div class="form-section"><h2>Customize</h2><div class="row g-3"><div class="col-6"><label class="form-label" for="qr-foreground">Code color</label><input class="form-control form-control-color w-100" type="color" id="qr-foreground" name="foreground" value="' . h($qrDefaults['foreground']) . '"></div><div class="col-6"><label class="form-label" for="qr-background">Background</label><input class="form-control form-control-color w-100" type="color" id="qr-background" name="background" value="' . h($qrDefaults['background']) . '"></div><div class="col-6"><label class="form-label" for="qr-size">Image size</label><select class="form-select" name="size" id="qr-size">';
        foreach ([256, 384, 512] as $size) {
            $form .= '<option value="' . $size . '"' . select_option((string) $qrDefaults['size'], (string) $size) . '>' . $size . ' × ' . $size . ' px</option>';
        }
        $form .= '</select></div><div class="col-6"><label class="form-label" for="qr-format">File format</label><select class="form-select" name="format" id="qr-format"><option value="png"' . select_option((string) $qrDefaults['format'], 'png') . '>PNG</option><option value="svg"' . select_option((string) $qrDefaults['format'], 'svg') . '>SVG</option></select></div></div></div>';
        $form .= '<button class="btn btn-brand w-100 mt-3" type="submit">Generate QR code</button></form>';
        $result = '';
        if (is_array($postResult) && isset($postResult['data_uri'])) {
            $qr = $postResult['qr'];
            $result = '<div class="result-card text-center"><p class="eyebrow">Your QR code</p><img class="qr-preview" src="' . h($postResult['data_uri']) . '" alt="Generated QR code for the submitted content"><p class="result-description">Scan this code with a phone camera. The code is generated locally on this website.</p>';
            $result .= '<form method="post" action="' . h($path) . '" class="d-grid gap-2 mt-3">' . csrf_field();
            foreach (['qr_type', 'content', 'email_subject', 'wifi_ssid', 'wifi_password', 'wifi_encryption', 'foreground', 'background', 'size', 'format'] as $key) {
                $value = safe_text($_POST[$key] ?? '');
                $result .= '<input type="hidden" name="' . h($key) . '" value="' . h($value) . '">';
            }
            $result .= '<input type="hidden" name="download" value="1"><button class="btn btn-outline-brand" type="submit">Download ' . h(strtoupper($qr['format'])) . '</button></form>';
            $result .= '<button type="button" class="btn btn-link mt-2" data-copy-text="' . h($qr['source']) . '">Copy original content</button></div>';
        }
        tool_shell($title, $description, $tool['category'], $path, $form, $result);
    }

    if ($tool['slug'] === 'invoice-generator') {
        $invoiceInput = array_merge([
            'business_name' => '', 'business_email' => '', 'business_phone' => '',
            'business_address' => '', 'client_name' => '', 'client_email' => '',
            'client_phone' => '', 'client_address' => '',
            'invoice_number' => '', 'issue_date' => date('Y-m-d'), 'due_date' => '',
            'currency' => 'UGX', 'tax_rate' => '0', 'discount_type' => 'percent',
            'discount_rate' => '0', 'discount_amount' => '0', 'notes' => '',
            'payment_instructions' => '',
            'description' => [''], 'quantity' => ['1'], 'rate' => [''],
        ], $_POST);
        foreach ([
            'business_name', 'business_email', 'business_phone', 'business_address',
            'client_name', 'client_email', 'client_phone', 'client_address', 'invoice_number', 'issue_date',
            'due_date', 'currency', 'tax_rate', 'discount_type', 'discount_rate',
            'discount_amount', 'notes', 'payment_instructions',
        ] as $fieldName) {
            $invoiceInput[$fieldName] = safe_text($invoiceInput[$fieldName] ?? '');
        }
        $form = route_form_start($path);
        $form .= '<div class="form-section"><h2>Your business</h2><div class="row g-3">';
        $form .= '<div class="col-sm-6"><label class="form-label" for="business-name">Business name</label><input class="form-control" name="business_name" id="business-name" maxlength="120" autocomplete="organization" required value="' . h($invoiceInput['business_name']) . '"></div>';
        $form .= '<div class="col-sm-6"><label class="form-label" for="business-email">Business email</label><input class="form-control" type="email" name="business_email" id="business-email" maxlength="254" value="' . h($invoiceInput['business_email']) . '"></div>';
        $form .= '<div class="col-sm-6"><label class="form-label" for="business-phone">Phone <span class="optional">(optional)</span></label><input class="form-control" name="business_phone" id="business-phone" maxlength="40" value="' . h($invoiceInput['business_phone']) . '"></div>';
        $form .= '<div class="col-sm-6"><label class="form-label" for="invoice-number">Invoice number</label><input class="form-control" name="invoice_number" id="invoice-number" maxlength="50" placeholder="Generated if left blank" value="' . h($invoiceInput['invoice_number']) . '"></div>';
        $form .= '<div class="col-12"><label class="form-label" for="business-address">Business address <span class="optional">(optional)</span></label><textarea class="form-control" name="business_address" id="business-address" rows="2" maxlength="300">' . h($invoiceInput['business_address']) . '</textarea></div></div></div>';
        $form .= '<div class="form-section"><h2>Client & dates</h2><div class="row g-3">';
        $form .= '<div class="col-sm-6"><label class="form-label" for="client-name">Client name</label><input class="form-control" name="client_name" id="client-name" maxlength="120" required value="' . h($invoiceInput['client_name']) . '"></div>';
        $form .= '<div class="col-sm-6"><label class="form-label" for="client-email">Client email <span class="optional">(optional)</span></label><input class="form-control" type="email" name="client_email" id="client-email" maxlength="254" value="' . h($invoiceInput['client_email']) . '"></div>';
        $form .= '<div class="col-sm-6"><label class="form-label" for="client-phone">Client phone <span class="optional">(optional)</span></label><input class="form-control" name="client_phone" id="client-phone" maxlength="40" value="' . h($invoiceInput['client_phone']) . '"></div>';
        $form .= '<div class="col-sm-6"><label class="form-label" for="currency">Currency</label>' . currency_select((string) $invoiceInput['currency']) . '</div>';
        $form .= '<div class="col-12"><label class="form-label" for="client-address">Client address <span class="optional">(optional)</span></label><textarea class="form-control" name="client_address" id="client-address" rows="2" maxlength="300">' . h($invoiceInput['client_address']) . '</textarea></div>';
        $form .= '<div class="col-sm-6"><label class="form-label" for="issue-date">Issue date</label><input class="form-control" type="date" name="issue_date" id="issue-date" required value="' . h($invoiceInput['issue_date']) . '"></div>';
        $form .= '<div class="col-sm-6"><label class="form-label" for="due-date">Due date <span class="optional">(optional)</span></label><input class="form-control" type="date" name="due_date" id="due-date" value="' . h($invoiceInput['due_date']) . '"></div></div></div>';
        $form .= '<div class="form-section"><div class="d-flex align-items-center justify-content-between gap-3"><div><h2 class="mb-1">Invoice items</h2><p class="form-hint mb-0">Add a description, quantity, and unit price.</p></div><button class="btn btn-sm btn-outline-brand" type="button" id="add-invoice-row">+ Add item</button></div><div id="invoice-rows" class="invoice-rows mt-3">';
        $descriptions = is_array($invoiceInput['description']) ? $invoiceInput['description'] : [''];
        $quantities = is_array($invoiceInput['quantity']) ? $invoiceInput['quantity'] : ['1'];
        $rates = is_array($invoiceInput['rate']) ? $invoiceInput['rate'] : [''];
        $rowCount = min(max(count($descriptions), count($quantities), count($rates)), 50);
        for ($i = 0; $i < max(1, $rowCount); $i++) {
            $form .= '<div class="invoice-row"><div class="row g-2 align-items-end"><div class="col-12 col-md-5"><label class="form-label" for="desc-' . $i . '">Description</label><input class="form-control" name="description[]" id="desc-' . $i . '" maxlength="240" value="' . h($descriptions[$i] ?? '') . '"></div><div class="col-4 col-md-2"><label class="form-label" for="qty-' . $i . '">Qty</label><input class="form-control" type="number" name="quantity[]" id="qty-' . $i . '" min="0.01" max="1000000" step="0.01" value="' . h($quantities[$i] ?? '') . '"></div><div class="col-6 col-md-3"><label class="form-label" for="rate-' . $i . '">Unit price</label><input class="form-control" type="number" name="rate[]" id="rate-' . $i . '" min="0" step="0.01" value="' . h($rates[$i] ?? '') . '"></div><div class="col-2 col-md-2"><button class="btn btn-remove-row" type="button" aria-label="Remove item">×</button></div></div></div>';
        }
        $form .= '</div></div><div class="form-section"><h2>Tax & discount</h2><div class="row g-3"><div class="col-6"><label class="form-label" for="tax-rate">Tax rate (%)</label><input class="form-control" type="number" name="tax_rate" id="tax-rate" min="0" max="100" step="0.01" value="' . h($invoiceInput['tax_rate']) . '"></div><div class="col-6"><label class="form-label" for="discount-type">Discount type</label><select class="form-select" name="discount_type" id="discount-type"><option value="percent"' . select_option((string) $invoiceInput['discount_type'], 'percent') . '>Percentage</option><option value="fixed"' . select_option((string) $invoiceInput['discount_type'], 'fixed') . '>Fixed amount</option></select></div><div class="col-6" id="discount-percent-wrap"><label class="form-label" for="discount-rate">Discount (%)</label><input class="form-control" type="number" name="discount_rate" id="discount-rate" min="0" max="100" step="0.01" value="' . h($invoiceInput['discount_rate']) . '"></div><div class="col-6 d-none" id="discount-fixed-wrap"><label class="form-label" for="discount-amount">Discount amount</label><input class="form-control" type="number" name="discount_amount" id="discount-amount" min="0" step="0.01" value="' . h($invoiceInput['discount_amount']) . '"></div></div></div>';
        $form .= '<div class="form-section"><h2>Payment & notes</h2><label class="form-label" for="payment-instructions">Payment instructions <span class="optional">(optional)</span></label><textarea class="form-control mb-3" name="payment_instructions" id="payment-instructions" rows="2" maxlength="500">' . h($invoiceInput['payment_instructions']) . '</textarea><label class="form-label" for="invoice-notes">Notes <span class="optional">(optional)</span></label><textarea class="form-control" name="notes" id="invoice-notes" rows="2" maxlength="1000">' . h($invoiceInput['notes']) . '</textarea>';
        $form .= isset($_SESSION['user_id']) ? '<div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="save_invoice" id="save-invoice"><label class="form-check-label" for="save-invoice">Save a copy to my account (contains business and client details)</label></div>' : '<p class="form-hint mt-3">Want to save invoices? <a href="/register">Create an account</a>. This invoice is not stored unless you choose to save it.</p>';
        $form .= '</div>';
        $form .= '<button class="btn btn-brand w-100 mt-2" type="submit">Generate printable invoice</button><p class="form-hint text-center mt-2 mb-0">Your invoice is processed for this request. It is stored only if you are signed in and choose the save option.</p></form>';

        $result = '';
        if (is_array($postResult) && isset($postResult['rows'])) {
            $invoice = $postResult;
            $issue = valid_iso_date($invoice['issue_date']) ? date('F j, Y', strtotime($invoice['issue_date'])) : '';
            $due = $invoice['due_date'] !== '' && valid_iso_date($invoice['due_date']) ? date('F j, Y', strtotime($invoice['due_date'])) : '';
            $result = '<div class="invoice-result"><div class="invoice-actions no-print"><button type="button" class="btn btn-brand" data-print>Print / Save as PDF</button><a href="' . h($path) . '" class="btn btn-outline-brand">Edit invoice</a></div><article class="invoice-paper" id="print-invoice"><header class="invoice-top"><div><small>FROM</small><h2>' . h($invoice['business_name']) . '</h2>';
            if ($invoice['business_address'] !== '') {
                $result .= '<p>' . nl2br(h($invoice['business_address'])) . '</p>';
            }
            if ($invoice['business_email'] !== '' || $invoice['business_phone'] !== '') {
                $result .= '<p>' . h(implode(' · ', array_filter([$invoice['business_email'], $invoice['business_phone']]))) . '</p>';
            }
            $result .= '</div><div class="text-end"><p class="invoice-word">INVOICE</p><strong>' . h($invoice['invoice_number']) . '</strong><p>Issued ' . h($issue) . '</p>' . ($due !== '' ? '<p>Due ' . h($due) . '</p>' : '') . '</div></header>';
            $result .= '<div class="invoice-billto"><small>BILL TO</small><strong>' . h($invoice['client_name']) . '</strong>';
            $clientContact = implode(' · ', array_filter([$invoice['client_email'], $invoice['client_phone']]));
            if ($clientContact !== '') {
                $result .= '<p>' . h($clientContact) . '</p>';
            }
            if ($invoice['client_address'] !== '') {
                $result .= '<p>' . nl2br(h($invoice['client_address'])) . '</p>';
            }
            $result .= '</div>';
            $result .= '<div class="table-responsive"><table class="table invoice-table"><thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Amount</th></tr></thead><tbody>';
            foreach ($invoice['rows'] as $row) {
                $result .= '<tr><td>' . h($row['description']) . '</td><td class="text-end">' . h($row['quantity']) . '</td><td class="text-end">' . h(format_minor($row['rate_minor'], $invoice['currency'])) . '</td><td class="text-end">' . h(format_minor($row['amount_minor'], $invoice['currency'])) . '</td></tr>';
            }
            $result .= '</tbody></table></div><div class="invoice-totals"><div><span>Subtotal</span><strong>' . h(format_minor($invoice['subtotal_minor'], $invoice['currency'])) . '</strong></div>';
            $discountLabel = $invoice['discount_type'] === 'fixed'
                ? 'Discount'
                : 'Discount (' . number_format($invoice['discount_basis_points'] / 100, 2) . '%)';
            $result .= '<div><span>' . h($discountLabel) . '</span><strong>−' . h(format_minor($invoice['discount_minor'], $invoice['currency'])) . '</strong></div>';
            $result .= '<div><span>Tax (' . number_format($invoice['tax_basis_points'] / 100, 2) . '%)</span><strong>' . h(format_minor($invoice['tax_minor'], $invoice['currency'])) . '</strong></div><div class="invoice-total"><span>Total due</span><strong>' . h(format_minor($invoice['total_minor'], $invoice['currency'])) . '</strong></div></div>';
            if ($invoice['payment_instructions'] !== '' || $invoice['notes'] !== '') {
                $result .= '<div class="invoice-footer">';
                if ($invoice['payment_instructions'] !== '') {
                    $result .= '<div><small>PAYMENT INSTRUCTIONS</small><p>' . nl2br(h($invoice['payment_instructions'])) . '</p></div>';
                }
                if ($invoice['notes'] !== '') {
                    $result .= '<div><small>NOTES</small><p>' . nl2br(h($invoice['notes'])) . '</p></div>';
                }
                $result .= '</div>';
            }
            $result .= '<p class="invoice-thanks">Thank you for your business.</p></article></div>';
            $content = render_tool_header($title, $description, $tool['category'], $path) . form_notice($postErrors, $invoiceSaveStatus) . $result;
            render_layout($title, $description, $content, $path, [
                ['label' => 'Home', 'url' => '/'],
                ['label' => 'All tools', 'url' => '/tools'],
                ['label' => $title, 'url' => $path],
            ]);
        }
        tool_shell($title, $description, $tool['category'], $path, $form);
    }

    if ($tool['slug'] === 'bmi-calculator') {
        $unit = text_input('unit', 'metric');
        $form = route_form_start($path);
        $form .= '<div class="form-section"><h2>Your measurements</h2><label class="form-label" for="bmi-unit">Units</label><select class="form-select mb-3" name="unit" id="bmi-unit"><option value="metric"' . select_option($unit, 'metric') . '>Metric (cm, kg)</option><option value="imperial"' . select_option($unit, 'imperial') . '>Imperial (ft/in, lb)</option></select>';
        $form .= '<div id="bmi-metric" class="' . ($unit === 'imperial' ? 'd-none' : '') . '"><label class="form-label" for="height-cm">Height (cm)</label><input class="form-control mb-3" type="number" name="height_cm" id="height-cm" min="50" max="260" step="0.1" value="' . h(text_input('height_cm')) . '"><label class="form-label" for="weight-kg">Weight (kg)</label><input class="form-control" type="number" name="weight_kg" id="weight-kg" min="2" max="500" step="0.1" value="' . h(text_input('weight_kg')) . '"></div>';
        $form .= '<div id="bmi-imperial" class="' . ($unit === 'imperial' ? '' : 'd-none') . '"><div class="row g-2"><div class="col-6"><label class="form-label" for="height-ft">Height (feet)</label><input class="form-control mb-3" type="number" name="height_ft" id="height-ft" min="1" max="8" step="1" value="' . h(text_input('height_ft')) . '"></div><div class="col-6"><label class="form-label" for="height-in">Inches</label><input class="form-control mb-3" type="number" name="height_in" id="height-in" min="0" max="11.9" step="0.1" value="' . h(text_input('height_in', '0')) . '"></div></div><label class="form-label" for="weight-lb">Weight (lb)</label><input class="form-control" type="number" name="weight_lb" id="weight-lb" min="5" max="1100" step="0.1" value="' . h(text_input('weight_lb')) . '"></div></div><button class="btn btn-brand w-100" type="submit">Calculate BMI</button></form>';
        $result = '';
        if (is_array($postResult) && isset($postResult['bmi'])) {
            $result = '<div class="result-card"><p class="eyebrow">Your estimate</p><div class="result-number">' . h(number_format($postResult['bmi'], 1)) . '</div><h2>' . h($postResult['category']) . '</h2><p>Calculated as weight in kilograms divided by height in metres squared.</p><div class="result-detail"><span>Height</span><strong>' . h(number_format($postResult['height_m'], 2)) . ' m</strong></div><div class="result-detail"><span>Weight</span><strong>' . h(number_format($postResult['weight_kg'], 1)) . ' kg</strong></div><div class="bmi-ranges mt-3"><strong>Standard adult ranges</strong><span>Below 18.5 · Below range</span><span>18.5–24.9 · Standard range</span><span>25.0–29.9 · Above range</span><span>30.0 and above · Higher range</span></div><p class="notice-muted mt-3 mb-0">BMI is a screening measure, not a diagnosis. It does not account for every factor that affects health. Talk with a qualified health professional about personal concerns.</p></div>';
        }
        tool_shell($title, $description, $tool['category'], $path, $form, $result);
    }

    if ($tool['slug'] === 'income-budget-calculator') {
        $form = route_form_start($path);
        $incomePeriod = text_input('income_period', 'monthly');
        $form .= '<div class="form-section"><h2>Income</h2><div class="row g-3"><div class="col-sm-5"><label class="form-label" for="income-value">Take-home or gross income</label><input class="form-control" type="number" name="income" id="income-value" min="0.01" step="0.01" required value="' . h(text_input('income')) . '"></div><div class="col-sm-3"><label class="form-label" for="income-period">Frequency</label><select class="form-select" name="income_period" id="income-period"><option value="monthly"' . select_option($incomePeriod, 'monthly') . '>Monthly</option><option value="annual"' . select_option($incomePeriod, 'annual') . '>Annual</option></select></div><div class="col-sm-4">' . currency_select(text_input('currency', 'UGX')) . '</div><div class="col-12"><label class="form-label" for="manual-deductions">Monthly deductions <span class="optional">(optional)</span></label><input class="form-control" type="number" name="deductions" id="manual-deductions" min="0" step="0.01" value="' . h(text_input('deductions', '0')) . '"><small class="form-hint">Enter a value you already know. This is not an official tax estimate.</small></div></div></div>';
        $expenseNames = is_array($_POST['expense_name'] ?? null) ? $_POST['expense_name'] : ['Rent'];
        $expenseAmounts = is_array($_POST['expense_amount'] ?? null) ? $_POST['expense_amount'] : [''];
        $form .= '<div class="form-section"><div class="d-flex align-items-center justify-content-between"><div><h2>Monthly expenses</h2><p class="form-hint">Add the recurring expenses you want to include.</p></div><button class="btn btn-sm btn-outline-brand" type="button" id="add-expense-row">+ Add expense</button></div><div id="expense-rows">';
        for ($i = 0, $count = min(max(count($expenseNames), count($expenseAmounts)), 30); $i < max(1, $count); $i++) {
            $form .= '<div class="expense-row row g-2 align-items-end mb-2"><div class="col-7"><label class="form-label" for="expense-name-' . $i . '">Expense</label><input class="form-control" name="expense_name[]" id="expense-name-' . $i . '" maxlength="80" value="' . h($expenseNames[$i] ?? '') . '"></div><div class="col-4"><label class="form-label" for="expense-amount-' . $i . '">Amount</label><input class="form-control" name="expense_amount[]" id="expense-amount-' . $i . '" type="number" min="0" step="0.01" value="' . h($expenseAmounts[$i] ?? '') . '"></div><div class="col-1"><button type="button" class="btn btn-remove-row remove-expense-row" aria-label="Remove expense">×</button></div></div>';
        }
        $form .= '</div></div><button class="btn btn-brand w-100" type="submit">Calculate budget</button></form>';
        $result = '';
        if (is_array($postResult) && isset($postResult['monthly_income'])) {
            $budget = $postResult;
            $savingsClass = $budget['monthly_savings'] < 0 ? 'negative' : '';
            $result = '<div class="result-card"><p class="eyebrow">Monthly estimate</p><div class="budget-total ' . $savingsClass . '">' . h(format_minor($budget['monthly_savings'], $budget['currency'])) . '</div><p>Estimated amount left after expenses and your manual deductions.</p><div class="result-detail"><span>Income</span><strong>' . h(format_minor($budget['monthly_income'], $budget['currency'])) . '</strong></div><div class="result-detail"><span>Expenses</span><strong>−' . h(format_minor($budget['expense_total'], $budget['currency'])) . '</strong></div><div class="result-detail"><span>Manual deductions</span><strong>−' . h(format_minor($budget['deductions'], $budget['currency'])) . '</strong></div><div class="result-detail"><span>Savings rate</span><strong>' . h(number_format($budget['savings_rate'], 1)) . '%</strong></div><div class="result-detail"><span>Annual income</span><strong>' . h(format_minor($budget['annual_income'], $budget['currency'])) . '</strong></div><div class="result-detail"><span>Annual estimated savings</span><strong>' . h(format_minor($budget['annual_savings'], $budget['currency'])) . '</strong></div>';
            if ($budget['expenses'] !== []) {
                $result .= '<div class="budget-bars" aria-label="Monthly expense breakdown">';
                foreach ($budget['expenses'] as $expense) {
                    $width = $budget['expense_total'] > 0 ? min(100, max(0, round($expense['amount_minor'] / $budget['expense_total'] * 100))) : 0;
                    $result .= '<div class="budget-bar-row"><div><span>' . h($expense['name']) . '</span><strong>' . h(format_minor($expense['amount_minor'], $budget['currency'])) . '</strong></div><span class="budget-bar-track"><span style="width:' . (int) $width . '%"></span></span></div>';
                }
                $result .= '</div>';
            }
            $csvRows = [
                ['Metric', 'Monthly (' . $budget['currency'] . ')', 'Annual (' . $budget['currency'] . ')'],
                ['Income', format_minor($budget['monthly_income'], $budget['currency']), format_minor($budget['annual_income'], $budget['currency'])],
                ['Expenses', format_minor($budget['expense_total'], $budget['currency']), format_minor($budget['expense_total'] * 12, $budget['currency'])],
                ['Manual deductions', format_minor($budget['deductions'], $budget['currency']), format_minor($budget['deductions'] * 12, $budget['currency'])],
                ['Estimated savings', format_minor($budget['monthly_savings'], $budget['currency']), format_minor($budget['annual_savings'], $budget['currency'])],
                ['Savings rate', number_format($budget['savings_rate'], 1) . '%', ''],
            ];
            foreach ($budget['expenses'] as $expense) {
                $expenseName = preg_match('/^\s*[=+\-@]/u', $expense['name']) ? "'" . $expense['name'] : $expense['name'];
                $csvRows[] = [$expenseName, format_minor($expense['amount_minor'], $budget['currency']), format_minor($expense['amount_minor'] * 12, $budget['currency'])];
            }
            $csvStream = fopen('php://temp', 'w+');
            foreach ($csvRows as $csvRow) {
                fputcsv($csvStream, $csvRow, ',', '"', '\\');
            }
            rewind($csvStream);
            $csvContent = "\xEF\xBB\xBF" . stream_get_contents($csvStream);
            fclose($csvStream);
            $csvData = rtrim(strtr(base64_encode($csvContent), '+/', '-_'), '=');
            $result .= '<p class="notice-muted mt-3 mb-0">This is a planning estimate, not tax or financial advice. Deductions are entered manually and are not calculated from official tax rules.</p><div class="no-print mt-3 d-flex flex-wrap gap-2"><button type="button" class="btn btn-outline-brand" data-print>Print / Save summary</button><button type="button" class="btn btn-outline-brand" data-download-csv="' . h($csvData) . '" data-filename="budget-summary.csv">Download CSV</button></div></div>';
        }
        tool_shell($title, $description, $tool['category'], $path, $form, $result);
    }

    if ($tool['slug'] === 'percentage-calculator') {
        $calculationType = text_input('calculation', 'of');
        $form = route_form_start($path);
        $form .= '<div class="form-section"><h2>Choose a calculation</h2><label class="form-label" for="calculation-type">Calculation</label><select class="form-select mb-3" name="calculation" id="calculation-type">';
        foreach ([
            'of' => 'What is X% of a number?',
            'change' => 'Percentage increase or decrease',
            'difference' => 'Percentage difference',
            'reverse' => 'Find the original from a result',
            'discount' => 'Discounted price',
        ] as $value => $label) {
            $form .= '<option value="' . h($value) . '"' . select_option($calculationType, $value) . '>' . h($label) . '</option>';
        }
        $form .= '</select><div class="row g-3"><div class="col-6"><label class="form-label value-a-label" for="percent-a">Number</label><input class="form-control" type="number" step="any" name="value_a" id="percent-a" required value="' . h(text_input('value_a')) . '"></div><div class="col-6"><label class="form-label value-b-label" for="percent-b">Percentage (%)</label><input class="form-control" type="number" step="any" name="value_b" id="percent-b" required value="' . h(text_input('value_b')) . '"></div></div><button class="btn btn-brand w-100 mt-4" type="submit">Calculate</button></div></form>';
        $result = '';
        if (is_array($postResult) && isset($postResult['value'])) {
            $result = '<div class="result-card"><p class="eyebrow">' . h($postResult['label']) . '</p><div class="result-number">' . h(number_format((float) $postResult['value'], 2)) . '</div><div class="calculation-steps"><strong>How it works</strong><p>' . h($postResult['equation']) . '</p></div>';
            if (isset($postResult['saving'])) {
                $result .= '<div class="result-detail"><span>You save</span><strong>' . h(number_format((float) $postResult['saving'], 2)) . '</strong></div>';
            }
            $result .= '<div class="no-print mt-3"><button type="button" class="btn btn-outline-brand" data-copy-text="' . h(number_format((float) $postResult['value'], 2)) . '">Copy result</button></div></div>';
        }
        tool_shell($title, $description, $tool['category'], $path, $form, $result);
    }

    if ($tool['slug'] === 'word-counter') {
        $form = '<div class="form-section"><h2>Your text</h2><label class="form-label" for="counter-text">Type or paste text</label><textarea class="form-control word-input" id="counter-text" rows="12" placeholder="Start typing or paste your text here. This text stays in your browser."></textarea><div class="d-flex justify-content-between align-items-center mt-2"><small class="form-hint">Text is counted in your browser and is never sent to the server.</small><button type="button" class="btn btn-sm btn-link text-danger" id="clear-text">Clear text</button></div></div>';
        $result = '<div class="result-card"><p class="eyebrow">Live counts</p><div class="counter-grid"><div><strong id="count-words">0</strong><span>Words</span></div><div><strong id="count-chars">0</strong><span>Characters</span></div><div><strong id="count-chars-no-space">0</strong><span>Without spaces</span></div><div><strong id="count-sentences">0</strong><span>Sentences</span></div><div><strong id="count-paragraphs">0</strong><span>Paragraphs</span></div><div><strong id="count-reading">0 min</strong><span>Reading time</span></div></div><p class="notice-muted mt-3 mb-0">Reading time is estimated at 200 words per minute.</p></div>';
        tool_shell($title, $description, $tool['category'], $path, $form, $result);
    }
}

if ($path === '/about') {
    info_page('About UtilityHub', 'A small set of free tools for people who have things to get done.', '<h2>Useful tools, without the extra steps</h2><p>UtilityHub brings together practical calculators and helpers for freelancers, students, small businesses, and everyday tasks. The public tools are available without an account.</p><h2>Privacy is part of the product</h2><p>We do not save calculator submissions or invoice details by default. The word counter runs locally in your browser. Other tools process only the request needed to create the result.</p><h2>Our approach</h2><p>We aim to make each tool clear, accessible, and honest about its limits. For example, the BMI calculator is an educational screening estimate—not a diagnosis—and the budget calculator does not claim to calculate official tax.</p>', '/about');
}

if ($path === '/faq') {
    info_page('Frequently asked questions', 'Quick answers about using the tools.', '<h2>Are these tools free?</h2><p>Yes. The public tools are free to use, and no account is required.</p><h2>Do you store my information?</h2><p>Tool inputs and results are not saved by default. The word and character counter runs entirely in your browser. QR and invoice details are only processed to return the requested output. Anonymous aggregate tool-open counts may be stored for site statistics.</p><h2>How do I save an invoice as a PDF?</h2><p>Generate the invoice, then choose Print / Save as PDF and select your browser’s PDF option.</p><h2>Is the BMI calculator medical advice?</h2><p>No. BMI is a screening measure, not a diagnosis. The tool explains the standard adult ranges and limitations.</p><h2>Are income or tax estimates official?</h2><p>No. The budget tool only uses values you enter. It does not apply official tax rules or provide financial advice.</p><h2>Why can’t I sign in?</h2><p>Accounts are available once the site owner has configured a MySQL/MariaDB database and working email delivery. If the setup is incomplete, the public guest tools remain available.</p>', '/faq');
}

if ($path === '/privacy') {
    info_page('Privacy policy', 'A draft overview of how UtilityHub handles information. The site owner should review this before publication.', '<h2>Information you enter into tools</h2><p>We do not store submitted calculator values, invoice details, QR payloads, or counter text. QR and invoice requests are processed by the site’s PHP application to return a result. The word counter processes all text in your browser. If you are signed in and explicitly choose “Save a copy,” invoice details are stored in your account until you delete them. Contact messages submitted while the inbox is configured are retained until the operator removes them.</p><h2>Anonymous tool counts</h2><p>When the database is configured, the site counts a tool open at most once per browser session per UTC day. It stores only the tool name and date for aggregate statistics. It does not store the form values, account ID, IP address, or session identifier with that count.</p><h2>Technical request data</h2><p>Your web host may process standard connection data such as IP address, browser information, and request time in its server logs. Hosting providers may retain these logs according to their own policies.</p><h2>Cookies and local storage</h2><p>Public tools do not require an account. The theme preference may be saved in browser local storage. A short-lived HTTP-only session cookie supports CSRF protection and limits duplicate anonymous tool counts.</p><h2>Third-party resources</h2><p>The interface loads Bootstrap assets from jsDelivr. This request may be visible to the CDN provider. Ad scripts are not active by default.</p><h2>Contact</h2><p>Before launch, configure a real contact address and update this draft with the operator’s legal name, jurisdiction, retention periods, and applicable privacy rights.</p>', '/privacy');
}

if ($path === '/terms') {
    info_page('Terms of service', 'Draft terms for review by the site owner before publication.', '<h2>Using the tools</h2><p>You may use the public tools for lawful personal or business purposes. You are responsible for reviewing the results before relying on or sharing them.</p><h2>No professional advice</h2><p>UtilityHub provides general-purpose tools only. It does not provide medical, tax, legal, accounting, or investment advice.</p><h2>Availability and accuracy</h2><p>We work to keep the tools available and results accurate, but do not guarantee uninterrupted access or suitability for a particular purpose.</p><h2>Acceptable use</h2><p>Do not use the site to distribute unlawful content, interfere with its operation, or attempt to access systems or data without authorization.</p><h2>Before launch</h2><p>The site owner should add the operator identity, jurisdiction, dispute terms, limitation wording, and effective date before publishing these draft terms.</p>', '/terms');
}

if ($path === '/cookies') {
    info_page('Cookie policy', 'Public tools use a minimal security session and a browser-local theme preference.', '<h2>Essential session cookie</h2><p>A short-lived HTTP-only session cookie supports form CSRF protection and avoids duplicate anonymous tool-open counts in one browser session. It is configured as SameSite Lax and marked Secure when served over HTTPS.</p><h2>Theme setting</h2><p>The light or dark appearance preference is stored in browser local storage rather than a cookie.</p><h2>Advertising</h2><p>Ad scripts are not active by default. If the site owner adds third-party advertising, update this page and any required consent behavior first.</p>', '/cookies');
}

if ($path === '/disclaimer') {
    info_page('Disclaimer', 'Please review tool results before using them for decisions.', '<h2>General information</h2><p>UtilityHub tools are provided for convenience and education. Results may need independent checking and are not a substitute for professional advice.</p><h2>Health</h2><p>The BMI calculator applies standard adult BMI categories. BMI is a screening measure and does not diagnose a health condition.</p><h2>Money</h2><p>The budget calculator performs arithmetic on values you provide. It does not calculate official taxes, benefits, interest, or investment outcomes.</p><h2>Invoices and QR codes</h2><p>Review invoice details before sending. Test a generated QR code before printing or distributing it.</p>', '/disclaimer');
}

if ($path === '/articles') {
    $content = '<section class="directory-intro"><p class="eyebrow">Learn & use</p><h1>Guides</h1><p class="lead">Short, practical explainers to help you get better results from everyday tools.</p></section><div class="row g-3"><div class="col-md-6"><article class="article-card"><p class="tool-category">Business</p><h2>What to include on a freelance invoice</h2><p>A useful invoice identifies both parties, describes the work, shows quantities and rates, makes any discount and tax visible, and states payment instructions and due date.</p><a href="/articles/freelance-invoice-basics" class="tool-link">Read guide →</a></article></div><div class="col-md-6"><article class="article-card"><p class="tool-category">Calculators</p><h2>Percentage change, explained</h2><p>Percentage change compares the size of a change with the original value. Keeping the original value as the denominator makes increases and decreases comparable.</p><a href="/articles/percentage-change" class="tool-link">Read guide →</a></article></div><div class="col-md-6"><article class="article-card"><p class="tool-category">Privacy</p><h2>How to share a Wi-Fi QR code safely</h2><p>A Wi-Fi QR code contains network access details. Share it only with people you want to connect, and change the network password if a code is shared too broadly.</p><a href="/articles/wifi-qr-safety" class="tool-link">Read guide →</a></article></div></div>';
    if (database_configured()) {
        try {
            $published = db()->query("SELECT slug, title, excerpt FROM blog_posts WHERE status = 'published' AND published_at <= UTC_TIMESTAMP() ORDER BY published_at DESC")->fetchAll();
            foreach ($published as $post) {
                $content .= '<div class="article-card mt-3"><p class="tool-category">Guide</p><h2>' . h($post['title']) . '</h2><p>' . h($post['excerpt']) . '</p><a href="/articles/' . h($post['slug']) . '" class="tool-link">Read guide →</a></div>';
            }
        } catch (Throwable $exception) {
            error_log('Published guide list failed: ' . $exception);
            render_error(503, 'Guides are temporarily unavailable', 'Check the database setup and try again.');
        }
    }
    render_layout('Guides', 'Short educational guides about invoices, percentages, and QR codes.', $content, '/articles');
}

if (in_array($path, ['/articles/freelance-invoice-basics', '/articles/percentage-change', '/articles/wifi-qr-safety'], true)) {
    $article = match ($path) {
        '/articles/freelance-invoice-basics' => ['What to include on a freelance invoice', 'A clear invoice helps both sides understand the work and what is due.', '<p>Include your business name and contact details, the client’s name, a unique invoice number, issue date, and due date. Add a concise description of each service, the quantity, unit price, and line total.</p><p>Show the subtotal, any discount, tax rate and tax amount, and the final total. State how the client can pay and include a short note if needed. Review every field before sending.</p><a href="/tools/invoice-generator" class="btn btn-brand">Create an invoice</a>'],
        '/articles/percentage-change' => ['Percentage change, explained', 'Compare a change to the original value.', '<p>Subtract the original value from the new value. Divide that difference by the original value, then multiply by 100. A positive result is an increase; a negative result is a decrease.</p><p>For example, moving from 80 to 100 is a change of 20. Dividing 20 by the original 80 gives 0.25, or a 25% increase.</p><a href="/tools/percentage-calculator" class="btn btn-brand">Calculate a percentage</a>'],
        default => ['How to share a Wi-Fi QR code safely', 'A Wi-Fi QR code can make joining a network easier, but it contains access details.', '<p>Generate a Wi-Fi QR code only for a network you are authorized to share. Anyone who can scan the code may be able to connect, so do not post it publicly unless that is intended.</p><p>After creating a code, scan it yourself to confirm the correct network and security type are included. If access details are shared with the wrong audience, change the Wi-Fi password.</p><a href="/tools/qr-code-generator" class="btn btn-brand">Create a QR code</a>'],
    };
    $content = '<article class="content-page article-body"><p class="eyebrow">Guide</p><h1>' . h($article[0]) . '</h1><p class="lead">' . h($article[1]) . '</p><div class="prose">' . $article[2] . '</div></article>';
    render_layout($article[0], $article[1], $content, $path, [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'Guides', 'url' => '/articles'],
        ['label' => $article[0], 'url' => $path],
    ]);
}

if (preg_match('~^/articles/([a-z0-9-]{1,180})$~', $path, $articleMatch)) {
    if (!database_configured()) {
        render_error(404, 'Guide not found', 'That guide is not available.');
    }
    try {
        $statement = db()->prepare("SELECT title, excerpt, body_markdown, published_at FROM blog_posts WHERE slug = :slug AND status = 'published' AND published_at <= UTC_TIMESTAMP() LIMIT 1");
        $statement->execute(['slug' => $articleMatch[1]]);
        $publishedArticle = $statement->fetch();
    } catch (Throwable $exception) {
        error_log('Published guide lookup failed: ' . $exception);
        render_error(503, 'Guide unavailable', 'Check the database setup and try again.');
    }
    if (!$publishedArticle) {
        render_error(404, 'Guide not found', 'That guide is not available.');
    }
    $paragraphs = preg_split('/\R{2,}/u', trim((string) $publishedArticle['body_markdown'])) ?: [];
    $safeBody = '';
    foreach ($paragraphs as $paragraph) {
        $safeBody .= '<p>' . nl2br(h($paragraph)) . '</p>';
    }
    $content = '<article class="content-page article-body"><p class="eyebrow">Guide · ' . h(date('F j, Y', strtotime($publishedArticle['published_at']))) . '</p><h1>' . h($publishedArticle['title']) . '</h1><p class="lead">' . h($publishedArticle['excerpt']) . '</p><div class="prose">' . $safeBody . '</div></article>';
    render_layout($publishedArticle['title'], $publishedArticle['excerpt'], $content, $path);
}

if ($path === '/contact') {
    $contactEmail = trim((string) (getenv('CONTACT_EMAIL') ?: ''));
    $contactAvailable = database_configured() || ($contactEmail !== '' && mail_is_configured());
    $status = '';
    $contactErrors = $postErrors;
    if ($isPost && $path === '/contact' && $contactErrors === []) {
        $name = safe_text($_POST['name'] ?? '');
        $email = safe_text($_POST['email'] ?? '');
        $message = safe_text($_POST['message'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            $contactErrors[] = 'Enter your name (up to 120 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $contactErrors[] = 'Enter a valid email address.';
        }
        if ($message === '' || mb_strlen($message) > 5000) {
            $contactErrors[] = 'Enter a message of up to 5,000 characters.';
        }
        if ($contactErrors === [] && database_configured()) {
            try {
                $pdo = require_database();
                $pdo->prepare('INSERT INTO contact_messages (name, email, message) VALUES (:name, :email, :message)')
                    ->execute(['name' => $name, 'email' => $email, 'message' => $message]);
                $status = 'Your message has been sent to the site team.';
            } catch (Throwable $exception) {
                error_log('Contact inbox save failed: ' . $exception);
                $contactErrors[] = 'The message could not be saved. Please try again later.';
            }
        } elseif ($contactErrors === [] && $contactEmail !== '' && mail_is_configured()) {
            if (!send_site_email($contactEmail, 'UtilityHub contact message', "From: {$name}\nReply to: {$email}\n\n{$message}\n")) {
                $contactErrors[] = 'The message could not be sent. Please try again later.';
            } else {
                $status = 'Your message has been sent.';
            }
        } elseif ($contactErrors === []) {
            $contactErrors[] = 'Contact delivery needs a configured database inbox, or CONTACT_EMAIL plus working mail delivery.';
        }
    }
    $content = '<section class="content-page"><p class="eyebrow">We’re here to help</p><h1>Contact us</h1><p class="lead">Send a question or report a problem with a tool.</p><div class="row g-4"><div class="col-lg-7"><div class="tool-panel">';
    $content .= form_notice($contactErrors, $status);
    $content .= !$contactAvailable ? '<div class="alert alert-warning">Contact delivery is not configured. Set up the database inbox or configure CONTACT_EMAIL, MAIL_FROM, and host mail delivery.</div>' : '';
    $content .= route_form_start('/contact') . '<label class="form-label" for="contact-name">Name</label><input class="form-control mb-3" id="contact-name" name="name" maxlength="120" required value="' . h(text_input('name')) . '"><label class="form-label" for="contact-email">Email</label><input class="form-control mb-3" type="email" id="contact-email" name="email" maxlength="254" required value="' . h(text_input('email')) . '"><label class="form-label" for="contact-message">Message</label><textarea class="form-control mb-3" id="contact-message" name="message" rows="6" maxlength="5000" required>' . h(text_input('message')) . '</textarea><button class="btn btn-brand" type="submit"' . (!$contactAvailable ? ' disabled' : '') . '>Send message</button></form></div></div><div class="col-lg-5"><div class="article-card"><h2>Before you write</h2><p>Do not include passwords, payment details, invoice contents, or other sensitive information in a support message.</p><p>Tell us which tool you were using and what happened. This makes it easier to investigate.</p></div></div></div></section>';
    render_layout('Contact us', 'Contact UtilityHub with a question or tool issue.', $content, '/contact');
}

if (in_array($path, ['/login', '/register', '/verify-email', '/password-reset', '/dashboard', '/favorites', '/admin', '/logout'], true)
    || preg_match('~^/invoices/[0-9]+$~', $path)) {
    account_route($path, $isPost, $postErrors);
}

if ($path === '/maintenance') {
    http_response_code(503);
    $content = '<section class="empty-state"><p class="eyebrow">Maintenance</p><h1>We’ll be back soon.</h1><p>UtilityHub is temporarily unavailable. Please try again later.</p></section>';
    render_layout('Maintenance', 'UtilityHub is temporarily unavailable.', $content, $path);
}

if ($path === '/sitemap.xml') {
    $base = rtrim((string) (getenv('APP_URL') ?: ''), '/');
    header('Content-Type: application/xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $publicPaths = ['/', '/tools'];
    foreach (tool_catalog() as $siteTool) {
        $publicPaths[] = '/tools/' . $siteTool['slug'];
    }
    $publicPaths = array_merge($publicPaths, [
        '/about', '/contact', '/faq', '/privacy', '/terms', '/cookies', '/disclaimer',
        '/articles', '/articles/freelance-invoice-basics',
        '/articles/percentage-change', '/articles/wifi-qr-safety',
    ]);
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    if ($base !== '') {
        foreach ($publicPaths as $publicPath) {
            echo '<url><loc>' . h($base . $publicPath) . '</loc></url>';
        }
        if (database_configured()) {
            try {
                $posts = db()->query("SELECT slug FROM blog_posts WHERE status = 'published' AND published_at <= UTC_TIMESTAMP()")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($posts as $slug) {
                    echo '<url><loc>' . h($base . '/articles/' . rawurlencode((string) $slug)) . '</loc></url>';
                }
            } catch (Throwable $exception) {
                error_log('Sitemap guide lookup failed: ' . $exception);
            }
        }
    }
    echo '</urlset>';
    exit;
}

if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "User-agent: *\nAllow: /\nDisallow: /login\nDisallow: /register\nDisallow: /verify-email\nDisallow: /password-reset\nDisallow: /dashboard\nDisallow: /favorites\nDisallow: /admin\nDisallow: /maintenance\n";
    $siteRoot = rtrim((string) (getenv('APP_URL') ?: ''), '/');
    if ($siteRoot !== '') {
        echo "Sitemap: {$siteRoot}/sitemap.xml\n";
    }
    exit;
}

render_error(404, 'Page not found', 'The page you requested does not exist. Browse the tools directory to find what you need.');
