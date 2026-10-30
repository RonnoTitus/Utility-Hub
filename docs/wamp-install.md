# Install UtilityHub with WampServer

This package is intended for local testing on Windows. The public tools work without a database or outgoing email. Registration, saved invoices, favourites, and administration need extra setup.

## 1. Check WampServer's PHP version and extensions

Use PHP 8.2 or newer. From the WampServer tray menu, enable these PHP extensions and restart the services:

- `gd`
- `mbstring`
- `pdo_mysql`

The ZIP includes the Composer runtime libraries, so Composer is not required just to open the public tools. Use Composer if you later change dependencies.

## 2. Put the project in WampServer's web directory

Extract the `UtilityHub` folder to a path such as:

```text
C:\wamp64\www\UtilityHub
```

The recommended setup is a local Apache virtual host whose document root is the project's `public` directory:

```text
C:\wamp64\www\UtilityHub\public
```

In WampServer, add a virtual host named `utilityhub.local`, set the document root to that `public` directory, and allow `.htaccess` overrides (`AllowOverride All`). Add this line to the Windows hosts file, editing it as Administrator:

```text
127.0.0.1 utilityhub.local
```

Restart all WampServer services and open `http://utilityhub.local`. Keeping `public` as the document root prevents visitors from reaching the PHP source, database schema, and Composer files.

If you only need a quick PHP development-server preview, open a terminal in the extracted project folder and run:

```bat
php -S 127.0.0.1:8000 -t public public\index.php
```

Then open `http://127.0.0.1:8000`.

## 3. Optional: enable accounts and administration

Create a database named `utilityhub` in phpMyAdmin and import `database\schema.sql`. Set these environment variables for Apache (for example, with `SetEnv` lines in the virtual-host configuration):

```apache
SetEnv APP_ENV "local"
SetEnv APP_URL "http://utilityhub.local"
SetEnv DB_DSN "mysql:host=127.0.0.1;dbname=utilityhub;charset=utf8mb4"
SetEnv DB_USER "root"
SetEnv DB_PASSWORD ""
SetEnv MAIL_FROM "no-reply@utilityhub.local"
```

Use a dedicated database user with a password if other people can access this machine; do not use these local example credentials on a public server. The application reads environment variables and does not load `.env` files automatically.

To create the first administrator, open a Windows terminal in the project folder, set the database variables for that terminal, then run:

```bat
set "DB_DSN=mysql:host=127.0.0.1;dbname=utilityhub;charset=utf8mb4"
set "DB_USER=root"
set "DB_PASSWORD="
php scripts\create-admin.php admin@utilityhub.local "Local Admin"
```

Enter the administrator password when prompted. Account registration and password reset also require working PHP `mail()` delivery; a typical local WampServer installation is not configured to send email. The public tools remain available even when email is not set up.

## Notes

- Keep the project at the virtual host root; the site's links use root-relative URLs.
- Do not put real passwords in the ZIP, `.env.example`, source files, or public web directory.
- Privacy, terms, cookie, and disclaimer pages are drafts. Review them before making the site public.
