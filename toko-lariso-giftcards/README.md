# Toko Lariso Giftcards

Custom WooCommerce giftcard plugin for Toko Lariso.

The plugin treats giftcards as multi-purpose voucher store credit / partial payment. Products, shipping, VAT, and the WooCommerce cart total stay unchanged. A redeemed giftcard is shown separately as an amount paid by giftcard, and the selected payment method only charges the remaining amount.

Base language: English.

Text domain: `toko-lariso-giftcards`.

## Requirements

- WordPress 6.4+
- WooCommerce 8.5+; tested target up to WooCommerce 11.1
- PHP 8.0+
- WooCommerce Cart and Checkout Blocks for the native block redemption UI
- Optional: WPML + WPML String Translation for translated giftcard email template settings

## Installation

1. Upload `toko-lariso-giftcards-0.2.05.zip` in WordPress admin under Plugins > Add New > Upload Plugin.
2. Activate `Toko Lariso Giftcards`.
3. Go to WooCommerce > Toko Lariso Giftcards > Settings.
4. Configure fixed amounts, custom amount support, default validity, multiple giftcards, email text, PDF settings, refund behavior, and uninstall behavior.

## Creating a Giftcard Product

1. Create a WooCommerce product.
2. Set product type to `Toko Lariso giftcard`.
3. Add the giftcard image as the product image.
4. Add extra selectable designs as product gallery images.
5. Publish the product.

Do not enter the giftcard amount as the regular WooCommerce product price. The product can be left without a fixed catalog price. Customers choose the amount on the product page from the configured fixed presets, or from the custom amount field when that setting is enabled. The selected amount becomes the cart item price when the giftcard is added to the cart.

The single-product giftcard form renders a visual builder with a live card preview, fixed amount buttons, selectable product/gallery images, recipient fields, sender name, message character counter, and optional delivery date.

Giftcard products are intentionally treated as real, non-virtual WooCommerce products instead of virtual products. This keeps the giftcard as a normal order line for admin/order connectors, while the plugin prevents the giftcard from triggering paid shipping-rate lookups.

WooCommerce's default product gallery is hidden on giftcard product pages, because the giftcard builder is the primary image/design interface. Product gallery zoom is disabled and remaining zoom overlay elements are removed defensively, so selecting a design image does not open the magnifying-glass zoom overlay.

The plugin renders the giftcard builder only inside its own add-to-cart form. It does not hook the builder into WooCommerce's generic `woocommerce_before_add_to_cart_button` action, so themes that also render the default simple-product form cannot duplicate the giftcard interface. Quantity is forced to `1`, because every giftcard needs its own recipient, message, design, delivery date, and generated code.

On giftcard product pages, any theme/default WooCommerce `form.cart` that is not the Toko Lariso giftcard form is hidden as a defensive fallback. This prevents a second add-to-cart button from appearing above the builder.

Giftcard products also opt out of WooCommerce's AJAX add-to-cart feature. A catalog/category button therefore sends the customer to the giftcard product page instead of adding an incomplete giftcard line without recipient details.

The plugin keeps giftcard delivery free and Blocks-safe:

- Giftcard products remain non-virtual order line items.
- Giftcards are excluded from shipping-rate requests so WooCommerce Cart/Checkout Blocks do not wait for giftcard delivery rates.
- Giftcard-only carts do not require a shipping charge or shipping address.
- Giftcards must be ordered separately from physical/regular products, so third-party shipping plugins cannot grant free delivery by counting giftcard value toward a physical-product shipping threshold.
- A fallback `Giftcard delivery` method remains available as a safety net when a giftcard-only package is created by another integration.

## SendCloud Compatibility

The plugin includes a defensive compatibility layer for SendCloud's WooCommerce Blocks checkout handlers. SendCloud expects the WooCommerce session key `chosen_shipping_methods` to be a non-empty array during Store API checkout. Giftcard-only carts intentionally do not need shipping, so WooCommerce may leave that value unset. For giftcard-only orders without shipping line items, the plugin sets a non-SendCloud placeholder method id before SendCloud validates service-point data. This prevents SendCloud's service-point handler from throwing a PHP fatal while keeping the giftcard as a non-virtual order line item.

Customers can then select:

- Fixed amount
- Optional custom amount when enabled
- Recipient name
- Recipient email
- Optional sender name
- Personal message
- Optional delivery date
- Giftcard design from the product image/gallery

Each add-to-cart action creates one giftcard purchase line with its own recipient, message, delivery date, and design. To buy multiple giftcards, add the product multiple times with the right recipient details for each card.

