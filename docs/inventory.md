# Manual Inventory Management

## Setup and routes

Run `php database/migrations/inventory-management.php` after the existing inventory/product migrations. It extends `stock_movements` with previous/resulting quantities and average costs, reason, effective entry date and explicit-cost flag. Existing movement types and immutable rows remain intact. New manual types are Stock In, Stock Out and Adjustment Increase/Decrease; Opening Stock and Reversal retain their original identities. It adds a single-reversal unique index, a non-negative balance constraint and `inventory_requests` receipts for safe retries (including zero-difference adjustments). No second stock ledger or balance table is introduced.

Working routes via `index.php?route=`:

- `/inventory` (also `/inventory/stock`): metrics, stock list, low/out filters, recent movements.
- `/inventory/in`, `/inventory/out`, `/inventory/adjust`: manual entry forms. `/inventory/form&kind=in|out|adjust|reverse` also supports product or original-movement selection.
- `/inventory/search`: scoped exact-product picker search by name, SKU or OEM.
- `/inventory/post`: POST-only stock changes with CSRF, session form token and explicit confirmation.
- `/inventory/movements`, `/inventory/movement&id=…`: filtered/paginated history and immutable movement details.

## Permissions

Owners have full access. Staff grants are configured on the existing Staff & Permissions page:

- `inventory.view`: inventory overview and stock list.
- `inventory.stock_in`: receive stock.
- `inventory.stock_out`: remove stock.
- `inventory.adjust`: counted-stock adjustments and reversals.
- `inventory.movements`: movement history; required alongside adjustment permission for reversals.

Writer-only staff can post authorised stock entries without receiving overview/history access. Product-detail links are shown only when product permission is also granted. All queries and writes are shop-scoped; selecting or reversing another shop's records is rejected. No normal edit/delete movement routes exist.

## Stock rules

Each write locks the existing product, then its stock-balance row, and validates that the cached quantity equals the ledger sum. Movement insertion, balance update and retry receipt commit together. Decimal quantities and cost calculations use BCMath; no unit management or conversion exists. Insufficient removals and negative results are rejected. Database constraints also reject negative balances. Simultaneous operations serialize on the same SKU; unrelated products need not share that stock lock.

Stock In accepts a positive quantity, optional cost, date, reason, reference and notes. Blank cost uses the current carrying average, not the selling or purchase price; explicit zero means free stock. Weighted average cost updates on additions. Stock Out removes at carrying cost and does not generate supplier/payables or sales transactions. Inactive/archived products may be depleted or counted, but cannot be replenished through Stock In.

Adjustments compare the physical count against the locked system quantity and append only the difference. The form includes the previously displayed quantity: if another posting changed it, submission is rejected and the user must reload/recount. A zero difference creates no movement. Its receipt ensures retrying that old no-op cannot overwrite subsequent stock changes.

Every newly posted movement records previous/resulting quantities, cost basis, actor and actual posting timestamp. Entry date is recorded separately and may be backdated but not future-dated. History filters use entry date; date sorting uses posting timestamp/ID. Opening stock creation now writes the same snapshot fields within the original product creation transaction. Editing and duplication retain their stock safeguards.

A correction never edits the original entry. Reversal posts its opposite quantity and links both records; only one direct reversal is allowed, and reversals cannot themselves be reversed. Current stock and value are revalidated. A reversal that would make quantity or remaining stock value negative is rejected; use an authorised counted-stock correction when appropriate. Transactions and product IDs are never cascade-deleted.

Low stock means positive quantity at/below a positive configured minimum. Zero stock is a separate state. Products without a minimum are not flagged low. Missing balances are disclosed as untracked, not assumed zero. Overview product/low/zero counts apply to active, unarchived products. Total recorded quantity includes physically held inactive/archived product balances. Products and the main dashboard use the same positive low-stock rule.

## Legacy data and limitations

No historical movement is rewritten or assigned invented reasons/timestamps. For older movements with null snapshots, previous/resulting quantities are derived from ledger posting-ID order and labelled as derived on details. Their recorded timestamp is retained, even if an old opening implementation used midnight on the opening date. Future movements have explicit snapshots and entry dates.

