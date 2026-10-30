<?php
declare(strict_types=1);

/**
 * Single source of truth for every public tool.
 *
 * 'client' => true  : the tool runs entirely in the browser (public/assets/tools.js).
 * 'popular' => true : shown on the home page.
 * Add a new tool here and it automatically appears in the directory, search,
 * category filters, sitemap, related-tool lists and structured data.
 */
function tool_categories(): array
{
    return ['Business', 'Finance', 'Calculators', 'Health', 'Productivity', 'Developer', 'Design', 'Security'];
}

function tool_catalog(): array
{
    return [
        [
            'slug' => 'qr-code-generator', 'title' => 'QR Code Generator', 'category' => 'Business', 'icon' => '▦', 'popular' => true,
            'short' => 'Create a scannable QR code for a link, contact detail, message, or Wi-Fi network.',
            'keywords' => 'qr barcode wifi link url png svg',
            'howto' => ['Choose what the code should contain: a website, text, email, phone number, or Wi-Fi network.', 'Pick a colour, size, and file format (PNG or SVG).', 'Generate the code, scan it with your phone to check it, then download it.'],
            'faq' => [
                ['Is the QR code free to use commercially?', 'Yes. Codes are static, never expire, and contain your data directly, so there is no tracking link or subscription.'],
                ['Should I use PNG or SVG?', 'Use SVG for print, signs and anything that will be enlarged. Use PNG for web pages and documents.'],
            ],
            'related' => ['invoice-generator', 'base64-encoder', 'password-generator'],
        ],
        [
            'slug' => 'invoice-generator', 'title' => 'Invoice Generator', 'category' => 'Business', 'icon' => '▤', 'popular' => true,
            'short' => 'Build a polished freelance invoice with line items, discounts, tax, and print-to-PDF.',
            'keywords' => 'invoice bill freelance pdf tax discount receipt',
            'howto' => ['Enter your business and client details.', 'Add line items with a description, quantity, and unit price, then set any tax or discount.', 'Generate the invoice and use Print / Save as PDF to download it.'],
            'faq' => [
                ['Is my invoice stored?', 'No. It is processed for the request only, unless you are signed in and tick the option to save a copy.'],
                ['Which currencies are supported?', 'Ugandan shilling, US dollar, euro, British pound, and Kenyan shilling. Amounts use whole-number arithmetic to avoid rounding errors.'],
            ],
            'related' => ['income-budget-calculator', 'percentage-calculator', 'loan-calculator'],
        ],
        [
            'slug' => 'bmi-calculator', 'title' => 'BMI Calculator', 'category' => 'Health', 'icon' => '◉', 'popular' => true,
            'short' => 'Calculate an adult BMI using metric or imperial measurements, with context and limitations.',
            'keywords' => 'bmi body mass index weight height health',
            'howto' => ['Choose metric (cm, kg) or imperial (ft/in, lb).', 'Enter your height and weight.', 'Read your BMI and the standard adult range it falls into.'],
            'faq' => [
                ['Is BMI accurate for everyone?', 'BMI is only a screening measure. It does not account for muscle mass, age, or body composition, and it is not a diagnosis.'],
                ['Can I use this for children?', 'No. This calculator uses adult ranges. Children’s BMI is interpreted against growth charts by a health professional.'],
            ],
            'related' => ['unit-converter', 'age-calculator', 'percentage-calculator'],
        ],
        [
            'slug' => 'income-budget-calculator', 'title' => 'Income & Budget Calculator', 'category' => 'Finance', 'icon' => '↗', 'popular' => false,
            'short' => 'Compare monthly income, expenses, manual deductions, and estimated savings.',
            'keywords' => 'budget income expenses savings salary money',
            'howto' => ['Enter your income and whether it is monthly or annual.', 'List your recurring expenses and any deductions.', 'Review your estimated savings, savings rate, and expense breakdown, then print or download a CSV.'],
            'faq' => [
                ['Does it calculate tax?', 'No. Deductions are entered manually and are not based on official tax rules.'],
            ],
            'related' => ['loan-calculator', 'compound-interest-calculator', 'percentage-calculator'],
        ],
        [
            'slug' => 'percentage-calculator', 'title' => 'Percentage Calculator', 'category' => 'Calculators', 'icon' => '%', 'popular' => true,
            'short' => 'Work out percentages, changes, differences, reverse percentages, and discounts.',
            'keywords' => 'percent percentage increase decrease discount change difference',
            'howto' => ['Choose the type of percentage calculation.', 'Enter the two values the labels ask for.', 'Calculate, read the worked equation, and copy the result.'],
            'faq' => [
                ['How is percentage change calculated?', 'Subtract the original value from the new value, divide by the original, and multiply by 100.'],
                ['What is the difference between percentage change and percentage difference?', 'Change compares a new value to an original. Difference compares two values symmetrically, with neither treated as the starting point.'],
            ],
            'related' => ['loan-calculator', 'unit-converter', 'income-budget-calculator'],
        ],
        [
            'slug' => 'word-counter', 'title' => 'Word & Character Counter', 'category' => 'Productivity', 'icon' => 'Aa', 'popular' => true,
            'short' => 'Count words, characters, sentences, paragraphs, and approximate reading time locally.',
            'keywords' => 'word count characters sentences paragraphs reading time text',
            'howto' => ['Type or paste your text.', 'Watch the counts update live.', 'Use the reading-time estimate to size articles, speeches, and posts.'],
            'faq' => [
                ['Is my text uploaded?', 'No. Counting happens entirely in your browser.'],
            ],
            'related' => ['text-case-converter', 'json-formatter', 'base64-encoder'],
        ],

        // ---- Browser-only tools -------------------------------------------------
        [
            'slug' => 'password-generator', 'title' => 'Password Generator', 'category' => 'Security', 'icon' => '⚿', 'popular' => true, 'client' => true,
            'short' => 'Generate strong random passwords with your browser’s secure random number generator.',
            'keywords' => 'password random secure strong generator passphrase',
            'howto' => ['Set the length and choose the character types.', 'Optionally avoid look-alike characters such as 0 and O.', 'Copy the password straight into your password manager.'],
            'faq' => [
                ['Is the password sent anywhere?', 'No. It is generated in your browser with the Web Crypto API and is never uploaded or stored.'],
                ['How long should a password be?', 'At least 14 characters for ordinary accounts, and 20 or more for important ones. Use a password manager so you do not have to remember them.'],
            ],
            'related' => ['base64-encoder', 'qr-code-generator', 'text-case-converter'],
        ],
        [
            'slug' => 'age-calculator', 'title' => 'Age Calculator', 'category' => 'Calculators', 'icon' => '☺', 'popular' => false, 'client' => true,
            'short' => 'Find your exact age in years, months and days, plus the days until your next birthday.',
            'keywords' => 'age birthday date of birth days old',
            'howto' => ['Enter your date of birth.', 'Leave “Age at date” as today or pick another date.', 'Read your age, total days lived, and next birthday.'],
            'faq' => [
                ['How are months and days counted?', 'Whole calendar months are counted first; the remaining days are measured from the last month anniversary.'],
            ],
            'related' => ['date-calculator', 'bmi-calculator', 'unit-converter'],
        ],
        [
            'slug' => 'date-calculator', 'title' => 'Date Difference Calculator', 'category' => 'Calculators', 'icon' => '▣', 'popular' => false, 'client' => true,
            'short' => 'Count the days, weeks, months and years between two dates.',
            'keywords' => 'date difference days between weeks months duration',
            'howto' => ['Pick a start date and an end date.', 'Read the total days and the years, months and days breakdown.'],
            'faq' => [
                ['Is the end date included?', 'No. The result is the number of days from the start date up to, but not including, the end date.'],
            ],
            'related' => ['age-calculator', 'loan-calculator', 'percentage-calculator'],
        ],
        [
            'slug' => 'loan-calculator', 'title' => 'Loan & EMI Calculator', 'category' => 'Finance', 'icon' => '⌂', 'popular' => true, 'client' => true,
            'short' => 'Estimate monthly loan payments, total interest and see a full repayment schedule.',
            'keywords' => 'loan emi mortgage repayment interest amortization schedule payment',
            'howto' => ['Enter the loan amount, yearly interest rate and the term.', 'Read the monthly payment, total interest and total repaid.', 'Scroll the schedule or download it as a CSV.'],
            'faq' => [
                ['What kind of loan does this model?', 'A fixed-rate loan repaid in equal monthly instalments. Variable rates, fees, and insurance are not included.'],
                ['Why might my lender’s figure differ?', 'Lenders may round differently, charge fees, or compute interest daily. Treat this as an estimate.'],
            ],
            'related' => ['compound-interest-calculator', 'income-budget-calculator', 'percentage-calculator'],
        ],
        [
            'slug' => 'compound-interest-calculator', 'title' => 'Compound Interest Calculator', 'category' => 'Finance', 'icon' => '↑', 'popular' => false, 'client' => true,
            'short' => 'Project how savings or investments grow with regular contributions and compounding.',
            'keywords' => 'compound interest investment savings growth future value returns',
            'howto' => ['Enter a starting amount, monthly contribution, yearly return and number of years.', 'Choose how often interest compounds.', 'Review the future value and year-by-year growth.'],
            'faq' => [
                ['Are returns guaranteed?', 'No. This is a projection using a constant rate. Real returns vary, and inflation, fees, and tax are not included.'],
            ],
            'related' => ['loan-calculator', 'income-budget-calculator', 'percentage-calculator'],
        ],
        [
            'slug' => 'unit-converter', 'title' => 'Unit Converter', 'category' => 'Calculators', 'icon' => '⇄', 'popular' => true, 'client' => true,
            'short' => 'Convert length, weight, volume, area, speed, temperature and data sizes instantly.',
            'keywords' => 'unit converter length weight temperature volume area speed data miles km kg pounds',
            'howto' => ['Pick a category such as length or temperature.', 'Enter a value and choose the units to convert from and to.', 'Use the swap button to reverse the conversion.'],
            'faq' => [
                ['Are US and UK gallons different?', 'Yes. A US gallon is about 3.785 litres and a UK (imperial) gallon is about 4.546 litres. Both are listed separately.'],
            ],
            'related' => ['percentage-calculator', 'bmi-calculator', 'age-calculator'],
        ],
        [
            'slug' => 'json-formatter', 'title' => 'JSON Formatter & Validator', 'category' => 'Developer', 'icon' => '{}', 'popular' => true, 'client' => true,
            'short' => 'Format, validate and minify JSON in your browser with line and column error hints.',
            'keywords' => 'json format validate minify pretty print beautify parser',
            'howto' => ['Paste your JSON.', 'Choose an indent size, or minify.', 'Format it. Invalid JSON shows where the problem is.'],
            'faq' => [
                ['Is my JSON uploaded?', 'No. Parsing happens in your browser, so it is safe for private data.'],
            ],
            'related' => ['base64-encoder', 'text-case-converter', 'word-counter'],
        ],
        [
            'slug' => 'base64-encoder', 'title' => 'Base64 Encoder & Decoder', 'category' => 'Developer', 'icon' => '64', 'popular' => false, 'client' => true,
            'short' => 'Encode text to Base64 or decode it back, with full Unicode and URL-safe input support.',
            'keywords' => 'base64 encode decode string utf-8 text',
            'howto' => ['Paste text or a Base64 string.', 'Choose Encode or Decode.', 'Copy the output.'],
            'faq' => [
                ['Is Base64 encryption?', 'No. It is only an encoding and offers no security. Anyone can decode it.'],
            ],
            'related' => ['json-formatter', 'password-generator', 'text-case-converter'],
        ],
        [
            'slug' => 'color-converter', 'title' => 'Colour Converter', 'category' => 'Design', 'icon' => '◐', 'popular' => false, 'client' => true,
            'short' => 'Convert between HEX, RGB and HSL and check WCAG contrast for text on that colour.',
            'keywords' => 'color colour hex rgb hsl converter contrast accessibility wcag',
            'howto' => ['Enter a HEX, RGB or HSL value, or use the picker.', 'Copy the format you need.', 'Check the contrast ratios to see whether white or black text is readable.'],
            'faq' => [
                ['What contrast ratio is required?', 'WCAG AA asks for 4.5:1 for normal text and 3:1 for large text. AAA asks for 7:1.'],
            ],
            'related' => ['text-case-converter', 'json-formatter', 'qr-code-generator'],
        ],
        [
            'slug' => 'text-case-converter', 'title' => 'Text Case Converter', 'category' => 'Productivity', 'icon' => 'aA', 'popular' => false, 'client' => true,
            'short' => 'Switch text between upper, lower, title, sentence, camel, snake and kebab case, or make a URL slug.',
            'keywords' => 'text case upper lower title sentence camel snake kebab slug reverse',
            'howto' => ['Paste your text.', 'Click the case you want.', 'Copy the converted text.'],
            'faq' => [
                ['Does it support accented letters?', 'Yes. Case changes work on Unicode letters. The URL slug option removes accents.'],
            ],
            'related' => ['word-counter', 'json-formatter', 'base64-encoder'],
        ],
    ];
}

