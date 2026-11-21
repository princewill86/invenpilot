# Development report

## Implementation

InvenPilot includes business registration and membership, role enforcement, profile and password settings, product and supplier catalogs, private product images, inventory and alerts, purchasing and receiving, sales and returns, a dashboard, reports, CSV export, and Docker and CI configuration.

## Data model

- `users`, `businesses`, and `business_memberships` separate sign-in identities from company data.
- `business_invitations` stores expiring, email-bound token hashes. `activity_logs` records catalog, membership, and business settings changes.
- Categories, products, and suppliers belong to a single business. Composite foreign keys prevent cross-business category, supplier, product, and order links. Product SKUs are unique within a business.
- The current balance and weighted-average cost live on the product record. Each mutation writes an immutable `inventory_movements` entry with before and after balances, cost basis, reference, actor, and reason.
- Purchase and sales documents share `orders` and `order_items` while retaining distinct workflow rules. Lines snapshot product details, prices, units, and costs. `order_operations` and `order_operation_items` capture receipts, returns, and cancellations, and link each relevant operation to its stock entry.

## Operational and accounting rules

Stock quantities keep three decimal places. Money fields keep four. The ledger uses BCMath for stock balances and weighted-average calculations. Business row locks serialize transaction writes and make negative-stock policy changes visible before stock is updated. Product locks protect individual balances. Document UUIDs, operation UUIDs, and movement references support safe retries.

A purchase order does not affect stock until goods are received. A partial receipt leaves the balance available to receive later. A supplier return can remove up to the quantity received minus earlier returns. Sales deduct stock immediately. Customer returns and cancellations add compensating movements and preserve the original sale.

Valuation uses perpetual weighted average. Incoming receipts are valued at the purchase line’s unit cost. Discounts and additional purchase charges are allocated proportionally for purchase expenditure reporting, and are excluded from inventory unit cost. Gross profit is net sales revenue minus the cost of goods captured on each sale. Sales reversals are reported on the day of the return or cancellation. Purchase expenditure is recognized when goods are received and reduced for supplier returns. Discount allocations are rounded to four decimal places for presentation.

## Security and verification

Business membership and role restrictions are enforced server-side. Uploads are private and validated against file type, size, and dimensions. Cookie-backed sessions use CSRF protection and rotate credentials on authentication. Invitation links are single-use and the database stores only their digests. CSV exports neutralize spreadsheet formulas in business-provided text fields.

The project includes PHPUnit tests for authentication, tenant isolation, role boundaries, catalog changes, uploads, invitations, stock invariants, purchase receiving, returns, and report totals. CI is configured to use SQLite and MySQL and to run the Playwright workflow against a disposable Compose stack.

## Verification results

- PHPUnit passed all 23 tests and 227 assertions on both SQLite and MySQL 8.4.
- Playwright passed the production Docker workflow from business registration through inventory, purchasing, sales and returns, CSV export, mobile layout, dark mode, and logout.
- A separate browser run verified all four demo roles, platform administrator authorization, server-rendered SEO, and the public registration switch.
- Password recovery was verified end to end with a local SMTP inbox. The production installation requires configured mail delivery. PHP mail requires a working sendmail-compatible transport.
- The platform moderation browser workflow verified revocation of a live user session, tenant suspension and reactivation, ownership transfer, and audit history.
- Concurrent sales against the same MySQL stock balance yielded one accepted sale and one insufficient-stock response. Receipt replay and cancellation restored the expected balance.
- The frontend production build and Composer validation passed. Composer and npm reported no known vulnerabilities in the locked dependencies at the time of verification.
- The local Docker stack passed its health check and all eleven migrations are applied. GitHub Actions is configured for CI. The checks above ran locally.

## Deployment boundaries

The application supports one stock balance per product within a business, one reporting currency per business, and one location per stock balance. It does not transact payments, send invitation emails, or sync with external accounting systems. Invitations use copyable links. Report printing uses the current page of filtered results. CSV includes all matching rows.

See [README.md](../README.md#deployment) for environment, HTTPS, backup, migration, and deployment instructions.
