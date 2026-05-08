# Order Adjustment Workflow

## Problem

The store needs a clean way to modify already-paid WooCommerce orders after the customer has checked out.

Common cases:

- customer adds an add-on after payment
- customer removes an add-on after payment
- customer swaps one paid option for another
- the net change may be positive, negative, or zero

WooCommerce does not model this well as a direct edit to the original paid order. Paid orders are tied to gateway transactions, refunds, accounting records, stock behavior, order emails, and fulfillment workflows. Mutating the original order total after payment risks making those records inconsistent.

## Goal

Build an admin workflow that lets staff create a clear order adjustment without forcing a full refund and reorder.

The workflow should:

- keep the original paid order intact
- calculate the net financial difference
- collect only additional money owed
- refund only money owed back
- show staff the final effective order contents
- avoid duplicate fulfillment and dropship emails
- preserve clean payment/refund records

## Core Design Principle

Do not turn a paid WooCommerce order into a mutable multi-payment order.

Instead, keep the original order as the operational parent order and create explicit adjustment records around it.

## Order Model

### Parent Order

The original paid order remains the source of truth for:

- customer details
- shipping address
- original line items and add-ons
- original payment transaction
- gateway fees
- existing fulfillment/dropship workflow
- order notes and staff history

The parent order should get an Adjustments panel that shows:

- adjustment history
- linked child payment orders
- refunds created from adjustments
- effective current order summary
- balance status

### Positive Adjustment

If the net adjustment is positive, create a linked child order for the net amount due.

Example:

- add lighting kit: `+$250`
- remove delivery upgrade: `-$150`
- net due: `+$100`

Create one child adjustment order for `$100`.

The child order is a payment artifact, not the fulfillment source. It should be clearly linked to the parent order and should not trigger normal dropship or fulfillment behavior.

### Negative Adjustment

If the net adjustment is negative, create a native WooCommerce partial refund on the parent order.

Example:

- remove delivery upgrade: `-$150`
- add nothing
- net refund: `$150`

Create a `$150` refund on the parent order with an adjustment reason.

WooCommerce supports exact refund amounts through `wc_create_refund()`. The refund does not need to be tied to a refunded item quantity, although line-item allocation can be added later if needed.

### Zero Adjustment

If the net adjustment is zero, record the adjustment only.

No child order and no refund are needed.

## Admin Workflow

1. Staff opens the paid parent order.
2. Staff clicks `Create adjustment`.
3. An adjustment builder opens with parent order context preloaded.
4. Staff records changes:
   - add product or add-on
   - remove product or add-on
   - change service/shipping option
   - add internal/customer note
5. System calculates:
   - added value
   - removed value
   - tax/shipping effect if supported
   - net due/refund/zero
6. Staff reviews the adjustment.
7. System performs the correct financial action:
   - net positive: create linked child payment order
   - net negative: create partial refund on parent
   - net zero: record adjustment only
8. Parent order adjustment panel updates with the adjustment history and effective order summary.

## Effective Order Summary

The parent order should show the original WooCommerce order details unchanged, followed by an effective order summary.

Example:

```text
Original Order #1001
Safe: Browning Hunter HTR49
Original add-ons:
- Dial lock
- Delivery upgrade
Original total: $4,000
Paid via PayPal: $4,000

Adjustments
Adjustment #1002: + Interior lighting kit, - Delivery upgrade, net +$100, paid

Effective Order Summary
Safe: Browning Hunter HTR49
Current add-ons:
- Dial lock
- Interior lighting kit
Shipping/service:
- Standard delivery
Effective total: $4,100
Original paid: $4,000
Additional paid: $100
Refunded: $0
Balance due: $0
```

## Fulfillment Behavior

The parent order remains the operational order.

The child adjustment order should not become the fulfillment order. It should not independently send vendor, dropship, warehouse, or fulfillment emails.

When a positive adjustment order is paid, the system should update the parent order's adjustment record and effective summary.

Fulfillment staff should review the parent order and its adjustment panel to understand the final customer commitment.

## Payment Behavior

### Additional Money Owed

Create a child order for the net amount only and use WooCommerce's normal payment flow.

This allows the store to send a normal payment link and lets the active payment gateways handle the transaction cleanly.

### Money Owed Back

Create a partial refund on the parent order.

Use WooCommerce's native refund APIs so the refund stays tied to the original gateway transaction.

### Mixed Changes

Do not separately refund removed items and charge added items if the net result is positive.

Calculate the net:

```text
added value - removed value = net adjustment
```

Then perform one financial action based on the net.

## Non-Goals

The initial build should not:

- make paid orders freely editable
- mutate original paid order totals after payment
- create negative child orders
- treat child adjustment orders as fulfillment orders
- duplicate dropship/vendor emails
- attempt full accounting/reporting replacement
- support every possible tax edge case on day one

## Open Design Questions

- Should adjustment child orders use a virtual/internal adjustment product or custom fee line?
- Should child order emails be customer-facing payment requests only, with fulfillment emails suppressed?
- Should negative adjustments allocate refund amounts to original line items, or start with exact amount refunds only?
- How should tax be calculated for positive and negative adjustments?
- How should shipping/service changes appear in dropship notifications?
- Should adjustment records be custom post types, order meta arrays, or a custom database table?
- What permissions should staff need to create adjustments and refunds?

## Recommended Build Phases

### Phase 1: Adjustment Ledger and Manual Workflow

- Add parent order Adjustments panel.
- Add adjustment records as structured order meta.
- Create linked child order for net positive adjustments.
- Create exact partial refund for net negative adjustments.
- Record net zero adjustments.
- Suppress fulfillment behavior for child adjustment orders.

### Phase 2: Better Adjustment Builder

- Product/add-on picker.
- Removed item/add-on selector.
- Automatic net calculation.
- Admin review screen.
- Customer-facing payment request copy.

### Phase 3: Fulfillment Integration

- Effective order summary in parent order.
- Adjustment-aware dropship emails.
- Adjustment-aware admin/export views.
- Clear paid/refunded/balance status.

## Success Criteria

The feature succeeds if staff can:

1. start from the paid parent order
2. record what changed
3. charge only the net additional amount
4. refund only the net owed-back amount
5. keep the original payment/refund records clean
6. see the final effective order in one place
7. avoid duplicate fulfillment workflows
