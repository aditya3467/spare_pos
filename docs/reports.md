# Reports & Analytics

Open **Reports → Reports Dashboard**, **Sales Reports**, **Profit Reports** or **Inventory Reports**. These are read-only views of existing sales, invoice lines, service lines, payments, stock balances and append-only stock movements. They do not create accounting entries or change POS, product or inventory transactions.

## Setup and files

Apply the existing product, inventory, sales and labour migrations, then:

```sh
/Applications/XAMPP/xamppfiles/bin/php database/migrations/reports.php
```

The additive, idempotent migration adds only `sale_payments(shop_id,payment_date,id)` when an equivalent leading-column index does not already exist. No tables or financial data are duplicated. Existing sale-date, sale-item, product, stock and cancellation keys are reused.

Implementation:

- `app/Services/ReportService.php`: shared period selection, sales eligibility, sale-time cost validation, report datasets, current stock metrics and transaction-consistent reads.
- `app/Services/ReportExportService.php`: CSV and real `.xlsx` workbooks, generated in temporary files and removed after download.
- `app/Controllers/ReportController.php`, `app/Middleware/ReportMiddleware.php`, `routes/web.php`: protected read-only report/export routes.
- `resources/views/reports/{index,components}.php`, `public/assets/{css/reports.css,js/reports.js}`: responsive tabs, metrics, SVG charts, tables, filters, pagination and exports.
- `config/navigation.php`, `resources/views/layouts/app.php`: report navigation and scoped assets.
- `app/Services/DashboardService.php`, `resources/views/dashboard/index.php`: use the same sales/profit eligibility and cost checks for the main dashboard. Gross profit is labelled as gross profit; unrelated shop workflows remain unchanged.
- `tests/Reports{Test.php,HttpTest.py,BrowserTest.mjs}`: isolated calculations, permissions, downloads, date controls, pagination and responsive browser verification.

## Dates, scope and cancellation policy

Presets are Today, Yesterday, This Week (Monday onwards), This Month, Last Month and Custom Range, using Asia/Kolkata calendar dates. Sales and revenue trends use invoice timestamps. Payment/refund tables use actual payment dates. Stock movement summaries use the entry date, with posting date as the legacy fallback, consistent with inventory history.

The reports dashboard has explicitly labelled calendar-today and calendar-month cards, plus selected-period sales, gross profit, invoice count and average invoice value. Today's and monthly cards keep their fixed calendar meanings when the selected period changes. Inventory valuation and low/out counts are current snapshots, not reconstructed balances at the historical end date.

**All currently cancelled invoices are excluded from sales, revenue, profit, best sellers, velocity, trends, invoice counts and credit totals.** Cancellation can therefore change the original period's eligible sales. Invoice history retains cancelled records clearly marked `Cancelled (excluded)` for audit. This is different from the older standalone `/sales/report` event-based view, which remains available at its original route for its existing labour-expense workflow; new analytics uses the requested cancelled-invoice exclusion policy.

Refunds are the negative applied payment records created by supported invoice cancellation. They are disclosed in their actual refund period and are not subtracted again from already cancellation-excluded sales/profit. Cash change is not a refund. The payment table includes original receipts and their reversal records; net collection can be negative when an earlier invoice is refunded in the selected period.

Full invoice cancellation/refund is the existing supported return-like financial operation. There is no partial return/credit-note module. A standalone `customer_return` stock movement does not establish a customer refund or invoice revenue reversal, so no financial value is invented from it.

Search, product category, stock status and sorting filter **detail tables and their corresponding exports**. Headline metrics/charts cover the whole selected date range. Product status defaults to active for inventory detail rows; the valuation card explicitly includes all recorded balances (active, inactive and archived). Low stock uses positive quantity at/below a configured minimum. Out of stock requires a recorded zero balance; missing balances remain `Not tracked`.

## Sales and payment accuracy

Sales totals and average invoice values include recorded GST. Revenue is after all line/bill discounts and excludes GST. Invoice count counts invoices, not payment rows. Discounts come from the posted invoice snapshot and are not applied a second time.

Cash, UPI, Card and Bank Transfer use individual `sale_payments.amount` records. Split payments are never joined into a repeated invoice total. Gross applied receipts, refunded amounts and net collections are separate. Credit is the recorded outstanding balance on eligible invoices issued in the period and is labelled **not a payment**. Collections can differ from sales because their dates, refunds and credit timing differ.

Daily/weekly/monthly trends aggregate the same eligible invoice cohort. Weekly buckets are labelled by Monday start; monthly buckets by first day. Charts show recorded buckets; the data table/export provides exact figures. Best sellers are available by quantity and tax-exclusive revenue.

