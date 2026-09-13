# Changelog

## 0.2.05

- Improved customer-facing giftcard validation messages for expired cards and fully used cards.
- Expired giftcards now explicitly say that the giftcard has been expired and can no longer be used.
- Fully used giftcards now explain that the full balance has already been used.

## 0.2.04

- Added the activity time below each spending history date, formatted with WooCommerce's configured date and time formats.

## 0.2.03

- Updated the customer giftcard spending history table to follow WooCommerce theme table markup more closely.
- Added responsive table `data-title` attributes and WooCommerce table classes for better theme/mobile behavior.
- Kept activity amounts on one line and right-aligned amount columns with minimal CSS, leaving colors, borders, spacing, and typography to the active theme.

## 0.2.02

- Fixed Store API checkout retries that reuse a pending order which already has giftcard redeemed metadata from an earlier failed or interrupted checkout attempt.
- Active checkout giftcard allocations now override the old redeemed-order guard during order preparation, so the current remaining amount can still be stored and forced into Mollie.
- Added debug marker `prepare_order_reused_redeemed_checkout_order` for this recovery path.

## 0.2.01

- Fixed a zero-payment checkout race where a fully giftcard-covered order could be restored to the original payable amount before Mollie created its payment request.
- Kept active giftcard session data available throughout checkout finalization so stale checkout cleanup no longer removes valid giftcard payment metadata too early.
- Rebuild checkout order allocations from applied giftcards when WooCommerce still has the applied card but the allocation snapshot is temporarily missing.
- Improved the Checkout Blocks button overlay so it prefers the configured/template button label and ignores WooCommerce's generic `Plaats bestelling`/`Place order` label as a base for giftcard remainder text.

## 0.2.00

Baseline release. This version consolidates all functionality built and tested up to and including version 0.1.45.

Built so far:

- Custom WooCommerce product type for Toko Lariso giftcards.
- Giftcard product page with a visual builder, amount presets, optional custom amount, image/design selection, recipient details, sender name, message, delivery date, and live preview.
- Quantity forced to one giftcard per add-to-cart action so every giftcard has its own recipient, design, message, and generated code.
- Duplicate product-page add-to-cart forms, default gallery zoom, and incomplete AJAX add-to-cart behavior are suppressed for giftcard products.
- Giftcards are sold as normal non-virtual order lines but excluded from paid shipping-rate lookups where appropriate.
- Giftcard-only carts can complete without paid shipping, while mixed giftcard/physical-product carts are blocked.
- Giftcards cannot be used to buy other giftcards.
- WooCommerce Cart and Checkout Blocks support for applying, removing, and displaying giftcard partial payments.
- Optional maximum giftcard amount field for deliberate partial redemption.
- Giftcard redemption is treated as a separate partial payment, not as a coupon, cart discount, taxable fee, or VAT reduction.
- Product totals, shipping totals, and VAT tables remain based on the original WooCommerce cart/order amounts.
- Checkout button and giftcard summary show the remaining payment-method amount after giftcard allocation.
- Checkout button overlay avoids direct React text-node mutation and uses the current/template button text, with `Bestel en betaal` as fallback.
- Giftcard balance is redeemed only after successful payment, or immediately for a fully giftcard-covered zero-payment order.
- Cancelled or failed external payments leave giftcard balances untouched.
- Mollie Payments for WooCommerce compatibility: final Mollie request amount is forced to the stored remaining payment-method amount.
- Checkout Blocks draft-order reuse protection: stale giftcard payment metadata is cleared when the current checkout no longer has active giftcard allocations.
- Active giftcard allocations remain available long enough for Mollie/payment gateway request creation.
- SendCloud Blocks compatibility for giftcard-only orders and Store API checkout validation.
- Shipping/free-shipping safeguards so giftcard value does not count toward physical-product free-shipping thresholds.
- Admin settings for fixed amounts, custom amount support, validity, multiple giftcards, email content, PDF options, refund behavior, uninstall behavior, and debug logging.
- Admin giftcard management screen with search, detail view, balance/status/expiry management, manual balance corrections, ledger display, and email resend.
- Giftcard repository with secure full-code handling, hashed lookup, optional encrypted storage, balance mutations, and ledger history.
- Recipient email flow with WPML-aware option-backed email template strings.
- My Account giftcard balance checker and `[tokolariso_giftcard_balance]` shortcode.
- Secured giftcard PDF downloads from order details after giftcard issue.
- Giftcard PDF layout with configured logo, banner color, selected design, amount, recipient, sender, expiry date, message, full code, webshop URL, and QR code.
- PDF image handling converts local PNG/WebP images to a cached JPEG for reliable PDF embedding.
- QR/redeem links can store a giftcard code for later use when the shopper opens the link with an empty cart.
- Pending QR/redeem codes are stored in WooCommerce session plus a short-lived HttpOnly cookie and automatically applied once products are added.
- Debug logging for Store API updates, cart allocations, order preparation, stale checkout cleanup, Mollie amount forcing, redemption, and failure paths.
- Local PHP lint runner and a manual remainder calculator for replaying scenarios such as `10.95 - 9.00 = 1.95`.

Development continues from version 0.2.00.
