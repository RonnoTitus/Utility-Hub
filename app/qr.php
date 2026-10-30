<?php
declare(strict_types=1);

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

function qr_escape_wifi(string $value): string
{
    return str_replace(['\\', ';', ',', ':', '"'], ['\\\\', '\;', '\,', '\:', '\"'], $value);
}

function qr_contrast_ratio(string $foreground, string $background): float
{
    $luminance = static function (string $color): float {
        $channels = [];
        for ($offset = 1; $offset <= 5; $offset += 2) {
            $channel = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };
    $values = [$luminance($foreground), $luminance($background)];
    return (max($values) + 0.05) / (min($values) + 0.05);
}

function qr_payload(array $input): array
{
    $errors = [];
    $type = safe_text($input['qr_type'] ?? 'text');
    $allowedTypes = ['text', 'url', 'email', 'phone', 'wifi'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'text';
    }
    $content = safe_text($input['content'] ?? '');

    if ($type === 'wifi') {
        $ssid = safe_text($input['wifi_ssid'] ?? '');
        $password = safe_text($input['wifi_password'] ?? '');
        $encryption = safe_text($input['wifi_encryption'] ?? 'WPA');
        if ($ssid === '' || mb_strlen($ssid) > 32) {
            $errors[] = 'Enter a Wi-Fi network name (SSID) up to 32 characters.';
        }
        if (!in_array($encryption, ['WPA', 'WEP', 'nopass'], true)) {
            $encryption = 'WPA';
        }
        if ($encryption !== 'nopass' && $password === '') {
            $errors[] = 'Enter the Wi-Fi password, or select an open network.';
        }
        if (mb_strlen($password) > 64 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            $errors[] = 'Wi-Fi passwords must be no more than 64 characters and contain no control characters.';
        }
        if ($encryption === 'WPA' && $password !== '' && mb_strlen($password) < 8 && !preg_match('/^[A-Fa-f0-9]{64}$/', $password)) {
            $errors[] = 'A WPA password must contain at least 8 characters.';
        }
        if ($encryption === 'WEP' && $password !== '' && !in_array(mb_strlen($password), [5, 13], true) && !preg_match('/^(?:[A-Fa-f0-9]{10}|[A-Fa-f0-9]{26})$/', $password)) {
            $errors[] = 'A WEP password must be 5 or 13 characters, or a 10- or 26-digit hexadecimal key.';
        }
        $payload = 'WIFI:T:' . $encryption . ';S:' . qr_escape_wifi($ssid) . ';';
        if ($encryption !== 'nopass') {
            $payload .= 'P:' . qr_escape_wifi($password) . ';';
        }
        $payload .= ';';
    } else {
        if ($content === '' || mb_strlen($content) > 2500) {
            $errors[] = 'Enter content between 1 and 2,500 characters.';
        }
        $payload = $content;
        if ($type === 'url' && $content !== '') {
            if (!filter_var($content, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $content)) {
                $errors[] = 'Enter a complete website address starting with https:// or http://.';
            }
        } elseif ($type === 'email' && $content !== '') {
            if (!filter_var($content, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address.';
            }
            $subject = rawurlencode(safe_text($input['email_subject'] ?? ''));
            $payload = 'mailto:' . $content . ($subject !== '' ? '?subject=' . $subject : '');
        } elseif ($type === 'phone' && $content !== '') {
            if (!preg_match('/^\+?[0-9().\-\s]{5,30}$/', $content)) {
                $errors[] = 'Enter a valid phone number.';
            }
            $payload = 'tel:' . preg_replace('/[\s().-]/', '', $content);
        }
    }

    $foreground = strtoupper(safe_text($input['foreground'] ?? '#145B4A'));
    $background = strtoupper(safe_text($input['background'] ?? '#FFFFFF'));
    if (!preg_match('/^#[0-9A-F]{6}$/', $foreground)) {
        $errors[] = 'Choose a valid foreground color.';
        $foreground = '#145B4A';
    }
    if (!preg_match('/^#[0-9A-F]{6}$/', $background)) {
        $errors[] = 'Choose a valid background color.';
        $background = '#FFFFFF';
    }
    if ($foreground === $background) {
        $errors[] = 'Choose different foreground and background colors so the code remains scannable.';
    } elseif (preg_match('/^#[0-9A-F]{6}$/', $foreground) && preg_match('/^#[0-9A-F]{6}$/', $background) && qr_contrast_ratio($foreground, $background) < 3.0) {
        $errors[] = 'Choose colors with stronger contrast so phones can scan the QR code.';
    }

    $size = filter_var(safe_text($input['size'] ?? '384'), FILTER_VALIDATE_INT);
    if (!in_array($size, [256, 384, 512], true)) {
        $size = 384;
    }
    $format = safe_text($input['format'] ?? 'png');
    if (!in_array($format, ['png', 'svg'], true)) {
        $format = 'png';
    }

    if ($errors !== []) {
        return ['errors' => array_values(array_unique($errors)), 'result' => null];
    }

    return [
        'errors' => [],
        'result' => [
            'payload' => $payload,
            'foreground' => $foreground,
            'background' => $background,
            'size' => $size,
            'format' => $format,
            'type' => $type,
            'source' => $payload,
        ],
    ];
}

/** Generate the image locally with the maintained Endroid QR Code library. */
function create_qr_result(array $options): \Endroid\QrCode\Writer\Result\ResultInterface
{
    $toColor = static function (string $hex): Color {
        return new Color(
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2))
        );
    };
    $qr = new QrCode(
        data: $options['payload'],
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::Medium,
        size: (int) $options['size'],
        margin: 12,
        roundBlockSizeMode: RoundBlockSizeMode::Margin,
        foregroundColor: $toColor($options['foreground']),
        backgroundColor: $toColor($options['background'])
    );
    $writer = $options['format'] === 'svg' ? new SvgWriter() : new PngWriter();
    // Endroid 5.1 is compatible with PHP 8.1+, but PHP 8.4 reports deprecations
    // for its nullable writer parameters. Keep those vendor notices out of PNG
    // responses while leaving warnings, errors, and application diagnostics on.
    $previousErrorReporting = error_reporting();
    error_reporting($previousErrorReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    try {
        return $writer->write($qr);
    } finally {
        error_reporting($previousErrorReporting);
    }
}
