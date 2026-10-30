# UtilityHub

UtilityHub is a small PHP utility website designed to be easy to deploy. The public tools work without an account:

- QR codes for text, URLs, email, telephone numbers, and Wi-Fi (PNG/SVG download)
- Freelance invoices with line items, tax, discount, multiple currencies, and browser print-to-PDF
- Adult BMI estimates in metric or imperial units
- Monthly and annual income/budget estimates with expenses and manual deductions
- Percentage, change, difference, reverse percentage, and discount calculations
- Live word, character, sentence, paragraph, and reading-time counts (the text stays in the browser)
- Browser-only tools (nothing is uploaded): password generator, age calculator, date difference, loan/EMI with repayment schedule, compound interest, unit converter, JSON formatter/validator, Base64, colour converter with WCAG contrast, and text case converter

The site also includes public FAQ, about, contact, privacy, terms, cookies, disclaimer, and guide pages; responsive layout; theme toggle; SEO metadata; robots and sitemap routes; and AdSense insertion comments.

## Requirements

- PHP 8.2 or newer with PDO, PDO MySQL, mbstring, and GD
- Composer 2
- MySQL 8+ or a compatible MariaDB server for accounts, saved invoices, favorites, contact inbox, and admin tools
- Apache with `mod_rewrite`, or another web server configured to route clean URLs to `public/index.php`
- Outbound mail configured by the host for account verification and password-reset links

The public tools do not need MySQL or email. Account pages intentionally return an unavailable message until those services are configured; no fake account or mail behavior is used.

## Install locally

```sh
composer install
php -S 127.0.0.1:8000 -t public public/index.php
```

Then open `http://127.0.0.1:8000`. PHP's development server uses `public/` as the document root; do not expose the project root in production.

For Windows/WampServer, see [`docs/wamp-install.md`](docs/wamp-install.md). The downloadable Wamp package includes production Composer dependencies so it can run without installing Composer first.

## Production setup

1. Upload the project and run `composer install --no-dev --classmap-authoritative`.
2. Configure the web server document root as the project's `public/` directory. This is the recommended setup because Composer, source, schema, tests, and scripts remain outside the public web root.
3. Set protected server environment variables:
   - `APP_ENV=production`
   - `APP_URL=https://your-real-domain`
   - `DB_DSN=mysql:host=127.0.0.1;dbname=utilityhub;charset=utf8mb4`
   - `DB_USER` and `DB_PASSWORD`
   - `MAIL_FROM` to an address authorized by your mail provider
   - `CONTACT_EMAIL` for the optional mail-based contact fallback
4. Create an empty MySQL/MariaDB database and import `database/schema.sql`.
5. Verify PHP mail delivery. Account registration and password resets require working outbound email and a correct `APP_URL`.
6. Create the first admin from a private CLI/SSH session:

   ```sh
   php scripts/create-admin.php admin@your-domain.example "Site Admin"
   ```

   Enter a 12–72 character password at the prompt. The script refuses to create another admin if one already exists. Never put the password in the command or a committed file.
7. Sign in at `/login`. The admin page at `/admin` manages contact messages, publishes plain-text guides, and updates the maintenance switch and announcement.
8. Review and replace the draft legal copy with your operator name, jurisdiction, privacy practices, and local legal advice before launch.

If your host requires the project root to remain the document root, the included root `.htaccess` routes requests into `public/` and blocks direct access to app/configuration folders. Apache rewrite support is required; use `public/` as document root whenever possible.

## AdSense

Search the source for `ADSENSE TOP BANNER` and `ADSENSE SIDEBAR`. Replace those comment placeholders with the provider snippet only after the site is approved. Update the privacy/cookie disclosures and consent behavior to match the scripts you add.

## Adding a tool

Every tool is described once in `app/catalog.php` (title, category, keywords, how-to steps, FAQ, related tools). The directory, category filters, search, sitemap, related-tool links and schema.org markup are generated from it.

- **Browser-only tool:** add the catalog entry with `'client' => true`, then add a matching entry to `tools` in `public/assets/tools.js` (a `form()` template and a `mount()` function) and put the calculation in `logic` so it can be tested.
- **Server-side tool:** add the catalog entry and a handler block in `public/index.php`, like the QR and invoice tools.

## Tests

```sh
node tests/tools.test.js
```

Runs 40 checks on the browser-tool logic (password generation, dates, loan and compound-interest maths, unit/colour/Base64 conversions, case conversion). No dependencies are needed beyond Node 18+.

PHP tests:


```sh
composer test
```

The PHPUnit suite covers money parsing, invoice rounding/tax/discount calculations, BMI and budget calculations, percentage modes, input validation, and generated QR image formats.

## Operational notes

- Invoice, QR, and budget forms are processed for a single request and are not stored by default. Saving an invoice is an explicit opt-in available to signed-in users and stores business/client details in their account.
- No payment processor or paid plan is enabled.
- Do not commit `.env` files or credentials. Use the hosting provider's protected environment-variable settings.
- Set regular database backups and enforce HTTPS at the web server/proxy.
