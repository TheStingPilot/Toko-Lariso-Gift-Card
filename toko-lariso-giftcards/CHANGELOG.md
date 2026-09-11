# Changelog

## 0.1.28

- Added PDF settings for a company logo in the giftcard PDF header.
- Added a configurable giftcard redeem URL for generated PDFs.
- Added locally generated vector QR codes to giftcard PDFs. The QR code embeds the redeem URL plus the full giftcard code.
- Added automatic giftcard application from `tokolariso_giftcard` URLs, followed by a redirect that removes the full code from the address bar.
- Updated documentation and manual PDF test scenarios.

## 0.1.27

- Added this changelog.
- Added a product-page CSS safeguard so only the Toko Lariso giftcard builder add-to-cart form is visible on giftcard pages. Theme/default `form.cart` output is hidden to prevent a second add-to-cart button.

## 0.1.26

- Added PDF image conversion for local non-JPEG media. PNG/WebP and other formats supported by the active WordPress image editor are converted to cached JPEG files under `uploads/tokolariso-giftcards/pdf-cache/` before embedding in the giftcard PDF.
- Kept the clean fallback PDF card for remote, unreadable, or unsupported images.

## 0.1.25

- Blocked mixed carts containing both giftcards and regular products.
- Blocked adding a giftcard to a cart with regular products and blocked adding regular products to a cart with a giftcard.
- Added cart/checkout validation for existing mixed carts created before the rule was active.
- Updated documentation and manual tests for separate giftcard orders.

## 0.1.24

- Blocked giftcard payment when the cart contains a giftcard product.
- Cleared applied giftcard payment sessions when giftcard products are present.
- Exposed Store API flags so Cart/Checkout Blocks can disable the giftcard payment field with an explanation.
- Added draft-order cleanup so reused Checkout Blocks orders do not keep stale giftcard payment metadata.

## 0.1.23

- Disabled WooCommerce AJAX add-to-cart support for giftcard products so catalog buttons open the giftcard builder instead of adding incomplete giftcard lines.
- Documented why the product is not globally marked as sold individually, preserving separately configured giftcard lines.

## 0.1.22

- Continued checkout/payment hardening around partial-payment totals and Blocks button label behavior.
- Documented the then-current PDF image limitation where visual embedding required local JPEG images.

## 0.1.21

- Added secured giftcard PDF download links on paid order detail pages.
- Added full-code PDF output with recipient, sender, expiry date, message, amount, and selected design when embeddable.
- Added My Account balance checker activity history.

## 0.1.20

- Improved the giftcard product-page builder layout.
- Added safeguards against WooCommerce gallery zoom overlays on giftcard designs.

## 0.1.19

- Added a richer product-page giftcard builder with live preview, selectable image designs, amount buttons, recipient fields, sender name, message counter, and delivery date.
- Added initial PDF download support for issued giftcards.

## 0.1.18

- Added Checkout Blocks place-order button label handling for remaining payment-method amount.
- Improved zero-payment checkout handling when giftcard credit covers the full order.

## 0.1.16

- Added debug logging settings and checkout diagnostics for Store API, Mollie amount mismatches, and giftcard payment preparation.

## 0.1.10

- Added partial giftcard redemption input for Cart/Checkout Blocks.
- Added clearer giftcard payment summary rows in Blocks checkout.

## 0.1.0

- Initial Toko Lariso Giftcards plugin with custom giftcard product handling, code issuance, balance storage, redemption ledger, Cart/Checkout Blocks integration, admin screens, email delivery, and WooCommerce partial-payment behavior.