function tool_by_slug(string $slug): ?array
{
    foreach (tool_catalog() as $tool) {
        if ($tool['slug'] === $slug) {
            return $tool;
        }
    }
    return null;
}

/** Structured data: WebApplication plus FAQPage when the tool has FAQs. */
function tool_jsonld(array $tool): string
{
    $app = [
        '@type' => 'WebApplication',
        'name' => $tool['title'],
        'description' => $tool['short'],
        'applicationCategory' => 'UtilitiesApplication',
        'operatingSystem' => 'Any',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    ];
    $url = public_url('/tools/' . $tool['slug']);
    if ($url !== '') {
        $app['url'] = $url;
    }
    $graph = [$app];
    if (!empty($tool['faq'])) {
        $entities = [];
        foreach ($tool['faq'] as $item) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $item[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]],
            ];
        }
        $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $entities];
    }
    $json = json_encode(
        ['@context' => 'https://schema.org', '@graph' => $graph],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
}

/** How-to steps, FAQ and related tools shown beneath every tool. */
function tool_extras(array $tool): string
{
    $html = '';
    if (!empty($tool['howto'])) {
        $html .= '<section class="tool-info"><h2>How to use the ' . h($tool['title']) . '</h2><ol>';
        foreach ($tool['howto'] as $step) {
            $html .= '<li>' . h($step) . '</li>';
        }
        $html .= '</ol></section>';
    }
    if (!empty($tool['faq'])) {
        $html .= '<section class="tool-info"><h2>Frequently asked questions</h2>';
        foreach ($tool['faq'] as $item) {
            $html .= '<details class="tool-faq"><summary>' . h($item[0]) . '</summary><p>' . h($item[1]) . '</p></details>';
        }
        $html .= '</section>';
    }
    $related = '';
    foreach ($tool['related'] ?? [] as $slug) {
        $other = tool_by_slug((string) $slug);
        if ($other !== null) {
            $related .= '<div class="col-md-4"><a class="related-card" href="/tools/' . h($other['slug']) . '"><span class="tool-icon" aria-hidden="true">' . h($other['icon']) . '</span><strong>' . h($other['title']) . '</strong><small>' . h($other['category']) . '</small></a></div>';
        }
    }
    if ($related !== '') {
        $html .= '<section class="tool-info"><h2>Related tools</h2><div class="row g-3">' . $related . '</div></section>';
    }
    return $html . tool_jsonld($tool);
}

/** Page shell for tools that run entirely in the browser. */
function client_tool_page(array $tool, string $path): never
{
    $content = render_tool_header($tool['title'], $tool['short'], $tool['category'], $path)
        . '<div class="row g-4 align-items-start"><div class="col-lg-7"><section class="tool-panel">'
        . '<div data-client-tool="' . h($tool['slug']) . '"><p class="form-hint mb-0">Loading tool…</p></div>'
        . '<noscript><div class="alert alert-warning mb-0">This tool runs in your browser and needs JavaScript enabled.</div></noscript>'
        . '</section></div><aside class="col-lg-5"><!-- ADSENSE SIDEBAR --><div class="tool-aside" id="client-tool-result" aria-live="polite"></div></aside></div>'
        . tool_extras($tool)
        . '<script src="' . h(asset_url('assets/tools.js')) . '" defer></script>';
    render_layout($tool['title'], $tool['short'], $content, $path, [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'All tools', 'url' => '/tools'],
        ['label' => $tool['title'], 'url' => $path],
    ]);
}
