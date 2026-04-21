# Checkout Monitor MVP

## Problem

WooCommerce checkout issues are easy to miss until a client or customer reports them.

The current visibility gap is spread across multiple layers:

- WooCommerce checkout validation
- payment gateway responses
- checkout JavaScript failures
- plugin-specific validation failures like Turnstile
- PHP/server-side checkout errors

There is no single clean off-the-shelf plugin that provides focused checkout observability for WooCommerce.

## Goal

Build a lightweight, passive checkout monitor that:

- logs meaningful checkout failures without changing checkout behavior
- surfaces recurring failure patterns early
- helps diagnose whether failures come from WooCommerce, JS, payment methods, or known integrations
- gives a simple admin dashboard for review

This should be safe to run on a live ecommerce site because it is observational only.

## Product Positioning

This could start as an internal tool for `gunsafes.com` and later become a broader WooCommerce agency/operator plugin.

Best-fit users if expanded:

- WooCommerce agencies
- freelancers supporting active ecommerce sites
- store operators with meaningful checkout volume

## Non-Goals

The MVP should not:

- block or modify checkout submissions
- attempt automatic remediation
- capture full raw checkout payloads
- log sensitive payment data
- try to predict every abandoned cart
- try to support every plugin-specific edge case on day one

## Core Design Principle

Passive checkout incident logger, not checkout controller.

The plugin should observe a few high-signal surfaces and store normalized events.

## MVP Scope

### 1. Server-Side Checkout Event Logging

Capture a normalized incident row on a small set of WooCommerce checkout surfaces.

Initial targets:

- `woocommerce_after_checkout_validation`
- `woocommerce_checkout_process`
- `woocommerce_order_status_failed`
- `woocommerce_checkout_order_processed`

What to log:

- timestamp
- site environment
- request path
- checkout context
- payment method
- order ID if available
- cart total if available
- customer identifier in masked form if needed
- normalized error source
- normalized error code
- human-readable error message

### 2. Checkout JavaScript Error Capture

On checkout pages only:

- capture global JS errors
- capture failed checkout AJAX responses
- capture WooCommerce `checkout_error` events

Post those to a lightweight internal endpoint for logging.

This is important because some checkout issues never become PHP errors.

### 3. Simple Admin Dashboard

Create a wp-admin page showing:

- recent incidents
- grouped counts by error code/source
- filters by date and payment method
- quick view of repeated failures

The dashboard should answer:

- is checkout failing?
- what type of failures are happening?
- is one payment method implicated?
- is this isolated noise or a pattern?

## Initial Error Sources

The MVP should normalize errors into broad buckets:

- `woocommerce`
- `gateway`
- `javascript`
- `php`
- `turnstile`
- `unknown`

This allows useful pattern detection without requiring deep knowledge of every plugin on day one.

## Safe Logging Rules

Sanitize aggressively.

Meaning:

- whitelist only useful fields
- never log raw `$_POST`
- never log card data
- never log tokens/nonces
- never log full addresses
- mask email/phone if stored at all

Examples of safe fields:

- `payment_method=ppcp-gateway`
- `turnstile_token_present=yes/no`
- `request_type=wc-ajax`
- `error_code=missing-input-response`
- `cart_total=1299.00`

## Storage

Use a custom database table.

Suggested table:

- `wp_checkout_monitor_events`

Suggested columns:

- `id`
- `created_at`
- `level`
- `source`
- `code`
- `message`
- `request_uri`
- `request_type`
- `payment_method`
- `order_id`
- `cart_total`
- `customer_hint`
- `session_hint`
- `context_json`

Notes:

- keep `context_json` small and sanitized
- add retention cleanup so the table does not grow forever

## Alerting

The MVP dashboard is enough for phase one.

Phase two can add alerts when:

- the same error repeats above threshold
- checkout failures spike in a time window
- one payment method starts clustering failures

Possible alert channels later:

- email
- Slack
- Discord
- webhook

## Why This Is Feasible

There is no single global WordPress hook that catches every checkout issue.

The real architecture surface is a combination of:

- WooCommerce checkout hooks
- browser-side checkout JS errors
- order/payment status outcomes

That is still very buildable as a narrow plugin if we avoid trying to solve everything at once.

## MVP Success Criteria

The plugin is useful if it can reliably tell us:

1. whether checkout failures are happening
2. whether those failures are recurring or isolated
3. which payment method or integration seems involved
4. whether the failures are frontend, validation, or gateway related

## Recommended Build Order

1. Custom DB table
2. Server-side WooCommerce incident logging
3. Checkout-only JS error capture
4. Admin dashboard
5. Basic grouping/filtering
6. Optional plugin-specific enrichers later

## Likely Plugin-Specific Enrichers Later

After the core MVP works, enrich for:

- Cloudflare Turnstile
- WooCommerce PayPal Payments
- Action Scheduler-related checkout dependencies

These should be adapters on top of the core monitor, not baked into the initial architecture.

## Future Product Direction

If this proves useful on `gunsafes.com`, the broader product could become:

- checkout observability for WooCommerce
- agency-focused monitoring
- trend detection after plugin/theme updates
- error clustering by payment method/integration
- cross-site monitoring across multiple Woo stores
