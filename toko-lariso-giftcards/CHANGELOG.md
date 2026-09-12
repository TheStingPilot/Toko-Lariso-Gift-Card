# Changelog

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
- Checkout button overlay avoids direct React text-node mutation and uses the current/template button text, with `Bestellen en betalen` as fallback.
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
