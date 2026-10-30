### UtilityHub

UtilityHub is a lightweight, self-hosted PHP utility platform featuring tools for QR code generation, freelance invoicing, financial forecasting, and browser-side text/data processing. 

### 🚀 Quick Start

### Requirements

* PHP 8.2+ (with pdo_mysql, mbstring, gd)
* Composer 2
* MySQL 8+ or MariaDB
* Apache (with mod_rewrite) or equivalent web server

### Local Development Setup

Clone the repository to your server environment, install dependencies, and spin up the PHP built-in server: 

sh

composer install
php -S 127.0.0.1:8000 -t public public/index.php

Use code with caution.

Open http://127.0.0.1:8000 in your browser. 

### 📦 Production Deployment

1. **Upload & Install Packages**
Execute production-optimized package resolution outside your public web root: 

sh

composer install --no-dev --classmap-authoritative

Use code with caution.
2. **Web Server Configuration**
Route your domain's document root strictly to the /public directory to secure core application logic.
3. **Set Environment Variables**
Configure your host's runtime environment with the required values mapped out in .env.example (APP_ENV, APP_URL, DB_DSN, DB_USER, DB_PASSWORD, MAIL_FROM, CONTACT_EMAIL).
4. **Database Import**
Create an empty database schema and seed it using the database file: 

text

/database/schema.sql

Use code with caution.
5. **Provision Initial Admin**
Run the terminal generation script to establish your primary administrative profile: 

sh

php scripts/create-admin.php admin@your-domain.example "Site Admin"

Use code with caution.

### 🧪 Testing and Extension

### Running Tests

Execute the comprehensive frontend JavaScript and backend PHPUnit verification configurations: 

sh

# Frontend logic regression checks
node tests/tools.test.js

# Backend structural verification suite
composer test

Use code with caution.

### Adding New Utilities

Declare all metadata metrics inside app/catalog.php. 

* **Client-Only Utilities:** Append the entry flag 'client' => true, map templates within public/assets/tools.js, and position calculations under the target logical arrays.
* **Server-Side Utilities:** Add matching execution handler blocks into the base router at public/index.php.