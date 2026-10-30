<?php
declare(strict_types=1);

/**
 * Strictly parse a non-negative decimal into integer minor units.
 * No binary floating-point arithmetic is used for invoice money.
 */
function parse_minor(mixed $raw, string $currency): ?int
{
    $currencies = supported_currencies();
    $digits = $currencies[$currency]['digits'] ?? 2;
    $text = safe_text($raw);
    if ($text === '' || !preg_match('/^\d{1,9}(?:\.\d{1,' . max(1, $digits) . '})?$/', $text)) {
        if ($digits === 0 && preg_match('/^\d{1,9}$/', $text)) {
            return (int) $text;
        }
        return null;
    }

    if ($digits === 0) {
        return str_contains($text, '.') ? null : (int) $text;
    }

    [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
    $fraction = str_pad($fraction, $digits, '0');
    return ((int) $whole * 100) + (int) substr($fraction, 0, 2);
}

/** Parse a decimal percentage into basis points (100% = 10,000). */
function parse_basis_points(mixed $raw, float $maximum = 100.0): ?int
{
    $text = safe_text($raw);
    if ($text === '' || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $text)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
    $basisPoints = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    return $basisPoints <= (int) round($maximum * 100) ? $basisPoints : null;
}

/** Parse quantity into hundredths; quantity precision is limited to 2 places. */
function parse_quantity(mixed $raw): ?int
{
    $text = safe_text($raw);
    if ($text === '' || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $text)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
    $value = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    return $value > 0 && $value <= 100000000 ? $value : null;
}

/** Round amount × basis points without overflowing on normal invoice totals. */
function apply_basis_points(int $amount, int $basisPoints): int
{
    $whole = intdiv($amount, 10000) * $basisPoints;
    $remainder = intdiv((($amount % 10000) * $basisPoints) + 5000, 10000);
    return $whole + $remainder;
}

function calculate_invoice(array $input): array
{
    $errors = [];
    $currencies = supported_currencies();
    $currency = strtoupper(safe_text($input['currency'] ?? 'UGX'));
    if (!isset($currencies[$currency])) {
        $currency = 'UGX';
        $errors[] = 'Select a supported currency.';
    }

    $businessName = safe_text($input['business_name'] ?? '');
    $clientName = safe_text($input['client_name'] ?? '');
    foreach ([
        'Business name' => [$businessName, 120],
        'Client name' => [$clientName, 120],
    ] as $label => [$value, $maximum]) {
        if ($value === '') {
            $errors[] = $label . ' is required.';
        } elseif (mb_strlen($value) > $maximum) {
            $errors[] = $label . ' must be ' . $maximum . ' characters or fewer.';
        }
    }

    $email = safe_text($input['business_email'] ?? '');
    $phone = safe_text($input['business_phone'] ?? '');
    $clientEmail = safe_text($input['client_email'] ?? '');
    $clientPhone = safe_text($input['client_phone'] ?? '');
    $businessAddress = safe_text($input['business_address'] ?? '');
    $clientAddress = safe_text($input['client_address'] ?? '');
    $invoiceNumber = safe_text($input['invoice_number'] ?? '');
    if ($invoiceNumber === '') {
        $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    }
    if (mb_strlen($invoiceNumber) > 50) {
        $errors[] = 'Invoice number must be 50 characters or fewer.';
    }
    if ($email !== '' && (mb_strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $errors[] = 'Enter a valid business email address.';
    }
    if ($clientEmail !== '' && (mb_strlen($clientEmail) > 254 || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL))) {
        $errors[] = 'Enter a valid client email address.';
    }

    $issueDate = safe_text($input['issue_date'] ?? date('Y-m-d'));
    $dueDate = safe_text($input['due_date'] ?? '');
    if (!valid_iso_date($issueDate)) {
        $errors[] = 'Enter a valid issue date.';
    }
    if ($dueDate !== '' && !valid_iso_date($dueDate)) {
        $errors[] = 'Enter a valid due date.';
    }
    if ($dueDate !== '' && valid_iso_date($issueDate) && valid_iso_date($dueDate) && $dueDate < $issueDate) {
        $errors[] = 'Due date cannot be earlier than issue date.';
    }
    foreach ([
        'Business address' => [$businessAddress, 300],
        'Client address' => [$clientAddress, 300],
        'Phone number' => [$phone, 40],
        'Client phone number' => [$clientPhone, 40],
        'Payment instructions' => [safe_text($input['payment_instructions'] ?? ''), 500],
        'Notes' => [safe_text($input['notes'] ?? ''), 1000],
    ] as $label => [$value, $maximum]) {
        if (mb_strlen($value) > $maximum) {
            $errors[] = $label . ' must be ' . $maximum . ' characters or fewer.';
        }
    }

    $taxBasisPoints = parse_basis_points($input['tax_rate'] ?? '0');
    $discountBasisPoints = parse_basis_points($input['discount_rate'] ?? '0');
    if ($taxBasisPoints === null) {
        $errors[] = 'Tax rate must be between 0 and 100%.';
        $taxBasisPoints = 0;
    }
    if ($discountBasisPoints === null) {
        $errors[] = 'Discount rate must be between 0 and 100%.';
        $discountBasisPoints = 0;
    }

    $discountType = ($input['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    $fixedDiscount = parse_minor($input['discount_amount'] ?? '0', $currency);
    if ($discountType === 'fixed' && $fixedDiscount === null) {
        $errors[] = 'Enter a valid fixed discount amount.';
        $fixedDiscount = 0;
    }

    $descriptions = is_array($input['description'] ?? null) ? $input['description'] : [];
    $quantities = is_array($input['quantity'] ?? null) ? $input['quantity'] : [];
    $rates = is_array($input['rate'] ?? null) ? $input['rate'] : [];
    $rows = [];
    $subtotal = 0;
    $rowCount = min(max(count($descriptions), count($quantities), count($rates)), 50);
    if (max(count($descriptions), count($quantities), count($rates)) > 50) {
        $errors[] = 'An invoice can contain no more than 50 line items.';
    }

    for ($i = 0; $i < $rowCount; $i++) {
        $description = safe_text($descriptions[$i] ?? '');
        $quantityRaw = safe_text($quantities[$i] ?? '');
        $rateRaw = safe_text($rates[$i] ?? '');
        if ($description === '' && $quantityRaw === '' && $rateRaw === '') {
            continue;
        }
        if ($description === '' || mb_strlen($description) > 240) {
            $errors[] = 'Each used invoice row needs a description of 240 characters or fewer.';
            continue;
        }
        $quantityHundredths = parse_quantity($quantityRaw);
        $rateMinor = parse_minor($rateRaw, $currency);
        if ($quantityHundredths === null) {
            $errors[] = 'Enter a quantity greater than 0 (up to two decimal places) for “' . $description . '”.';
            continue;
        }
        if ($rateMinor === null || $rateMinor > 1000000000) {
            $errors[] = 'Enter a valid unit price for “' . $description . '”.';
            continue;
        }
        // Quantity is hundredths; rounding to the nearest minor currency unit.
        $lineTotal = intdiv(($quantityHundredths * $rateMinor) + 50, 100);
        $rows[] = [
            'description' => $description,
            'quantity_hundredths' => $quantityHundredths,
            'quantity' => number_format($quantityHundredths / 100, 2, '.', ''),
            'rate_minor' => $rateMinor,
            'amount_minor' => $lineTotal,
        ];
        $subtotal += $lineTotal;
    }
    if ($rows === []) {
        $errors[] = 'Add at least one complete line item.';
    }

    $discount = $discountType === 'fixed'
        ? min((int) $fixedDiscount, $subtotal)
        : apply_basis_points($subtotal, $discountBasisPoints);
    $taxable = max(0, $subtotal - $discount);
    $tax = apply_basis_points($taxable, $taxBasisPoints);
    if ($taxable > PHP_INT_MAX - $tax) {
        $errors[] = 'The invoice total is larger than this calculator can safely process.';
        $total = 0;
    } else {
        $total = $taxable + $tax;
    }

    return [
        'errors' => array_values(array_unique($errors)),
        'invoice' => [
            'business_name' => $businessName,
            'business_email' => $email,
            'business_phone' => $phone,
            'business_address' => $businessAddress,
            'client_name' => $clientName,
            'client_email' => $clientEmail,
            'client_phone' => $clientPhone,
            'client_address' => $clientAddress,
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'currency' => $currency,
            'tax_basis_points' => $taxBasisPoints,
            'discount_type' => $discountType,
            'discount_basis_points' => $discountBasisPoints,
            'discount_minor' => $discount,
            'notes' => safe_text($input['notes'] ?? ''),
            'payment_instructions' => safe_text($input['payment_instructions'] ?? ''),
            'rows' => $rows,
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $total,
        ],
    ];
}

function valid_iso_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

function calculate_bmi(array $input): array
{
    $unit = ($input['unit'] ?? 'metric') === 'imperial' ? 'imperial' : 'metric';
    $errors = [];
    if ($unit === 'metric') {
        $height = filter_var(safe_text($input['height_cm'] ?? ''), FILTER_VALIDATE_FLOAT);
        $weight = filter_var(safe_text($input['weight_kg'] ?? ''), FILTER_VALIDATE_FLOAT);
        if ($height === false || $height < 50 || $height > 260) {
            $errors[] = 'Enter a height between 50 and 260 cm.';
        }
        if ($weight === false || $weight < 2 || $weight > 500) {
            $errors[] = 'Enter a weight between 2 and 500 kg.';
        }
        $heightMeters = $height === false ? 0 : $height / 100;
        $weightKg = $weight === false ? 0 : $weight;
    } else {
        $feet = filter_var(safe_text($input['height_ft'] ?? ''), FILTER_VALIDATE_FLOAT);
        $inches = filter_var(safe_text($input['height_in'] ?? '0'), FILTER_VALIDATE_FLOAT);
        $pounds = filter_var(safe_text($input['weight_lb'] ?? ''), FILTER_VALIDATE_FLOAT);
        if ($feet === false || $feet < 1 || $feet > 8 || $feet !== floor($feet) || $inches === false || $inches < 0 || $inches >= 12) {
            $errors[] = 'Enter a valid height in feet and inches.';
        }
        if ($pounds === false || $pounds < 5 || $pounds > 1100) {
            $errors[] = 'Enter a weight between 5 and 1,100 lb.';
        }
        $heightMeters = ($feet === false || $inches === false) ? 0 : (($feet * 12 + $inches) * 0.0254);
        $weightKg = $pounds === false ? 0 : $pounds * 0.45359237;
    }

    if ($errors !== []) {
        return ['errors' => $errors, 'result' => null];
    }
    $bmi = $weightKg / ($heightMeters * $heightMeters);
    $category = match (true) {
        $bmi < 18.5 => 'Below the standard adult range',
        $bmi < 25 => 'Within the standard adult range',
        $bmi < 30 => 'Above the standard adult range',
        default => 'Higher than the standard adult range',
    };
    return [
        'errors' => [],
        'result' => [
            'bmi' => round($bmi, 1),
            'category' => $category,
            'height_m' => round($heightMeters, 3),
            'weight_kg' => round($weightKg, 1),
        ],
    ];
}

function calculate_budget(array $input): array
{
    $errors = [];
    $currency = strtoupper(safe_text($input['currency'] ?? 'UGX'));
    if (!isset(supported_currencies()[$currency])) {
        $currency = 'UGX';
        $errors[] = 'Select a supported currency.';
    }
    $incomePeriod = safe_text($input['income_period'] ?? 'monthly') === 'annual' ? 'annual' : 'monthly';
    $incomeEntered = parse_minor($input['income'] ?? '', $currency);
    $deductions = parse_minor($input['deductions'] ?? '0', $currency);
    if ($incomeEntered === null || $incomeEntered <= 0) {
        $errors[] = 'Enter an income greater than zero.';
        $incomeEntered = 0;
    }
    $annualIncome = $incomePeriod === 'annual' ? $incomeEntered : $incomeEntered * 12;
    $income = $incomePeriod === 'annual' ? intdiv($incomeEntered + 6, 12) : $incomeEntered;
    if ($income <= 0 && $incomeEntered > 0) $errors[] = 'Annual income must be at least one month of the smallest currency unit.';
    if ($deductions === null) {
        $errors[] = 'Enter valid manually estimated deductions.';
        $deductions = 0;
    }

    $names = is_array($input['expense_name'] ?? null) ? $input['expense_name'] : [];
    $amounts = is_array($input['expense_amount'] ?? null) ? $input['expense_amount'] : [];
    $expenses = [];
    $expenseTotal = 0;
    for ($index = 0, $count = min(max(count($names), count($amounts)), 30); $index < $count; $index++) {
        $name = safe_text($names[$index] ?? '');
        $raw = safe_text($amounts[$index] ?? '');
        if ($name === '' && $raw === '') {
            continue;
        }
        $amount = parse_minor($raw, $currency);
        if ($name === '' || mb_strlen($name) > 80 || $amount === null) {
            $errors[] = 'Each expense needs a short name and a valid monthly amount.';
            continue;
        }
        $expenses[] = ['name' => $name, 'amount_minor' => $amount];
        $expenseTotal += $amount;
    }
    $savings = $income - $expenseTotal - $deductions;
    $savingsRate = $income > 0 ? ($savings / $income) * 100 : 0;
    return [
        'errors' => $errors,
        'result' => [
            'currency' => $currency,
            'monthly_income' => $income,
            'annual_income' => $annualIncome,
            'income_period' => $incomePeriod,
            'expense_total' => $expenseTotal,
            'deductions' => $deductions,
            'monthly_savings' => $savings,
            'annual_savings' => $savings * 12,
            'savings_rate' => round($savingsRate, 1),
            'expenses' => $expenses,
        ],
    ];
}

function calculate_percentage(array $input): array
{
    $type = safe_text($input['calculation'] ?? 'of');
    $a = filter_var(safe_text($input['value_a'] ?? ''), FILTER_VALIDATE_FLOAT);
    $b = filter_var(safe_text($input['value_b'] ?? ''), FILTER_VALIDATE_FLOAT);
    $errors = [];
    if ($a === false || !is_finite((float) $a) || abs((float) $a) > 1.0e12) {
        $errors[] = 'Enter a valid first value.';
        $a = 0.0;
    }
    if ($b === false || !is_finite((float) $b) || abs((float) $b) > 1.0e12) {
        $errors[] = 'Enter a valid second value.';
        $b = 0.0;
    }
    if ($errors !== []) {
        return ['errors' => $errors, 'result' => null];
    }
    $a = (float) $a;
    $b = (float) $b;
    $validTypes = ['of', 'change', 'difference', 'reverse', 'discount'];
    if (!in_array($type, $validTypes, true)) {
        return ['errors' => ['Choose a valid percentage calculation.'], 'result' => null];
    }
    if ($type === 'discount' && ($a < 0 || $b < 0 || $b > 100)) {
        return ['errors' => ['Enter a non-negative price and a discount between 0 and 100%.'], 'result' => null];
    }

    $result = match ($type) {
        'change' => $a == 0.0
            ? null
            : ['value' => $b - $a, 'label' => 'Percentage change', 'equation' => '((new value − original value) ÷ original value) × 100'],
        'difference' => ((abs($a) + abs($b)) == 0.0)
            ? null
            : ['value' => abs($a - $b) / ((abs($a) + abs($b)) / 2) * 100, 'label' => 'Percentage difference', 'equation' => '(absolute difference ÷ average absolute value) × 100'],
        'reverse' => $b == 0.0
            ? null
            : ['value' => $a / ($b / 100), 'label' => 'Original value', 'equation' => 'result ÷ (percentage ÷ 100)'],
        'discount' => ['value' => $a - ($a * $b / 100), 'label' => 'Price after discount', 'equation' => 'original price − (original price × discount rate ÷ 100)', 'saving' => $a * $b / 100],
        default => ['value' => $a * $b / 100, 'label' => 'Percentage of a number', 'equation' => 'number × percentage ÷ 100'],
    };
    if ($result === null) {
        return ['errors' => ['This calculation needs a non-zero divisor or average.'], 'result' => null];
    }
    if ($type === 'change') {
        $result['value'] = $a == 0.0 ? 0 : (($b - $a) / abs($a)) * 100;
    }
    if (!is_finite((float) $result['value']) || (isset($result['saving']) && !is_finite((float) $result['saving']))) {
        return ['errors' => ['Those values produce a number outside the supported range.'], 'result' => null];
    }
    return ['errors' => [], 'result' => $result];
}
