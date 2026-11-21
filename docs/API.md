# REST API

The API uses `/api/v1`. Send `Accept: application/json` and use a same-origin cookie session. Obtain the CSRF cookie with `GET /sanctum/csrf-cookie`. For write requests, URL-decode the `XSRF-TOKEN` cookie and send it as `X-XSRF-TOKEN`. Retain the session cookie and referer or origin context on subsequent API calls.

Responses use 401 for unauthenticated access, 403 for a role restriction within a business, 404 for an inaccessible or missing record, 409 for a resource conflict, and 422 for invalid input or an invalid workflow operation. Paged responses include `data`, `current_page`, `last_page`, and `total`.

## Account

| Method and path | Purpose |
| --- | --- |
| `GET /health` | API liveness check |
| `POST /register` | Create an account and business with `name`, `email`, `password`, `password_confirmation`, `business_name`, and optional `currency`. Include `legal_accepted` and the current `legal_version` from `GET /platform`. Supply `token` instead of `business_name` to register through an invitation. |
| `POST /login` and `POST /logout` | Start or end the session. Login accepts `email` and `password`. |
| `GET /account` | Current user and their business memberships |
| `PATCH /profile` and `PUT /profile/password` | Update `name` or submit `current_password`, `password`, and `password_confirmation` |
| `POST /invitations/accept` | Accept an invitation with `token` as an existing user |

## Business routes

The following paths are relative to `/api/v1/businesses/{business}`.

| Method and path | Purpose |
| --- | --- |
| `GET /products`, `/categories`, `/suppliers` | List active records with optional `search`, `sort`, and `page`. Products accept `category_id` and `stock_status` values `low`, `out`, `in_stock`, and `affected`. Products and suppliers accept `archived=1`. |
| `POST /{resource}`, `GET /{resource}/{id}`, `PUT /{resource}/{id}`, `DELETE /{resource}/{id}` | Create, read, edit, archive products and suppliers, or delete unused categories. |
| `GET /suppliers/{id}/products` | List products associated with that supplier’s purchase orders. |
| `POST /products/{id}/image`, `GET /products/{id}/image` | Upload a multipart `image` or retrieve it privately. JPEG, PNG, and WebP images may be up to 4 MB and 4096 pixels per side. |
| `GET /movements`, `POST /movements` | List movements, optionally by `product_id`, or record a movement with `product_id`, `type`, signed `quantity`, UUID `reference`, and `reason`. Supported manual types include `received`, `adjustment`, `damaged`, `supplier_return`, and `customer_return`. |
| `GET /purchases`, `GET /sales` | List orders with optional `search` by numeric reference, `status`, `from`, `to`, and `supplier_id`. |
| `POST /purchases`, `POST /sales` | Create a purchase or record a completed sale. Each request requires a UUID `request_id`, ISO date `order_date`, and `items` with `product_id`, `quantity`, and `unit_price`. Both accept `discount` and `notes`. Purchases require `supplier_id` and `status` set to `draft` or `pending` and accept `additional_cost`. Sales require `payment_method`. |
| `GET /{purchases|sales}/{id}` | Read an order, line snapshots, and operation history. |
| `POST /purchases/{id}/{submit|receive|supplier_return|cancel}` | Transition a purchase or record goods received or returned. |
| `POST /sales/{id}/{return|cancel}` | Reverse sold units and preserve the original sale. |
| `GET /dashboard`, `GET /reports/{type}` | Read metrics and business reports. `from`, `to`, and `search` filter applicable report rows. Reports accept `format=csv` for download. |
| `GET /settings`, `PUT /settings` | Owner access to the business name and the negative-stock setting. |
| `GET /members`, `POST /invitations` | Owner access to membership and invitation creation. Invitations require `email` and `role`, with `administrator` and `staff` allowed. |
| `PATCH /members/{id}`, `DELETE /members/{id}` | Change a teammate’s role or remove access. |
| `GET /activity` | Owner access to recent business activity. |

All order operations require a UUID `request_id` and a `reason`. Receipts and returns also require `items` containing `product_id` and `quantity`. Use the same identifier and payload when retrying an operation with an uncertain result.

Product write fields are `name`, `sku`, `unit`, `purchase_cost`, `selling_price`, `minimum_stock`, and optional `category_id`, `barcode`, and `description`. Categories accept `name` and optional `description`. Suppliers accept `name` and optional `contact_person`, `email`, `phone`, `address`, and `notes`.

## Permissions

Owners manage all operational records, business settings, invitations, and permissions. Administrators manage catalog and inventory records, purchasing, sales, and reports. Staff can view assigned catalog and inventory records and record sales. Finance fields such as purchase cost and cost of goods are omitted from staff responses.

## Reports

Supported types are `inventory`, `valuation`, `low_stock`, `out_of_stock`, `movements`, `adjustments`, `sales_by_date`, `sales_by_product`, `sales_by_category`, `sales_totals`, `purchases_by_supplier`, `purchases_by_date`, `purchase_totals`, and `purchasing_history`.

## Platform administration

- `GET /platform` publishes branding, SEO, legal documents, and public registration configuration.
- `GET /platform/assets/{logo|favicon}` serves the active platform asset.
- `GET /platform-admin/overview` and `GET /platform-admin/settings` are restricted to platform administrators.
- `PUT /platform-admin/settings` updates platform settings. SMTP passwords can be replaced or cleared. Responses only indicate whether a password is configured.
- `POST /platform-admin/assets/{logo|favicon}` uploads an `image` using multipart form data. `DELETE` resets the asset.
- `POST /forgot-password` accepts an email and returns the same response whether or not it belongs to a user. `POST /reset-password` accepts an email, recovery token, new password, and confirmation.

### User and business administration

- `GET /platform-admin/users` and `GET /platform-admin/businesses` accept `search`, `status=active|suspended`, and `page`.
- `GET /platform-admin/users/{user}` and `GET /platform-admin/businesses/{business}` include paginated memberships.
- `PUT /platform-admin/users/{user}/status` and `PUT /platform-admin/businesses/{business}/status` accept a boolean `suspended` and a required `reason`.
- `PUT /platform-admin/businesses/{business}/members/{user}` accepts a `role` and `reason`. Choosing `owner` transfers ownership to an existing active member. The previous owner becomes a business administrator.
- `DELETE /platform-admin/businesses/{business}/members/{user}` removes a membership and requires a `reason`. Transfer ownership before removing the owner.
- `GET /platform-admin/activity` lists platform moderation actions, actors, targets, reasons, and timestamps.
