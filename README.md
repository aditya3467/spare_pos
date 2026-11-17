# Spare — spare parts shop management

Plain PHP 8.2+, MySQL 8+ / MariaDB 10.4+, PDO MySQL and Apache. Uses the requested MVC-style project structure without Laravel. Login, three roles and owner-managed staff permissions are the current implemented milestone.

## Local setup (XAMPP)

Run commands from the project root:

1. Start Apache and MySQL in XAMPP.
2. Create an empty `spare_pos` database in phpMyAdmin using utf8mb4.
3. Copy `.env.example` to `.env` and set your MySQL credentials.
4. Run `/Applications/XAMPP/xamppfiles/bin/php database/migrations/setup.php`.
5. Run `/Applications/XAMPP/xamppfiles/bin/php database/seeders/create-user.php` in an interactive terminal for each account. Create an owner first to obtain a shop ID for staff. Password entry is hidden. There are no default accounts.
6. Open `http://localhost/spare_pos/`; the root index redirects to `public/`.

For production, configure the web server document root to this project's `public/` directory. The application also works in a subdirectory. Routes currently use `index.php?route=/login`, so URL rewriting is not required.

## Structure

- `app/Controllers/`: authentication and dashboard request handling.
- `app/Models/`: database access for users and staff listings.
- `app/Services/`: transactional, shop-scoped staff permission updates.
- `app/Middleware/`: authentication, role and CSRF enforcement.
- `app/Helpers/`: escaping, URL generation, validation and permissions.
- `app/Core/`: database connection, router, request, base controller/model and sessions.
- `app/bootstrap.php`: configuration loading and autoloading.
- `config/`: application, database and permission definitions. Secrets come from `.env`.
- `database/schema.sql`: authentication schema.
- `database/migrations/setup.php`: repeatable initial table setup; this is not yet a versioned migration runner.
- `database/seeders/create-user.php`: trusted command-line account provisioning.
- `public/index.php`: the application front controller, the only public PHP endpoint.
- `public/assets/`: CSS, JavaScript, images and icons.
- `resources/views/`: login and dashboard templates, plus directories for future screens.
- `routes/web.php`: implemented browser routes. `routes/api.php` is reserved for future APIs.
- `storage/`: logs, invoices, imports, exports and backups.
- `tests/AuthTest.php`: isolated authentication, permission and routing checks.

Folders for future modules follow the supplied tree. Product, inventory, sale, tax and offline modules are not implemented yet, so their controllers, services, tests, seed data and PWA files have not been added as empty placeholders. `config/tax.php` will be added with the tax module. The existing checkout directory remains `spare_pos` so its XAMPP URL continues to work.

## Composer

`composer.json` defines PSR-4 autoloading and PHP extension requirements. The application runs without Composer using a small fallback autoloader. When Composer is available, run `composer install` to generate its autoloader and `composer.lock`; the lockfile is not fabricated. `vendor/` is generated and ignored by Git.

## Access and security

Roles are `superadmin`, `shop_owner` and `staff`. Owners manage permissions only for staff in their own shop. Staff begin without permissions; updates take effect on the next request. Superadmin has configured platform privileges. Future modules must enforce `can()` server-side and scope shop data in their queries.

Includes password hashing, prepared SQL, session rotation, CSRF protection, POST-only logout, active-account checks, 30-minute idle expiry and database-backed throttling (five attempts per email/IP or IP per rolling 15 minutes). Owners' permission updates lock the target staff row and run in a transaction. No public registration or password reset is exposed.

Use HTTPS and a restricted MySQL user in production. Ensure Apache permits the supplied `.htaccess` restrictions. Private directories and dotfiles must not be served; use `public/` as the production document root. Authenticated responses use `no-store`; offline authentication and transaction syncing remain future work.

## Validation

Run `/Applications/XAMPP/xamppfiles/bin/php tests/AuthTest.php` (requires PDO SQLite for its isolated in-memory database). This verifies routing, idle expiry, disabled users, permission grants/revocation and input validation. It does not exercise MySQL-specific login throttling or row locking; verify end-to-end sign-in and owner permission updates after starting MySQL and provisioning accounts.

## Configured local installation

The local `spare_pos` database and authentication tables have been created. `.env` uses a dedicated database account scoped to this database. Three local application accounts exist: `admin@spare.test` (superadmin), `owner@spare.test` (shop owner), and `staff@spare.test` (staff). Their generated passwords are stored in the Git-ignored, HTTP-protected `storage/local-accounts.json`; do not publish that file. Staff currently have no granted permissions.

Live Apache/MySQL validation passed 103 HTTP checks, including repeated successful logins, all roles, CSRF rejection, logout, session rotation, permission grants/revocation, cross-shop isolation, failed-login throttling and private-file access restrictions. Temporary test data was removed afterward.

To rerun the login regression check against this local installation, run `python3 tests/LoginHttpTest.py` with Apache and MySQL running. It reads the local account file and performs real logins/logouts. Set `SPARE_TEST_URL` if the public front-controller URL differs. The broader one-time validation also exercised temporary MySQL fixtures and is separate from this reusable login check.
