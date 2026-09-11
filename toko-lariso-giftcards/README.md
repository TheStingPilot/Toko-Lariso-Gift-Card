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

1. Upload `toko-lariso-giftcards-0.1.23.zip` in WordPress admin under Plugins > Add New > Upload Plugin.
2. Activate `Toko Lariso Giftcards`.
3. Go to WooCommerce > Toko Lariso Giftcards > Settings.
4. Configure fixed amounts, custom amount support, default validity, multiple giftcards, email text, refund behavior, and uninstall behavior.

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

Giftcard products also opt out of WooCommerce's AJAX add-to-cart feature. A catalog/category button therefore sends the customer to the giftcard product page instead of adding an incomplete giftcard line without recipient details.

The plugin keeps giftcard delivery free and Blocks-safe:

- Giftcard products remain non-virtual order line items.
- Giftcards are excluded from shipping-rate requests so WooCommerce Cart/Checkout Blocks do not wait for giftcard delivery rates.
- Giftcard-only carts do not require a shipping charge or shipping address.
- Mixed carts keep normal shipping for the physical products, while the giftcard line stays in the order for admin/order integrations.
- Mixed carts exclude giftcard value from free-shipping thresholds. A EUR 50 giftcard plus a EUR 3.95 physical product still counts as EUR 3.95 toward free shipping, not EUR 53.95.
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

## Giftcard PDF Downloads

After the purchase order is successfully paid and the giftcard has been issued, the order details page shows a `Giftcard PDFs` section with a download button for each purchased giftcard. The PDF contains the giftcard amount, full code, recipient, optional sender name, expiry date, message, and the selected design when it can be embedded.

The built-in PDF generator is self-contained and does not require a third-party PDF library. Local JPEG attachment images are embedded directly. Other image types fall back to a clean text/card layout, so PDF creation remains reliable.

PDF download URLs use a nonce and require either the matching order key, the owning logged-in customer, or a WooCommerce manager/admin. The full code is not exposed through Store API responses.

## Redemption

Cart and Checkout Blocks display a Giftcard field below the coupon area. The Store API callback applies or removes giftcards from the WooCommerce session and recalculates the cart.

The giftcard field has an optional `Amount to use` input. Leave it empty to use as much of the giftcard as possible, or enter a lower amount to make a deliberate partial redemption. For example, with a EUR 50 giftcard and a EUR 60 order, entering EUR 25 leaves EUR 35 to pay with iDEAL/card and keeps EUR 25 on the giftcard.

At checkout, the plugin prepares the giftcard as a pending partial payment and changes the payable WooCommerce order total to the remaining amount. That remaining amount is what payment gateways such as Mollie should receive. The giftcard balance is not deducted at this point.

The actual giftcard ledger redemption runs only after successful payment, or immediately for a fully giftcard-covered zero-payment order. If a customer starts an external payment and cancels or fails it, the order keeps the pending giftcard payment metadata but the giftcard balance remains untouched.

Public Store API responses expose only masked codes, not full codes.

Giftcard redemption is deliberately treated as a partial payment, not as a discount line. The plugin does not use a WooCommerce coupon, does not add a negative cart fee, and does not change the WooCommerce cart total used by VAT displays. For example, goods of `EUR 44.45` including VAT plus shipping of `EUR 6.95` including VAT remain a VAT/cart total of `EUR 51.40`; the checkout giftcard section then shows `EUR 50.00` paid by giftcard and `EUR 1.40` to pay with the selected payment method.

The Checkout Block place-order button is updated to show the remaining payment-method amount, including `EUR 0.00` when the giftcard covers the full order.

The button-label fallback only rewrites the Checkout Block's label text node. It avoids replacing the whole button contents, which prevents CSS text from being copied into the visible button label on themes/plugins that inject generated checkout button content.

## Debug Logging

Go to WooCommerce > Toko Lariso Giftcards > Settings and enable `Debug logging` while testing checkout issues. Logs are written to WooCommerce > Status > Logs with source `toko-lariso-giftcards`.

Useful events:

- `store_api_cart_update_apply_success`
- `store_api_cart_data`
- `prepare_order_start`
- `prepare_order_saved`
- `prepare_order_reapplied_prepared_total`
- `order_total_filter_applied`
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