The plugin blocks adding a giftcard to a cart that already contains regular products, blocks adding regular products to a cart that already contains a giftcard, and blocks checkout for any existing mixed cart.

## Giftcard PDF Downloads

After the purchase order is successfully paid and the giftcard has been issued, the order details page shows a `Giftcard PDFs` section with a download button for each purchased giftcard. The PDF contains the giftcard amount, full code, recipient, optional sender name, expiry date, message, webshop URL, QR code, and the selected design when it can be embedded.

The PDF header can include a configured company logo and header background color. Go to WooCommerce > Toko Lariso Giftcards > Settings > PDF settings to choose the logo, banner color, and giftcard redeem URL. The generated QR code and the printed/clickable webshop link store the redeem URL plus the giftcard code as `tokolariso_giftcard`, so scanning, clicking, or typing the link opens the shop and applies the code automatically.

The built-in PDF generator is self-contained and does not require a third-party PDF library. Local JPEG attachment images are embedded directly. Other local image types supported by the active WordPress image editor, such as PNG or WebP, are converted to a cached JPEG for PDF embedding. Remote or unreadable images fall back to a clean text/card layout, so PDF creation remains reliable.

PDF download URLs use a nonce and require either the matching order key, the owning logged-in customer, or a WooCommerce manager/admin. The full code is not exposed through Store API responses.

## Redemption

Cart and Checkout Blocks display a Giftcard field below the coupon area. The Store API callback applies or removes giftcards from the WooCommerce session and recalculates the cart.

Giftcard PDFs and QR codes can link shoppers to a configured redeem URL with the full giftcard code embedded in the URL. When a visitor opens that URL, the plugin applies the code to the WooCommerce session and redirects to the same page without the code in the address bar. If the shopper has an empty cart, the code is stored in WooCommerce session plus a short-lived HttpOnly cookie and applied automatically after products are added to the cart.

The giftcard field has an optional `Amount to use` input. Leave it empty to use as much of the giftcard as possible, or enter a lower amount to make a deliberate partial redemption. For example, with a EUR 50 giftcard and a EUR 60 order, entering EUR 25 leaves EUR 35 to pay with iDEAL/card and keeps EUR 25 on the giftcard.

Giftcards cannot be used to buy giftcards. When the cart contains a giftcard product, including a mixed cart with regular products, the giftcard payment field is disabled and any already applied giftcard payment is removed from the session.

At checkout, the plugin prepares the giftcard as a pending partial payment and changes the payable WooCommerce order total to the remaining amount. That remaining amount is what payment gateways such as Mollie should receive. The giftcard balance is not deducted at this point.

For Mollie gateways, the plugin also filters the final Mollie API request arguments and forces `amount.value` to the stored remaining payment amount. This amount is the same amount shown on the Checkout Blocks place-order button.

The actual giftcard ledger redemption runs only after successful payment, or immediately for a fully giftcard-covered zero-payment order. If a customer starts an external payment and cancels or fails it, the order keeps the pending giftcard payment metadata but the giftcard balance remains untouched.

Public Store API responses expose only masked codes, not full codes.

Giftcard redemption is treated as a partial payment, not as a cart discount. The plugin stores the selected giftcard allocation in the customer session and keeps WooCommerce cart, shipping, and VAT totals intact. During order creation the selected payment method receives only the remaining amount to charge.

For example, goods of `EUR 4.00` plus shipping of `EUR 6.95` with `EUR 10.00` paid by giftcard produce a remaining payable amount of `EUR 0.95`. The checkout giftcard section still shows the giftcard payment details, while the standard WooCommerce place-order button and payment method receive the remaining WooCommerce total.

The Checkout Block place-order button is visually overlaid with the remaining payment-method amount from Store API extension data. The overlay keeps the existing/template button text and appends the remaining amount. If the base text is not yet available, it falls back to `Bestel en betaal`. The overlay does not edit React-managed button text nodes, which avoids the earlier checkout block `insertBefore` error.

## Debug Logging

Go to WooCommerce > Toko Lariso Giftcards > Settings and enable `Debug logging` while testing checkout issues. Logs are written to WooCommerce > Status > Logs with source `toko-lariso-giftcards`.

Useful events:

- `store_api_cart_update_apply_success`
- `store_api_cart_data`
- `cart_order_allocations_ignored_stale_applied_cards`
- `stale_checkout_payment_meta_cleared`
- `prepare_order_cleared_stale_payment_meta`
- `prepare_order_start`
- `prepare_order_saved`
- `prepare_order_reapplied_prepared_total`
- `order_total_before_giftcards_mismatch`
- `order_total_filter_applied`
- `mollie_args_amount_forced`
- `mollie_args_amount_skipped`
- `redeem_prepared_start`
- `redeem_prepared_saved`
- `redeem_prepared_failed`

Full giftcard codes are redacted from logs. Disable debug logging after testing.

## Customer Balance Checks

Customers can check a giftcard balance in My Account > Giftcards. The form requires the full giftcard code and displays only the masked code, current balance, status, expiry date, and a spending/activity history.

The same checker can be placed on a page with:

```text
[tokolariso_giftcard_balance]
```

The checker uses a nonce, sanitizes input, and does not expose full codes in output. The activity history shows customer-safe rows only: date, type, order reference when available, amount, and balance after.

## Admin Management

Open WooCommerce > Toko Lariso Giftcards to:

- Search by exact code, masked code, recipient, or purchase order id
- See the installed plugin version at the top of the admin screen
- View initial balance, current balance, status, expiry, purchase order, redemption orders, and ledger
- View the full giftcard code on the admin-only giftcard detail screen for testing or manual recipient support
- Configure the PDF header logo and redeem URL used for generated PDFs and QR codes
- Change status and expiry
- Apply a manual balance correction with a required reason
- Resend the giftcard email

## WPML Support

The plugin source strings use English as the base language and the `toko-lariso-giftcards` text domain.

WPML support includes:

- `wpml-config.xml` registration for option-backed email template fields.
- Runtime registration through `wpml_register_single_string`.
- Runtime translation through `wpml_translate_single_string`.
- Translatable email subject, heading, intro, button label, and shop URL.

After changing email template settings, open WPML > String Translation and translate strings in the `toko-lariso-giftcards` context.

## Refunds

Refund behavior is configurable:

- Manual review only: no balance is restored automatically; the order receives an admin note.
- Restore on full order refund: redeemed giftcard credit is restored once when the order reaches refunded status.

Partial refunds are intentionally not automatically restored because the correct behavior depends on whether the refunded amount relates to cash paid, giftcard credit, product value, shipping, or a mixed allocation.

## Data Retention

Deactivation never deletes giftcard data. Uninstall deletes tables and settings only when the explicit `Delete giftcard tables and settings when the plugin is uninstalled` setting is enabled.

## Local Syntax Testing

Run a single-file lint:

```sh
php -l toko-lariso-giftcards.php
```

Run all plugin PHP lint checks:

```sh
php tests/lint-all.php
```

Replay giftcard remainder calculations:

```sh
php tests/calculate-remainder.php --subtotal=10.95 --voucher=10 --expected=0.95
php tests/calculate-remainder.php --subtotal=10,95 --voucher=10,00 --expected=0,95
```

The script treats `subtotal` as the cart/order amount including VAT and shipping before giftcard payment, subtracts the voucher amount in cents, and prints the exact remaining amount that should be shown on the checkout button and sent to Mollie as `amount.value`.

PowerShell alternative:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

## Troubleshooting Cart Blocks

If the WooCommerce Cart Block stays on grey loading placeholders even with only a normal product in the cart, inspect the browser console and Network tab first. This usually means a global frontend script or Store API request is failing before the cart block can finish rendering. Known indicators are uncaught JavaScript errors from theme scripts, consent/cookie plugins, cache/minify tools, or shipping/VAT extensions. Test once with optimization/cache disabled and with unrelated frontend scripts temporarily disabled.

If the browser console shows `Observer for shipping is already active.` after applying a giftcard, check whether another plugin is binding click handlers to broad WooCommerce Blocks selectors. `wc-postcode-checker` version 3.7.2 binds to every `.wc-block-components-button` click and calls `preventDefault()`, which can intercept unrelated extension buttons. This plugin avoids that conflict by using its own giftcard Apply button class and stopping the Apply click from bubbling to document-level checkout handlers.

## References

- WooCommerce Store API extensibility: https://github.com/woocommerce/woocommerce/blob/trunk/docs/apis/store-api/extending-store-api/README.md
- Updating the cart through `extensionCartUpdate`: https://github.com/woocommerce/woocommerce/blob/trunk/docs/apis/store-api/extending-store-api/extend-store-api-update-cart.md
- Cart and Checkout Blocks integration interface: https://github.com/woocommerce/woocommerce/blob/trunk/docs/block-development/reference/integration-interface.md