## Profit and historical cost quality

Product COGS uses **sold quantity × the carrying cost saved on the invoice line**, validated against the linked original sale stock movement, its quantity, invoice reference and previous average cost. It never substitutes today's purchase price, selling price, current average cost or current stock valuation. This preserves the existing weighted-average inventory basis, including product-related costs already incorporated into that carrying cost. Costs not recorded by inventory are not estimated.

Labour is sourced separately from immutable `sale_services` rows. Labour revenue equals labour gross profit because no product COGS is assigned. Product gross profit = product revenue − recorded product COGS. Total gross profit = product gross profit + labour gross profit. Product margin = product gross profit ÷ product revenue × 100; zero-revenue margins remain unavailable, not a fabricated percentage.

Missing/inconsistent movement links, costs or revenue snapshots are flagged. Recorded zero cost is accepted only when an explicit zero-cost receipt supports the current stock cycle; an uncosted counted-stock increase is not assumed to be a free purchase. The report also checks invoice revenue against recorded product/service lines. Affected product profit and aggregate COGS/gross profit become **Unavailable**, rather than silently using zero or reporting a partial sum as the total. Category allocations also become unavailable when invoice/line revenue does not reconcile.

Cost exceptions identify the invoice, line, product, recorded unverified cost and reason. Separate invoice-reconciliation exceptions identify incomplete/mismatched headers. Both can be exported. No historical record is modified or backfilled. Current balance/ledger quantity discrepancies are disclosed; reports do not attempt to repair balances or replace recorded valuations.

Operating expenses, technician payouts and a net-profit calculation are **not part of this analytics module**. Existing expense records do not reduce the gross-profit figures. No purchase, supplier, credit-collection, expense-management or accounting system is added.

## Inventory analytics

Current stock rows show quantity, carrying cost/value, category/brand, minimum/status, selected-period sold quantity and latest eligible sale in that period. Valuation totals use full recorded decimal carrying values before rounding; individual rows are displayed to paise. Active/inactive/archived filters are available without changing past sale snapshots.

Fast movers are the highest positive quantities sold, slow movers the lowest positive quantities, and **No sales in period** lists zero-sales products separately. Cancelled invoices do not create sales velocity. Stock In, Stock Out, adjustment increases/decreases, sale deductions, opening stock and reversals remain separate movement categories, using recorded quantities and reasons. The movement export includes entry/posting dates, references, costs and available previous/resulting stock snapshots; absent legacy fields stay blank.

## Exports, permissions and limits

CSV and Excel exports are available for invoice history, product profit/margins, labour/service revenue, stock, movements, payments, trends and cost/reconciliation exceptions. They export **all matching rows**, independently of the on-screen 25-row page. The 10-row secondary service/movement/exception previews explicitly show their limits; exports include all results.

CSV uses UTF-8 with a BOM and neutralises formula-leading text. XLSX is an actual Open XML ZIP workbook, with text SKUs (preserving leading zeros), typed numeric values, blank unknown costs/profits, a header row, frozen header and auto-filter. Formula-like names/references are stored as text. Excel requires PHP's ZIP extension; when absent, an explicit error directs the user to CSV. The XLSX sheet row limit is enforced with an error requesting a smaller range; records are never silently truncated. Monetary display uses paise; Excel's own numeric precision limits still apply to unusually large numbers.

All pages and downloads require an authenticated shop account with existing `reports.view`. Owners have access by default. Report-only staff can see/export analytics but invoice/movement links appear only with the corresponding sales-history/inventory-history grant. Shop identifiers in query strings cannot change scope. No external chart framework/CDN is required, and no new data is cached into financial tables. Large exports need normal PHP/MySQL memory/time limits appropriate to their row count; CSV is the lighter option.

## Tests

```sh
RUN_REPORT_TESTS=1 RUN_REPORT_BROWSER=1 /Applications/XAMPP/xamppfiles/bin/php tests/ReportsTest.php
```

Tests create/drop a disposable MySQL database and use known sales, weighted-cost stock receipts, split/partial/credit payments, service lines, same/cross-period cancellations, genuine zero costs, uncosted legacy records, header mismatches and ledger discrepancies. They verify formulas, current/historical cost separation, dates, search/pagination, full exports, valid XLSX XML, formula protection, role/shop isolation, CSV/Excel downloads, chart/card layout and mobile widths 360/390/430. POS/labour, inventory, product and dashboard regressions are run separately. No test transactions are inserted into the working shop.