If pre-existing balances disagree with the ledger, posting is blocked for owner reconciliation rather than silently repairing historical data. This module does not include a cache-repair/reconciliation screen. Reversals are direct, one-time corrections; complex cost corrections may require a subsequent audited stock count. Purchase invoices, supplier management, sales/POS, customer returns, barcodes, warehouses and unit conversion remain outside this module.

## Tests

`RUN_INVENTORY_TESTS=1 php tests/InventoryTest.php` creates and destroys a disposable MySQL database using local test admin credentials (`TEST_DB_ADMIN` / `TEST_DB_PASSWORD` optional). It runs actual migrations, stock arithmetic, weighted costs, stale-count and negative-stock rejection, confirmation, no-op replay, reversals/audit links, owner/staff isolation, forced failure after balance/ledger writes, immutable triggers, legacy derivation, zero-vs-low classification, mismatch detection, and concurrent removal workers. It starts an isolated PHP HTTP server on port 8099 for `InventoryHttpTest.py`; no test stock is inserted into the live shop.

Add `RUN_INVENTORY_BROWSER=1` for `InventoryBrowserTest.mjs`: isolated headless Chrome on debug port 9230, real product search, confirmation/cancel/post, out-of-stock error, adjustment preview/post, reversal audit, and desktop/mobile layouts at 360/390/430px. It closes its own browser/server and drops its own test database. Requires local Chrome and Node with WebSocket support. Existing product, category, dashboard and authentication regression suites remain applicable; ProductTest now applies the inventory-management migration before testing opening stock.

## Search and clarity refinements

Inventory now uses `InventorySearchService` for both the server-paginated overview and stock-form product picker. Search normalises case and whitespace, AND-matches separate words across name/SKU/OEM/brand, aliases and explicit manufacturer/model/variant mappings, and avoids many-to-many duplicates through EXISTS. Relevance order is exact SKU, exact OEM, exact name, partial name, exact brand, compatible bike, alias, then cross-field token matches. Existing sort/direction break relevance ties. Each result includes matching context, brand and bike summary, without loading all products into JavaScript.

Manufacturer/model catalogue suggestions, model-scoped variants and an optional year filter reuse the existing bike masters. All chosen criteria must match the same explicit fitment record. Open-ended/unrecorded year bounds remain unrestricted; selecting a year does not turn an unverified fitment into a verified claim. Catalogue names are loaded for suggestions; product rows stay server-side. Text search and filters fetch the existing inventory endpoint as a fragment with 300ms debounce, request cancellation and sequence checks. Clearing text retains bike/stock/status/sort; Clear bike removes only manufacturer/model/variant/year. Page resets on changes, while asynchronous pagination and browser history preserve criteria.

Summary cards now distinguish all recorded balances from active-product balances. The separate inactive/archived counts and quantities explain exactly what the all-stock total includes. Active low/out counts exclude inactive/archived products and missing balances. Search/filter results do not redefine these global metric scopes. Movement reasons and reference numbers have separate columns; original/reversal links remain read-only.

Adjustment selections include product name, SKU and category/subcategory. Preview and confirmation explicitly show current stock, physical count, direction/difference and resulting stock. If the locked stock changed after preview, the existing transaction rejects the operation and the returned form refreshes stock/preview with confirmation reset. No posting or reversal rules were weakened.

Run `php database/migrations/inventory-search.php` to add only missing product-name, OEM and fitment-to-product covering indexes. Existing SKU, brand, alias and vehicle relationship indexes are reused. This does not add tables or rewrite data. Contains/substring searches still use bounded indexed shop scopes and EXISTS rather than claiming full-text index acceleration; consider a measured full-text strategy only if the catalogue grows substantially beyond the intended shop size.

The disposable InventoryTest suite additionally includes `InventoryRefinementAssertions.php`: search ranking/field coverage, multiword queries, duplicate fitments, variant/year constraints, actual status-scoped totals, filtering and pagination. InventoryHttpTest checks fragments, picker reuse and concurrent-preview refresh. With `RUN_INVENTORY_BROWSER=1`, InventorySearchBrowserTest verifies debounce, ranking, independent filter clearing, pagination/sort, stale requests ignoring cancellation, and responsive layouts alongside the original posting/browser checks.
