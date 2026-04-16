# WooCommerce Coupon Affiliation

WordPress/WooCommerce plugin for an ambassador network tied to coupon codes. Built with support from AI-assisted tooling (Cursor).

## About

The plugin assigns specific coupon codes to users with the **Ambassador** role. It calculates commission on the net order base, exposes a partner-facing area in My Account, and gives shop admins payout reporting and controls.

## Features

- **Ambassador role:** A dedicated WordPress user role for partners.
- **Coupon assignment:** Link an ambassador to a coupon from the coupon edit screen in WooCommerce.
- **Commission base:** Commission is calculated on **order subtotal minus order-level discounts** (excludes tax and shipping in typical WooCommerce setups).
- **Ambassador panel:** A **My Account** tab with monthly stats and recent attributed orders.
- **Payouts:** WooCommerce admin screen **Ambassador Payouts** for monthly summaries, per-order transactions, and marking commissions paid (or void when orders are cancelled, refunded, or failed).
- **Status automation:** Commission is zeroed and payout status set to **void** when an order moves to cancelled, refunded, or failed.
- **Notifications:** Email to the ambassador when an attributed order is completed.

## Installation and setup

1. **Install the plugin** by uploading a ZIP under **Plugins → Add New → Upload Plugin**, then activate it.
2. **Activate the plugin** if it is not already active.
3. **Important:** Go to **Settings → Permalinks** and click **Save changes** once so the `/ambassador-stats/` endpoint is registered correctly.
4. **Create an ambassador:** Add or edit a user and assign the **Ambassador** role.
5. **Set commission rate:** On the user profile, set the ambassador commission **%** (defaults to **20%** if left empty).

## Technical reference (meta keys)

Useful when extending or debugging the plugin.

### Users

- `_ambassador_commission_rate` — Per-user commission percentage (0–100).

### Coupons

- `_assigned_ambassador_id` — User ID of the ambassador tied to the coupon.

### Orders

- `_order_ambassador_id` — Ambassador who earned commission on this order.
- `_order_ambassador_commission` — Commission amount in the order currency.
- `_commission_payout_status` — Payout bookkeeping: `unpaid`, `paid`, or `void`.

## Local development

This project uses `@wordpress/env`.

- Start environment: `npm start`
- Stop: `npm stop`
- Default WP admin login: `admin` / `password`

### Production ZIP build

To assemble a WordPress-ready plugin archive for distribution or upload:

1. Install dependencies: `npm install`
2. Run: **`npm run build`**

This creates **`wp-woocommerce-coupon-affiliation.zip`** in the repository root. The archive contains a single folder `wp-woocommerce-coupon-affiliation/` with PHP sources, `README.md`, and `admin/`, `includes/`, and `assets/` (suitable for **Plugins → Add New → Upload**). It excludes `node_modules`, Git metadata, `package.json`, lockfile, and local env files.

If `npm run build` fails with a missing **archiver** dependency, run `npm install` again (or `npm install archiver --save-dev`).
