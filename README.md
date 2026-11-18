# Spare — spare parts shop management

Plain PHP 8.2+, MySQL 8+ / MariaDB 10.4+, PDO MySQL, mbstring and Apache. Uses the requested MVC-style project structure without Laravel. Implemented: login, three roles, owner-managed staff permissions, two-level category management, and the inventory database foundation.

## Local setup (XAMPP)

Run commands from the project root:

1. Start Apache and MySQL in XAMPP.
2. Create an empty `spare_pos` database in phpMyAdmin using utf8mb4.
3. Copy `.env.example` to `.env` and set your MySQL credentials.
4. Run `/Applications/XAMPP/xamppfiles/bin/php database/migrations/setup.php`.
5. Run `/Applications/XAMPP/xamppfiles/bin/php database/seeders/create-user.php` in an interactive terminal for each account. Create an owner first to obtain a shop ID for staff. Password entry is hidden. There are no default accounts.
6. Run `/Applications/XAMPP/xamppfiles/bin/php database/migrations/inventory.php` to install inventory tables, ledger guards and the catalogue for existing shops. The migration account needs `TRIGGER` in addition to its schema privileges.
7. Open `http://localhost/spare_pos/`; the root index redirects to `public/`.

For production, configure the web server document root to this project's `public/` directory. The application also works in a subdirectory. Routes currently use `index.php?route=/login`, so URL rewriting is not required.

## Structure

- `app/Controllers/`: authentication, dashboard, category and staff request handling.
- `app/Models/`: database access for shops, users and staff listings.
- `app/Services/`: transactional category/permission operations and permission-aware dashboard queries.
- `app/Middleware/`: authentication, role and CSRF enforcement.
- `app/Helpers/`: escaping, URL generation, validation and permissions.
- `app/Core/`: database connection, router, request, base controller/model and sessions.
- `app/bootstrap.php`: configuration loading and autoloading.
- `config/`: application, database and permission definitions. Secrets come from `.env`.
- `database/schema.sql`: authentication and inventory foundation schema.
- `database/migrations/setup.php`: repeatable initial table setup; this is not yet a versioned migration runner.
- `database/seeders/create-user.php`: trusted command-line account provisioning.
- `public/index.php`: the application front controller, the only public PHP endpoint.
- `public/assets/`: CSS, JavaScript, images and icons.
- `resources/views/`: reusable application layout, dashboard, category and staff templates, plus the standalone login.
- `routes/web.php`: implemented browser routes. `routes/api.php` is reserved for future APIs.
- `storage/`: logs, invoices, imports, exports and backups.
- `tests/AuthTest.php`: isolated authentication, permission and routing checks.

Folders for future modules follow the supplied tree. Product CRUD, stock operations, sales, tax calculation and offline syncing remain future work; their controllers and PWA files have not been added as empty placeholders. `config/tax.php` will be added with the tax module. The existing checkout directory remains `spare_pos` so its XAMPP URL continues to work.

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

## Phase 1 — categories and inventory foundation

From the owner workspace, select **Manage categories**, or open `public/index.php?route=/categories`. Staff and superadmins cannot use these owner-only routes. Categories and all inventory data are scoped to the owner's shop; uniqueness is enforced per shop (subcategory names and slugs per parent).

The catalogue seed contains the supplied **10 main categories and 39 subcategories**. Run `database/migrations/inventory.php` for an existing installation, and again after provisioning a new shop. It creates missing tables, never replaces existing ones, and checks existing column compatibility before applying DDL. Repeated seeding inserts only missing names and does not overwrite descriptions, status or order. Archived names stay reserved. If an older table is incompatible, review and migrate its data explicitly rather than dropping it.

Owners can expand categories, add/edit both levels, set active/inactive status, enter a sort order or use up/down controls. Counts include all linked products, including inactive or archived products. Deactivating a parent makes it unavailable for new assignments even when its children remain active.

**Deletion means archival.** Empty subcategories can be archived directly. Referenced subcategories require a valid active destination (another subcategory, or a main category without a subcategory). Main categories must have no products and no live subcategories before archival; alternatively, merge into another active category. Merge combines matching subcategory names, copies other subcategories safely, reassigns every product and archives the source records in one transaction. Conflicts abort and roll back the entire merge. Archival retains IDs and historical references; archived entries are hidden from the live editor, with names reserved. Archived entries can be restored from the category editor. Restore the main category before restoring its children.

All mutations lock the shop row to serialize category operations. Composite foreign keys enforce shop isolation and require a product's selected subcategory to belong to its selected category. All inventory foreign keys restrict deletion; products and historical stock records are never cascaded away. `CategoryService::validateProductCategory()` also checks that destinations are active and unarchived; future product writes must perform category validation and writes atomically while taking the same shop lock.

Prepared tables: `brands`, `units`, `tax_rates`, `products`, `product_aliases`, `stock_movements` and `stock_balances`. Products require a category and base unit, allow an optional subcategory, and have purchase/sale conversion factors. Stock quantities use decimal base units. Movement event keys are unique per shop; database triggers reject ledger updates/deletions, so corrections require reversal entries. Balance updates and movement inserts must be implemented together in future stock services; no product, stock-entry or POS screens were added here. Tax rates and units are not seeded with unapproved values.

### Phase 1 validation

