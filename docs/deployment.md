# Deployment checklist

## Before serving traffic

- [ ] Set the web root to `public/`.
- [ ] Install production Composer dependencies.
- [ ] Provide `APP_URL`, `DB_DSN`, `DB_USER`, `DB_PASSWORD`, and `MAIL_FROM` as protected host variables.
- [ ] Import `database/schema.sql` into an empty database.
- [ ] Run `php scripts/create-admin.php ...` from a private CLI session.
- [ ] Test registration, verification, sign-in, password reset, account invoice save/delete, favorites, admin inbox, and maintenance mode using test accounts.
- [ ] Confirm PHP mail delivery, TLS/HTTPS, backup policy, and database access restrictions.
- [ ] Replace draft legal text and configure privacy/consent before adding analytics or advertising scripts.
- [ ] Configure `APP_URL` to the actual canonical domain so sitemap and canonical links are correct.

## Web server

Apache can use the included `public/.htaccess` with `mod_rewrite`. On Nginx, use `public/` as `root`, route non-file requests to `/index.php`, and deny access to dotfiles. Do not expose `app/`, `database/`, `docs/`, `scripts/`, `tests/`, or `vendor/`.

For Nginx, the relevant routing pattern is:

```nginx
root /path/to/utilityhub/public;
index index.php;
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
location ~ /\.(?!well-known) { deny all; }
```

Replace the PHP-FPM socket path with the one provided by the host. Apply the provider's current PHP-FPM and TLS recommendations.

## Environment template

See `.env.example` for variable names only. Copy values into the host's protected settings; do not upload that example with real values or create a committed production `.env`.

## After deployment

- Open `/`, all six `/tools/...` routes, `/robots.txt`, and `/sitemap.xml`.
- Check `/admin` is inaccessible to ordinary users.
- Confirm account messages, contact submissions, and admin actions appear in server logs/inbox as expected.
- Submit a QR, invoice, and BMI calculation and inspect the generated output.
- If the site is down because maintenance mode was enabled, sign in as the administrator and turn it off at `/admin`.
