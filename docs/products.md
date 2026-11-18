# Product Management

Run `php database/migrations/products.php` after inventory setup. It adds product fields (purchase price, OEM/part number, rack, creation key and edit version), makes MRP optional, and creates shop-scoped vehicle manufacturer/model/variant, fitment/link and specification tables. Existing data is preserved; rerunning is safe. New products use an internal fixed Item reference to satisfy the existing stock schema. No unit controls, conversion, barcode features or price tiers are introduced.

Permissions: `products.manage` for catalogue operations, `products.import` additionally for imports, `products.verify_fitment` for explicit verification. Owners manage brand, bike and tax masters. Staff grants are configured on the existing Staff & Permissions page. All product and master references are checked within the current shop.

Each distinct part gets a unique SKU. Compatible bikes share that product's inventory. Product, opening movement, balance, aliases, specifications and fitments are committed atomically. Failure anywhere rolls back all records. Creation/import keys prevent duplicate stock on retries. Editing uses version checks, preserves stock and rejects opening-stock fields. Stock movements remain append-only. Subsequent changes belong in inventory operations, not this editor. Archive retains the product, stock and history.

Duplicate opens an unsaved prefilled form with a fresh creation key. It copies product information but excludes SKU, stock, balances and movement history. Opening quantity defaults to zero and fitment verification is reset. A new product is created only on submission.

## Import instructions

Download the CSV template (opens in Excel). Upload CSV or `.xlsx`; legacy binary `.xls` is not supported. Use the first worksheet, template headers, plain values (no formulas), ISO dates `YYYY-MM-DD` and non-negative decimal numbers. Limits: 5 MB uploaded file, 20 MB expanded workbook, 1,000 rows. Blank SKU generates a new SKU. Provided SKUs must be unique. Category, subcategory, brand and named tax rate must already exist and be active. Unknown masters are rejected, never auto-created. Subcategories must belong to their category. Aliases are comma-separated names; specifications use `Label=Value;Label=Value`.

Preview shows row errors and possible same-name/brand/part-number duplicates. Duplicate SKUs in the file are rejected. Possible duplicates require confirmation; products are never automatically merged. Commit revalidates rows, creates each valid product atomically using the manual stock service and reports rejected rows explicitly. The result/report can be downloaded. Retrying a preview never adds duplicate products or opening movements.

### Separate compatibility import

Download the compatibility template and select **Compatibility for existing SKUs**. Columns: `sku,manufacturer,model,variant,year_from,year_to,notes`. SKU, manufacturer and model are required. All names must match existing shop masters and variants must belong to the model. Years and notes are optional. This workflow adds explicit mappings to existing non-archived products only. It never creates products, changes prices or stock, or verifies new mappings. Existing verified fitments remain unchanged. Multiple rows per SKU are supported; retries do not duplicate mappings. Verification is a separate authorised action in the product editor.

## Runtime and tests

Requires PHP PDO MySQL, mbstring, BCMath, ZipArchive and SimpleXML. `storage/imports` and `storage/logs` must be writable by the web server and blocked from HTTP. This local XAMPP installation grants directory ACL access specifically to `daemon`. Import previews are private files (`0600`), user/shop-bound, expiring after two hours. Administrators may remove expired `storage/imports/product-*.json` files; they are staging files, not business records.

- `RUN_PRODUCT_TESTS=1 php tests/ProductTest.php`: disposable MySQL database, real migrations, atomic stock/forced rollback, retries, duplication, multi-bike inventory, permissions, CSV/XLSX and compatibility imports. Uses local MySQL admin; optional `TEST_DB_ADMIN` / `TEST_DB_PASSWORD`.
- `python3 tests/ProductHttpTest.py`: temporary shop, real HTTP creation/edit/duplicate and multipart CSV upload/preview/commit/report/replay. Zero-stock fixtures and guarded cleanup.
- Chrome: dynamic specifications/bike rows, dependent selectors, duplicate/reset stock, unique SKUs, editing, search, desktop/tablet/mobile overflow.
- Existing category, workspace and authentication regressions also run. No sales, purchase invoices, POS or stock-adjustment module is implemented here.

## Bike seed sources

Names only; no variants or product compatibility are inferred. Owners can add missing bikes.

- [Hero](https://www.heromotocorp.com/en-in/motorcycles/splendor-plus.html)
- [Honda](https://www.honda2wheelersindia.com/)
- [TVS manuals](https://www.tvsmotor.com/our-service/user-manual), [Apache](https://www.tvsmotor.com/tvs-apache)
- [Bajaj Pulsar](https://www.bajajauto.com/bikes/pulsar/pulsar-150), [Platina](https://www.bajajauto.com/Bikes/Platina)
- [Suzuki](https://www.suzukimotorcycle.co.in/media-kit)
- [Royal Enfield](https://www.royalenfield.com/in/en/motorcycles/)
- [Yamaha](https://shop.yamaha-motor-india.com/collections/motorcycle)

## Live product search

The existing `/products` endpoint also returns a results fragment when `Accept: application/json` is requested. Listing SQL and permission checks are reused. The unchanged search input searches while typing after a 300 ms debounce and two trimmed characters; clearing it or entering fewer than two characters restores the default list with the other filters intact. The search, filter and sort controls remain mounted while only results/pagination are replaced. New searches reset pagination. Pagination is asynchronous, retains filters/sort order, and supports browser history. AbortController and request-version checks prevent stale responses; loading and no-match states are announced accessibly. Without JavaScript, the original GET form and pagination remain usable.

`tests/ProductLiveSearchBrowserTest.mjs` tests debounce, field coverage, combined category/subcategory/brand/bike/stock/status filters, clearing, empty state, pagination, sorting and delayed overlapping requests that ignore abort. Start a dedicated headless Chrome profile on local debug port 9227, then create a fixture with `php tests/HttpFixture.php create > /private/tmp/product-search-fixture.json`, prepare it with `php tests/ProductSearchFixture.php /private/tmp/product-search-fixture.json`, and run `node tests/ProductLiveSearchBrowserTest.mjs /private/tmp/product-search-fixture.json`. The browser test uses the local XAMPP URL and closes that isolated browser. Always clean up with `php tests/ProductSearchFixture.php /private/tmp/product-search-fixture.json cleanup`, followed by `php tests/HttpFixture.php cleanup < /private/tmp/product-search-fixture.json`. The guarded fixture helper refuses shops it did not create and refuses cleanup when stock ledger entries exist.

## Responsive product listing

The desktop table keeps its existing styling and columns. At 640px and below, CSS presents those same table rows as product cards: no duplicate data loop or separate action forms. Cards retain SKU, OEM, category/subcategory, brand/bikes, purchase and selling prices, stock, status and the existing actions. Mobile filters use a two-column grid with a full-width search row and 44px controls. Add Product is the primary mobile header action. Live-search fragments and pagination use this same markup.

`tests/ProductResponsiveBrowserTest.mjs` uses the same isolated Chrome/fixture setup as the live-search browser test. Run `node tests/ProductResponsiveBrowserTest.mjs /private/tmp/product-search-fixture.json`, then use the documented fixture cleanup commands. It checks desktop table width/columns, mobile cards at 360/390/430px, filter pairing, touch targets, header actions, no horizontal overflow, live search, AJAX pagination and switching back to desktop.
