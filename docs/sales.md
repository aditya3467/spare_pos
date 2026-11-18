# POS and sales

Open **Sales → New Sale / POS**. Before the first sale, the owner must save **Settings → Tax & Invoice Settings** using the shop's actual legal details, registration type, state and tax classifications. No shop tax identity or rate is invented or seeded by this module.

## Database and installation

Prerequisites are the existing inventory and product migrations. Apply the additive sales migration:

```sh
/Applications/XAMPP/xamppfiles/bin/php database/migrations/sales.php
/Applications/XAMPP/xamppfiles/bin/php database/migrations/sales-labour.php
```

It creates `shop_tax_settings`, `customers`, `invoice_sequences`, `sales`, `sale_items`, `sale_payments` and `sale_cancellations`. Existing business data is preserved. Foreign keys restrict deletion; invoice numbers and checkout keys are shop scoped. The migration is idempotent and installs UPDATE/DELETE guards on posted sales, items, payments and cancellations. Customer name/phone and sales date indexes support lookup/history. Existing inventory search indexes are reused.

## Billing and tax

- Regular registration produces a Tax Invoice. Composition produces a Bill of Supply; unregistered produces a Retail Receipt. Composition and unregistered bills never collect GST separately, regardless of product rates.
- Prices come from each product's single selling price. Arbitrary rate overrides are rejected. There is no separate below-price feature; granted discount permission controls reductions.
- Product tax classification takes priority over the optional shop default. A regular GST invoice with no classification is blocked. HSN can be required by the owner. Existing product classifications are reused.
- The main POS has no Place of Supply selector. Walk-in billing uses the configured shop state. For unregistered customers, their recorded state on the invoice determines goods supply state when present; otherwise the shop state applies. Registered counter-sale goods are handed over at the shop, so use the shop state; labour uses the recorded recipient state, falling back to the shop state when no customer state is recorded. Same-state lines use CGST/SGST, other-state lines use IGST. Each line snapshots its state; a service state differing from the header is printed on that line. Shipping/site-installation workflows are not introduced. Legacy integrations can still submit an explicit validated state, preserving their existing behaviour.
- Line and bill discounts are fixed rupee amounts in the configured price basis. Bill discount is proportionately allocated over the remaining line values before tax, with residual cents reconciled. Inclusive tax is extracted; exclusive tax is added. BCMath calculations round each line to paise. Line totals, tax components and the invoice total reconcile exactly; no artificial invoice round-off is applied.
- Series is `PREFIX/YYZZ/NNNNNN`, at most 16 characters. Financial years follow April–March in Asia/Kolkata. Sequences are locked and incremented transactionally, and never reset when settings change. The next financial year has its own sequence.
- Customer and shop details, item names/SKUs/HSNs, quantities, rates, discounts, tax values and carrying costs are saved as snapshots. Product changes do not rewrite old invoices.