- `RUN_MYSQL_TESTS=1 /Applications/XAMPP/xamppfiles/bin/php tests/CategoryTest.php`: MySQL constraints, idempotent catalogue, category/subcategory creation, editing, reordering, duplicate prevention, shop isolation, product relationship validation, deletion restrictions, reassignment, merge rollback and ledger immutability. Uses temporary shops and removes fixtures afterward.
- `python3 tests/CategoryHttpTest.py`: local Apache checks for owner-only access, CSRF, creation, editing, ordering, duplicate feedback, invalid parents, merge and archival. Uses an isolated temporary shop and accounts; fixtures are removed afterward so real category ordering is preserved. Set `SPARE_TEST_URL` or `SPARE_PHP_BINARY` if needed.
- `php tests/AuthTest.php` and `python3 tests/LoginHttpTest.py`: existing authentication regressions.

## Operational workspace redesign

Authenticated pages share `resources/views/layouts/app.php`: desktop sidebar collapse, mobile drawer navigation with focus handling, breadcrumbs, role/account menu and POST sign-out. `config/navigation.php` is the navigation registry; only Dashboard, owner Categories, and owner Staff & Permissions are enabled. Other requested modules are shown disabled, never linked to missing routes. The login and existing `/dashboard`, `/categories`, and `/staff/permissions` URLs remain valid. Staff settings now live at `/staff` and the original permission POST redirects there when JavaScript is unavailable.

`DashboardService` queries only existing, shop-scoped inventory tables. Active-product counts exclude inactive/archived products. Low-stock counts include active products with recorded balances and a positive minimum, at or below that minimum. Missing balances are disclosed instead of treated as zero. Valuation sums recorded quantities × average unit cost, including physically stocked inactive products. Inventory details require product, stock-adjustment or report permissions; cost valuation requires report access (owners have access). Superadmins without a selected shop see no invented global totals. The activity period (7/30/90 days) filters movements and latest recorded account sign-ins; stock totals remain current. Owners see team sign-ins; staff see their own only. Category audit events have not been invented.

Today's sales, bill counts, customer outstanding and supplier outstanding remain unavailable until sales, purchasing and payment modules exist. Sales trends, recent bills and payment ageing have explicit setup states. All six future quick actions are disabled; Manage Categories works for owners. No barcode features, schema recreation, demo business data or new transaction modules were introduced by this redesign.

Categories use dense expandable rows with independent add forms, live search, level/status filters, preserved expansion state, accessible reorder controls and the existing archive/merge workflows. JavaScript saves mutations in place and preserves form input on errors. Normal form posts and server-side GET filtering remain supported. Staff permissions also save in place. Both still run the original authentication, owner-role, CSRF and transactional business checks.

Additional verification:

- `php tests/DashboardEmptyTest.php`: setup states when inventory/sales/payment tables are absent.
- `RUN_MYSQL_TESTS=1 php tests/DashboardTest.php`: real totals, missing-balance handling, period filtering, shop isolation, permission boundaries and category filters, using rolled-back fixtures.
- `python3 tests/WorkspaceHttpTest.py`: shared shell, honest unavailable metrics, staff settings, grant/revoke visibility and category AJAX success/error responses. Uses an isolated shop via `tests/HttpFixture.php` and cleans it up.
- Desktop/mobile Chrome checks covered sidebar collapse, mobile drawer and Escape/focus behaviour, viewport overflow, category search/reset, compact rows, AJAX save/duplicate errors/archival, and staff permission saves with original grants restored.

### Category UX refinement

Category visits start collapsed. Expand All / Collapse All apply to visible categories; AJAX operations preserve expansion in memory. Search and level/status filters apply immediately. Active shows active unarchived entries, Archived exposes archived entries (with parent context where necessary), and All also includes inactive entries. Clear filters returns to the live Active catalogue. Sort values are hidden; up/down controls retain sorting.

Subcategory More actions includes reassignment/archive, moving to another main category and merging into a selected subcategory. Moving copies/merges into the destination and archives the original so its identity is retained; existing products keep their IDs. Restore and guarded permanent deletion appear for archived entries. Permanent deletion requires no product references and, for a main category, no children (including archived children). Restrictive foreign keys block historical references; archive instead when deletion is unsafe. These actions use the existing owner-only `/categories` endpoint and transactions, without schema changes.

Counts come from the database. Product counts remain text because no Product Management listing route exists yet; no nonfunctional listing links have been introduced. Category tests now cover move/merge, parent-first restoration and safe deletion, in addition to existing operations.

Main-category actions now use header dropdowns and a shared confirmation dialog. Merge loads an owner-scoped read-only preview through the existing `/categories` route, reporting real counts and matching names using the same name lookup as the transactional merge. Main edit is available from its header icon only. Archive/delete dialogs explain blocked references; permanent deletion stays separate from archive. Dropdowns dismiss outside, and modal errors preserve the selected destination. No schema or authentication changes are required.

## Product Management

Product listing, details, create/edit, duplicate, archival, owner brand/bike masters and product tax configuration are implemented. Apply `php database/migrations/products.php` after inventory setup. Imports and explicit fitment verification have separate staff permissions. [Product documentation](docs/products.md) covers migrations, stock atomicity, CSV/XLSX templates, separate compatibility import, permissions, catalogue sources and tests. Products navigation and Add Product now work; future POS and purchase screens stay disabled. No barcode or unit-management features were introduced.