Reference: [CBIC tax invoice and bill of supply rules](https://cbic-gst.gov.in/gst-invoice-rules.html). GSTIN validation checks format and state; it does not query the GST portal or verify registration. Owners configure applicable rates and HSN requirements; automated classification, e-invoicing/IRN and statutory filings are outside this module.

## Posting and stock

Checkout validates and locks settings, product rows in stable product-ID order and balances. Server quotes are recalculated under those locks. If prices, availability or settings differ from the displayed quote, the operator must review and confirm again. The stock service checks ledger/balance consistency and posts a distinct `sale` movement with invoice reference, previous/resulting quantities and current carrying cost. Shared stock-writing code maintains the balance; there is no parallel stock decrement implementation.

Invoice creation, all lines, stock movements, balances, numbering and payments share one transaction. Any failure rolls everything back. A shop-scoped checkout key plus payload hash returns an already-posted invoice on identical retries, including concurrent retries; changed payloads using that key are rejected. Existing manual inventory request receipts remain intact.

## Payments and cancellation

Cash, UPI, card and bank transfer are manual counter records. Noncash records require references. Split payments are separate rows. Cash may exceed the bill; change is deducted from the cash payment applied to the invoice. Noncash overpayment is rejected. Partial/credit sales require both `sales.credit` and a selected customer. Payment date and actor are recorded. Collection of outstanding credit after checkout is a future customer-payment workflow, not silently implemented here.

Posted documents cannot be edited/deleted. Authorised full cancellation requires a reason, acknowledgement that all stock can be restored, and acknowledgement that original-method refunds have actually been completed (or no payment was received). It does not send funds. Cancellation restores each item using an opposite movement at its original cost, links each original movement, creates an audit cancellation row and appends negative payment reversal rows for applied payments (excluding cash change). Original documents/payments remain unchanged. Duplicate cancellation returns the existing cancellation. Failure during stock or refund recording rolls everything back. Generic inventory reversal cannot bypass the invoice cancellation workflow. Physical customer returns and partial refunds remain separate future work.

## Permissions and UI

Owner has all sales permissions. Staff must be granted POS access and create sales to use POS, plus independent permissions for discounts, credit, history, cancellation, printing and payment details. A creator without history permission can view their own checkout invoice. Revenue/profit reporting requires `reports.view`; recording or correcting actual labour expenses additionally requires `expenses.manage`. Customer lookup/creation is available within authorised POS without leaving the cart. Sales and customer reads are shop scoped, and mutations retain CSRF protection.

Product search reuses ranked inventory search with 275ms debounce and outdated-response protection. It covers names, SKU, OEM, aliases, brand and recorded compatible bikes. Only active, unarchived products can be sold. Cart quotes refresh current product data and block insufficient stock. Arrow keys move through search Add buttons; Enter adds the focused match.

Draft product/labour/cart/customer data is stored in the current browser tab's session storage, separated by shop and user. A reload restores the draft and revalidates it. No offline sale posting is allowed: server confirmation is required. Drafts are not shared across tills/devices.

Invoices have A4 and 80mm browser print views; browser Save as PDF is supported. Printing/reprinting is read-only. Choose matching paper size in the browser/printer settings. Physical printer hardware has not been exercised. Sales history supports real pagination, sorting, invoice/customer search, date, payment and sale-status filters; cancelled invoices retain original posted amounts with their outstanding credit explicitly voided.

## Files

- `app/Services/{SaleService,SaleTaxService,SalesSettingsService}.php`: checkout, customers, history, cancellations, monetary calculations and settings.
- `app/Services/StockService.php`: extracted shared ledger writer and invoice-linked sale/cancellation posting; manual operations retain their request guards.
- `app/Controllers/SalesController.php`, `app/Middleware/SalesMiddleware.php`, `routes/web.php`: protected HTML/JSON endpoints.
- `config/{navigation,permissions}.php`, `resources/views/layouts/app.php`: navigation, grants and scoped asset loading.
- `resources/views/sales/{pos,settings,history,invoice,document,print}.php`: POS, configuration, history and shared invoice rendering.
- `public/assets/js/{pos,invoice-print}.js`, `public/assets/css/sales.css`: scoped interactions, responsive layouts and print styles.
- `resources/views/inventory/{history,movement}.php`: Sale label and invoice link; sale reversals use invoice cancellation.
- `database/migrations/sales.{php,sql}`: additive database setup and immutable-record guards.
- `tests/Sales{Test.php,Worker.php,HttpTest.py,BrowserTest.mjs}`: disposable database, concurrent workers, HTTP and Chrome checks.

## Verification

```sh
RUN_SALES_TESTS=1 RUN_SALES_BROWSER=1 /Applications/XAMPP/xamppfiles/bin/php tests/SalesTest.php
```

This creates/drops its own MySQL database, starts an isolated local PHP server, and optionally runs headless Chrome. Fixtures never populate the working shop database. Tests cover tax modes, product/default classification, rounding and discounts, payments and credit, stock/cost snapshots, stale quotes, full rollback, concurrent overselling and repeated requests, cancellation/refund rollback, immutability, permissions, filtering/pagination, customer popup, cart reload, A4/thermal PDF generation and mobile widths 360/390/430. Existing inventory, product, category and auth suites are also run for regressions. No purchase, supplier, barcode, unit conversion, gateway or accounting module is added.


## Labour services, revenue and actual costs

The additive `sales-labour.php` migration creates `sale_services` and `service_expenses`, with restricting foreign keys, retry/reversal uniqueness and immutable UPDATE/DELETE guards. It adds nullable `labour_tax_rate_id` and `labour_sac_code` to shop settings, validating the classification against the same shop. It does not alter historical invoices, product rows, stock or existing invoice-line constraints.

**Current Bill → + Add Labour Charges** adds an editable description and charge amount. Multiple entries, per-line discounts (with permission), removal and draft recovery are supported. Labour requires no SKU, category, brand or catalogue product. It is posted only into `sale_services`, never `sale_items` or the inventory ledger. Product-only, labour-only and combined sales share the existing quote, payment, idempotency, confirmation and transaction flow. A failed service write rolls back the entire combined bill including stock movements.

Regular GST shops must explicitly choose a labour classification in Tax & Invoice Settings; the product/default product rate is not assumed for services. Configure the applicable SAC; it is required when the existing HSN/SAC-required setting is enabled. No rate or SAC is guessed or automatically assigned. Composition/unregistered bills collect no GST for labour. Bill discounts are proportionately allocated across products and services before tax, including mixed classifications and inclusive/exclusive pricing. A4 and thermal invoices identify a separate Labour charges section without a SKU.

`RevenueReportService` is shared by **Reports → Sales & Profit Reports** and the dashboard. Revenue is after all discounts and excludes GST. Product revenue and product carrying-cost COGS remain separate from service revenue. Labour gross profit equals its service revenue, since no inventory cost is assigned. Combined gross profit = product revenue − product COGS + service revenue. A consistent database snapshot keeps category totals reconciled during concurrent posting.

Actual paid technician payouts/labour costs can be recorded in the report with description, payee, amount, expense date and optional invoice link. No expense is inferred from a service charge. Net after recorded expenses = gross profit − actual recorded labour expenses. Product gross profit, labour gross profit and labour net profit are shown separately. Other general operating expenses and comprehensive accounting are not implemented; the report labels this limited net figure explicitly.

Cancellation preserves all original service rows and appends the existing invoice/payment/stock audit reversals. Reports recognise invoice revenue on the invoice date and negate it on the cancellation/refund date, including product COGS reversals; cross-period refunds therefore leave the original period intact and show negative revenue/profit in the refund period. Existing refund support is full-invoice cancellation, not a new partial-return/refund module. Actual paid labour expenses are not automatically refunded when a customer's invoice is cancelled. When funds really are recovered, or an expense was entered incorrectly, **Record recovery / correction** appends a single linked negative expense without rewriting the original. Retry keys and reversal uniqueness prevent duplicate costs/corrections.

Additional files: `app/Services/RevenueReportService.php`, `database/migrations/sales-labour.{php,sql}`, `resources/views/sales/report.php`, `tests/LabourSalesAssertions.php`; dashboard service/view, tax settings, sales service/controller/middleware/routes, shared invoice rendering and POS assets are extended. Original product and inventory writers are unchanged by the labour feature.

Labour-specific checks cover no-SKU/no-stock service posting/cancellation, multiple lines, editing/removal, line/bill discounts, tax modes and different rates, per-line automatic supply state, retries, atomic failure, actual expenses/recoveries, isolated report permissions, cross-period refunds, dashboard/report reconciliation, printing, draft restore and mobile widths.

Place-of-supply references: [IGST Act](https://upload.indiacode.nic.in/showfile?actid=AC_CEN_2_2_00043_201713_1517807328116&filename=a2017-13.pdf&type=actfile) and [CBIC circular 209/3/2024 on goods supplied to unregistered persons](https://gstcouncil.gov.in/sites/default/files/2024-09/circular-no-209-03-2024.pdf).
