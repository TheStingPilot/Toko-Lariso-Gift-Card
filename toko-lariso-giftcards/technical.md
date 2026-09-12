# Technical Notes

## Architecture

The plugin is a conventional WordPress/WooCommerce plugin with small service classes:

- `Toko_Lariso_Giftcards_Settings`: option defaults, sanitization, and settings access.
- `Toko_Lariso_Giftcards_Debug`: opt-in WooCommerce logger diagnostics with full-code redaction.
- `Toko_Lariso_Giftcards_WPML`: WPML string registration and translation for option-backed email template settings.
- `Toko_Lariso_Giftcards_Repository`: database access, code hashing/encryption, balance mutation, and ledger logging.
- `Toko_Lariso_Giftcards_Product_Type`: custom WooCommerce product type `tokolarisogiftcard`, dynamic amount price label, product-page add-to-cart form with purchase fields, and giftcard-only gallery zoom suppression.
- `Toko_Lariso_Giftcards_Shipping`: giftcard shipping exclusion for rate calculations and zero-cost giftcard-only fallback package rates.
- `Toko_Lariso_Giftcards_Sendcloud_Compatibility`: defensive session normalization before SendCloud's Store API checkout validation runs.
- `Toko_Lariso_Giftcards_Cart`: cart item metadata, dynamic giftcard sale pricing, partial-payment allocation display, and session allocations without mutating WooCommerce cart totals.
- `Toko_Lariso_Giftcards_Store_API`: WooCommerce Store API extension data and `cart/extensions` update callback.
- `Toko_Lariso_Giftcards_Blocks_Integration`: Cart/Checkout Blocks asset registration via WooCommerce Blocks `IntegrationInterface`, with current `wc-blocks-data-store` dependency detection and fallback for older Blocks builds.
- `Toko_Lariso_Giftcards_Order`: checkout payment preparation, delayed giftcard redemption after successful payment, giftcard issuance after successful payment, and refund handling.
- `Toko_Lariso_Giftcards_PDF`: secured order-detail PDF download links and a self-contained one-page PDF generator for issued giftcards, including local image conversion and QR output.
- `Toko_Lariso_Giftcards_My_Account`: My Account balance checker endpoint and `[tokolariso_giftcard_balance]` shortcode.
- `Toko_Lariso_Giftcards_Admin`: WooCommerce submenu for giftcard management.
- `Toko_Lariso_Giftcards_Email`: WooCommerce mailer integration for recipient emails.

## Language and WPML

The base language is English. The plugin text domain is `toko-lariso-giftcards`.

Static UI strings are wrapped in WordPress i18n functions using that text domain. Option-backed email template fields are not static source strings, so the plugin supports WPML in two ways:

- `wpml-config.xml` declares the `tokolariso_giftcards_settings` option keys for WPML String Translation.
- `Toko_Lariso_Giftcards_WPML` registers and translates these fields at runtime:
  - `email_subject`
  - `email_heading`
  - `email_intro`
  - `email_button_label`
  - `email_shop_url`

The WPML string context is `toko-lariso-giftcards`.

## VAT and Fiscal Treatment

This implementation treats giftcards as multi-purpose vouchers / store credit used as a partial payment. On sale, the `tokolarisogiftcard` product is forced to non-taxable but not virtual. It remains a normal order line item, while the shipping compatibility layer prevents giftcard-only carts from triggering paid shipping-rate lookups. On redemption, the giftcard does not reduce products, shipping, VAT, or the WooCommerce cart total. It is displayed as a separate giftcard payment after WooCommerce has calculated product totals, shipping totals, and VAT.

Example:

- Products: EUR 34.95 including VAT
- Shipping: EUR 6.95 including VAT
- WooCommerce total including VAT and shipping: EUR 41.90
- Paid by giftcard: EUR 15.00
- Remaining amount charged by payment method: EUR 26.90

The cart and VAT calculation are deliberately left untouched. The plugin does not implement redemption as a WooCommerce coupon, does not add a negative cart fee, and does not use `woocommerce_calculated_total` to alter the Store API cart total. WooCommerce and any VAT-display plugin therefore continue to see the original tax-inclusive cart total. A cart of EUR 4.00 goods plus EUR 6.95 shipping remains EUR 10.95 in the VAT/cart summary; the giftcard section separately shows EUR 10.00 paid by giftcard and EUR 0.95 still due.

During order creation, the plugin stores the original order total, giftcard payment amount, and remaining payment-method amount as order meta. The order total is then set to the remaining amount so the selected payment gateway charges the correct remainder. Giftcard balance is redeemed only after successful payment.

Mollie Payments for WooCommerce builds both Payments API and Orders API requests from `$order->get_total()`, then exposes per-gateway request filters such as `woocommerce_mollie_wc_gateway_ideal_args` and `woocommerce_mollie_wc_gateway_idealpayment_args`. The plugin registers these filters for the known Mollie gateway ids and forces `amount.value` to `_tokolariso_payment_due_after_giftcards`. This is the same numeric amount exposed to Checkout Blocks as `remaining_total` and shown on the place-order button.

If Mollie reaches the filter before the order has been prepared, the filter runs `prepare_order_for_payment()` as a last-moment fallback. Successful enforcement logs `mollie_args_amount_forced`; if no giftcard payment metadata can be found, it logs `mollie_args_amount_skipped`.

The shopper may optionally set a maximum giftcard amount to use when applying the code in Cart/Checkout Blocks. A zero or empty limit means "use as much as possible up to the giftcard balance and cart total"; a positive limit caps that giftcard allocation before the remaining payment-method amount is calculated. This is still a payment allocation only and does not alter WooCommerce line totals, shipping totals, VAT bases, or VAT display tables.

Giftcard payment is blocked whenever the cart contains a giftcard product. The rule applies to giftcard-only carts and mixed carts with both giftcards and normal products. Existing session applications are cleared during cart allocation refresh, and the Store API apply callback throws before applying the submitted code. This prevents customers from extending giftcard validity by using one giftcard to buy another giftcard.

At order creation, the plugin prepares a pending giftcard partial payment and stores metadata for the original tax-inclusive total, giftcard payment total, and remaining payment-method amount. No negative order fee item is created. The order total is set to the remaining amount so the selected payment method charges only that amount, while product and shipping VAT bases remain unchanged.

The giftcard balance is not deducted during external payment redirection. A Mollie/iDEAL cancellation or failed payment therefore leaves the giftcard balance untouched. The ledger redemption runs after `woocommerce_payment_complete`, `processing`, or `completed`, or immediately for a zero-payment order fully covered by giftcard credit.

Checkout Blocks can reuse and rebuild draft/pending orders. When that happens, WooCommerce may temporarily restore the order total from the cart, or a reused draft order may still contain stale giftcard payment metadata from a previous checkout attempt. The plugin therefore prepares the partial payment at `woocommerce_store_api_checkout_update_order_meta` and re-applies the stored remaining amount again at `woocommerce_store_api_checkout_order_processed`. When active giftcard allocations exist, the current Cart Blocks total is treated as the authoritative original tax-inclusive total before giftcard payment. If no active allocations exist, stale giftcard payment metadata is cleared and the draft order total is restored before gateways read it. A final `woocommerce_order_get_total` guard returns the remaining payment-method amount when valid giftcard payment meta is present, so gateways read the payable amount instead of the original tax-inclusive order value.

Fiscal assumptions must be reviewed by the merchant's accountant/bookkeeper. This code is a technical implementation of the requested treatment, not tax advice.

## Shipping Treatment

Giftcard products are non-virtual order line items. This is deliberate for compatibility with order integrations that ignore virtual-only orders. They are still excluded from shipping-rate requests so giftcard-only carts do not require a paid shipping method.

The shipping behavior is:

- Giftcard products remain non-virtual and appear as normal order line items.
- `woocommerce_product_needs_shipping` returns `false` for giftcard products so they do not participate in shipping-rate requests.
- `woocommerce_cart_needs_shipping` and `woocommerce_cart_needs_shipping_address` remain `true` only when the cart contains a non-giftcard shippable item.
- `woocommerce_add_to_cart_validation`, `woocommerce_check_cart_items`, and `woocommerce_checkout_process` prevent giftcards and regular products from being ordered together.
- Legacy mixed-cart guards remain in `woocommerce_cart_shipping_packages`, `woocommerce_shipping_free_shipping_is_available`, and `woocommerce_package_rates` as a defensive fallback, but normal checkout should never proceed with a mixed giftcard/regular-product cart.
- `woocommerce_package_rates` still sets any giftcard-only fallback package rates to zero, including third-party rates such as SendCloud rates.
- If another integration creates a giftcard-only package with no rates, the plugin injects a fallback `Giftcard delivery` shipping rate at zero cost.

This means a cart containing only a giftcard does not need a shipping-rate lookup or a paid shipping method, while a cart with physical products still pays the normal shipping charge for those physical products. A EUR 50 giftcard and a EUR 3.95 physical product must be split into separate orders instead of relying on third-party shipping-rate corrections.

## SendCloud Blocks Compatibility

SendCloud's WooCommerce Blocks service-point checkout handler reads `WC()->session->get( 'chosen_shipping_methods', '' )` and immediately calls `reset()` on the result. In a giftcard-only checkout, WooCommerce may have no shipping method selection because the cart intentionally does not need shipping. In that state the session fallback can be a string, which causes a PHP fatal in SendCloud:

```text
reset(): Argument #1 ($array) must be of type array, string given
```

The compatibility layer hooks `woocommerce_store_api_checkout_order_processed` at priority `1`, before SendCloud's default priority `10` handler. For orders that contain only Toko Lariso giftcards and no shipping line items, it normalizes the session value to a non-empty array containing the non-SendCloud method id `tokolariso_giftcard_delivery`. SendCloud then sees that the selected method is not `sc_service_point` and returns without service-point validation.

The workaround is intentionally limited to giftcard-only orders without shipping items. It does not alter normal physical-product shipping, selected SendCloud rates, or service-point orders.

## Giftcard Product Pricing and Add to Cart

The giftcard product does not use the regular WooCommerce product price as the voucher amount. The product can be saved without a fixed regular price. The product page renders a custom `tokolarisogiftcard` add-to-cart form where shoppers choose one configured fixed amount, or a custom amount when enabled.

The selected amount is stored in cart item data and assigned as the cart line price during `woocommerce_before_calculate_totals`. Each add-to-cart action is limited to quantity `1` so each giftcard purchase has exactly one recipient, message, delivery date, design, and generated code. Buying multiple giftcards is supported by adding the product multiple times.

The product class reports that `ajax_add_to_cart` is not supported. This keeps catalog/category buttons from bypassing the single-product giftcard builder. It intentionally does not mark the product as globally sold individually, because that could prevent shoppers from adding several separately configured giftcards as separate cart lines.

Giftcards may not be mixed with regular products in one cart. The add-to-cart validation blocks both directions, and `woocommerce_check_cart_items` / `woocommerce_checkout_process` block checkout for existing mixed carts. This is a defensive compatibility rule for shipping plugins that calculate free shipping from the whole taxable goods total and do not expose enough rate data to reliably remove only the wrongly-free rate.

On giftcard product pages, WooCommerce's default gallery is hidden and replaced by the custom giftcard builder. Gallery zoom support is disabled via `woocommerce_single_product_zoom_enabled`, giftcard-only body classes, and removal of `wc-product-gallery-zoom` theme support for the current request. Product/gallery images remain selectable as giftcard designs inside the builder, and remaining zoom overlay DOM is removed defensively by `assets/js/product.js`.

The product page uses a custom visual builder in `assets/css/product.css` and `assets/js/product.js`. The builder keeps WooCommerce's existing add-to-cart form and field names but becomes the primary interface by hiding the standard gallery/product meta, widening the product summary, and adding a live preview, amount swatches, selectable design thumbnails, recipient information, optional sender name, message character counter, and delivery date. Sender name is stored on the order item for display/PDF use, not in the giftcard database table. The builder renders only through the custom product add-to-cart form and also has a `woocommerce_single_product_summary` fallback for themes that skip the custom product-type action. It is not attached to the generic `woocommerce_before_add_to_cart_button` action, which prevents themes that also render the default simple-product form from showing two giftcard builders. Product-page quantity controls are hidden and forced to `1` defensively. Theme/default `form.cart` output that is not `.tokolariso-giftcard-cart` is also hidden on giftcard product pages to prevent duplicate add-to-cart buttons. For the existing Toko Lariso product page, the plugin also treats products with SKU prefix `CADEAUKAART` or a title/slug containing `cadeaukaart`/`giftcard` as giftcard products, so a previously saved simple product does not lose the builder.

## Giftcard PDF Generation

Issued giftcards can be downloaded from paid order detail pages. The PDF service renders a `Giftcard PDFs` section through `woocommerce_order_details_after_order_table` only when the order is paid and the giftcard id has been written to the line item after successful payment.

The download endpoint is handled during `template_redirect` with query parameters for giftcard id, order id, item id, order key, and nonce. A download is allowed only when one of these is true:

- The current user can `manage_woocommerce`.
- The logged-in customer owns the order.
- The request contains the matching WooCommerce order key.

The generator does not depend on a bundled Composer/PDF package. It writes a minimal PDF 1.4 document directly. Local JPEG attachment images are embedded as `/DCTDecode` image XObjects. Other local image types are converted through WordPress' configured image editor to cached JPEG files under `uploads/tokolariso-giftcards/pdf-cache/` before embedding. Remote, unreadable, or unsupported images fall back to a designed text card so the download still succeeds. The full code is decrypted only inside the authorized PDF request and is not stored in plaintext order meta or exposed in Store API data.

PDF settings are stored with the normal plugin settings:

- `pdf_logo_image_id`: optional media attachment shown in the purple PDF header. Non-JPEG logos follow the same local JPEG cache conversion path as giftcard designs.
- `pdf_header_color`: configurable hex color for the PDF header banner.
- `giftcard_redeem_url`: base URL for PDF text and QR codes. The PDF service appends the full giftcard code as the `tokolariso_giftcard` query parameter.

The QR code is generated locally as vector rectangles inside the PDF. It uses a fixed Version 6-L byte-mode matrix, which is sufficient for the configured redeem URL plus a Toko Lariso giftcard code. If the URL becomes too long for that matrix, the PDF still shows the readable webshop URL and debug logging records `pdf_qr_payload_too_long`.

The visible PDF webshop URL includes the `https://` URL, the `tokolariso_giftcard` query parameter, and matches the full automatic QR payload. The URL/QR area is also a PDF URI annotation, so PDF readers that support links can open it directly. When that URL is opened with an empty cart, the full code is stored temporarily in WooCommerce session and the HttpOnly `tokolariso_giftcard_pending` cookie for 14 days during `wp_loaded`, before the later cart/checkout apply step. The code is validated server-side before storage, retried after WooCommerce loads the cart from session and after add-to-cart, and cleared after a successful application or a definitive invalid-code failure.

## Database Tables

Tables are created with `dbDelta()` on activation.

### `{prefix}tokolariso_giftcards`

- `id`
- `code_hash`: HMAC-SHA256 of the normalized code.
- `code_encrypted`: encrypted full code for admin detail/resend flows only.
- `code_mask`: masked public/admin display code.
- `initial_amount`
- `current_balance`
- `currency`
- `status`: `active`, `used`, `expired`, `blocked`
- `expires_at`
- `issued_at`
- `purchased_order_id`
- `purchased_order_item_id`
- `recipient_name`
- `recipient_email`
- `message`
- `image_id`
- `image_url`
- `created_at`
- `updated_at`

### `{prefix}tokolariso_giftcard_ledger`

- `id`
- `giftcard_id`
- `mutation_type`: `issued`, `redeemed`, `refunded`, `adjusted`, `expired`
- `amount`: signed amount; redemptions/expirations are negative.
- `balance_before`
- `balance_after`
- `order_id`
- `user_id`
- `reason`
- `created_at`

## Balance Safety

Giftcard application in Cart/Checkout is tentative and stored in the WooCommerce session. During checkout order creation, the tentative allocation becomes pending order metadata and the order total is reduced to the amount still payable by the selected payment method. The real giftcard balance mutation happens only after successful payment, except for fully giftcard-covered zero-payment orders.

The repository uses:

- SQL transaction with `START TRANSACTION` / `COMMIT` / `ROLLBACK`
- `SELECT ... FOR UPDATE` against the giftcard row
- Balance check before update
- Order/giftcard ledger idempotency check for `redeemed`
- Order meta `_tokolariso_giftcards_redeemed` to avoid repeated order-level processing

This prevents two concurrent successful checkout/payment requests from spending the same available balance twice. If an old pending order is paid after the giftcard has been used elsewhere, redemption fails safely, the order is moved to manual review/on-hold where possible, and no silent double spend is created.

## Hooks and Integration Points

### Product

- `product_type_selector`
- `woocommerce_product_class`
- `woocommerce_get_price_html`
- `woocommerce_single_product_zoom_enabled`
- `body_class`
- `wp` for giftcard-only removal of `wc-product-gallery-zoom` theme support
- `woocommerce_product_data_tabs`
- `woocommerce_tokolarisogiftcard_add_to_cart`
- `woocommerce_product_needs_shipping`
- `woocommerce_cart_needs_shipping`
- `woocommerce_cart_needs_shipping_address`
- `woocommerce_shipping_free_shipping_is_available`
- `woocommerce_store_api_checkout_order_processed` at priority `1` for SendCloud session normalization
- `woocommerce_add_to_cart_validation`
- `woocommerce_add_cart_item_data`
- `template_redirect` for applying a giftcard code from a PDF/QR redeem URL
- `woocommerce_before_calculate_totals`
- `woocommerce_after_calculate_totals`
- `woocommerce_get_item_data`
- `woocommerce_checkout_create_order_line_item`
- `wp_enqueue_scripts` for giftcard product CSS and live-preview JavaScript

### Cart and Checkout Blocks

- `before_woocommerce_init`: declares `cart_checkout_blocks` and HPOS compatibility.
- `woocommerce_blocks_loaded`: registers Store API endpoint data and update callback.
- `woocommerce_blocks_cart_block_registration`
- `woocommerce_blocks_checkout_block_registration`
- `woocommerce_cart_shipping_packages`
- `woocommerce_package_rates`
- Store API endpoints extended:
  - `CartSchema::IDENTIFIER`
  - `CheckoutSchema::IDENTIFIER`
- Store API update callback namespace:
  - `tokolariso-giftcards`
- Frontend call:
  - `wc.blocksCheckout.extensionCartUpdate({ namespace, data })`
- The Apply payload accepts `max_amount`. Empty means no manual cap; a positive value caps that card's allocation so customers can choose to use only part of a larger giftcard balance.
- The Blocks UI keeps a shared in-flight update guard around Apply/Remove, with listener synchronization across Checkout Blocks rerenders, so a rerendered checkout cannot submit duplicate giftcard updates before the previous Store API request finishes.
- The Apply button uses the normal WooCommerce/WordPress button classes for theme styling. The click still stops propagation so broad document-level checkout handlers do not treat giftcard Apply as an address or shipping action.
- UI slots:
  - `ExperimentalDiscountsMeta` for the input form.
  - `ExperimentalOrderMeta` for applied giftcard partial payment, when available, so the payment information appears closer to the order total. Older Blocks builds fall back to showing applied giftcard data inside `ExperimentalDiscountsMeta`.
- Button label sync:
  - `placeOrderButtonLabel` is left on the default label because WooCommerce Blocks appends its own order total to that label.
  - When giftcard payment is applied, the frontend stores the remaining payment-method amount from Store API extension data, adds a managed data attribute/class to the place-order button, and shows a CSS overlay with the remaining amount. This keeps React-managed text nodes intact and prevents duplicate labels such as `€ 0,95 · € 10,95`.
  - The overlay does not modify cart totals, order totals, VAT lines, Store API totals, or the submitted checkout payload.

### Orders

- `woocommerce_store_api_checkout_order_processed`
- `woocommerce_store_api_checkout_update_order_meta`
- `woocommerce_mollie_wc_gateway_*_args` and `woocommerce_mollie_wc_gateway_*payment_args`
- `woocommerce_checkout_order_processed`
- `woocommerce_payment_complete`
- `woocommerce_order_status_processing`
- `woocommerce_order_status_completed`
- `woocommerce_order_status_refunded`
- `woocommerce_order_refunded`
- `woocommerce_get_order_item_totals`
- `woocommerce_order_get_total`
- `woocommerce_order_details_after_order_table` for paid-order PDF buttons
- `template_redirect` for secured PDF download responses

### My Account and Balance Checker

- `init` for rewrite endpoint registration.
- `query_vars` for the `giftcards` endpoint.
- `woocommerce_account_menu_items` for the My Account menu item.
- `woocommerce_account_giftcards_endpoint` for the balance checker.
- Shortcode: `[tokolariso_giftcard_balance]`.

## Public Endpoint Security

Public Store API data exposes:

- Giftcard database id
- Masked code only
- Applied amount
- Current card balance
- Formatted amounts
- Multiple-card setting

It does not expose full giftcard codes or encrypted code payloads.

The My Account balance checker requires the customer to enter the full code, validates a WordPress nonce, sanitizes input, and returns only the masked code, current balance, status, expiry date, and customer-safe activity rows. The activity table includes `redeemed`, `refunded`, `adjusted`, and `expired` ledger entries with date, type, order reference when available, signed amount, and balance after. It does not expose full codes, encrypted payloads, internal reasons, or customer/order address data. The shortcode uses the same rendering path.

PDF downloads are not Store API endpoints. They are ordinary WordPress requests guarded by nonce, order ownership/order key, paid-order status, and line-item/giftcard matching checks.

## Admin Full Code Access

Giftcard codes are stored encrypted in the `code_encrypted` column and masked everywhere customer-facing. The WooCommerce admin giftcard detail screen shows the decrypted full code only to users who can access the plugin admin page with `manage_woocommerce`. This is intended for staging tests, stores with disabled outbound email, and manual recipient support.

The full code is not exposed in Cart/Checkout Blocks, Store API responses, order notes, or the admin list table.

## Local PHP Syntax Testing

The plugin includes `tests/lint-all.php`, which recursively runs `php -l` for every PHP file in the plugin folder.

Commands:

```sh
php -l toko-lariso-giftcards.php
php tests/lint-all.php
```

PowerShell:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

## Admin Security

Admin actions require:

- `manage_woocommerce`
- WordPress nonces
- Sanitized inputs
- Escaped output

Manual balance corrections require a reason and create `adjusted` ledger entries.

## Debug Logging

Debug logging is disabled by default and can be enabled at WooCommerce > Toko Lariso Giftcards > Settings. When enabled, diagnostics are written through `wc_get_logger()` with source `toko-lariso-giftcards`.

Logged events include:

- Cart/Store API apply and remove events.
- Store API extension cart data when a giftcard is applied.
- Order preparation start/skip/save events.
- Re-application of already prepared giftcard payment totals on reused Blocks draft orders.
- Last-moment order-total guard events when a gateway reads a stale total.
- Redemption start/success/failure after payment.

Full giftcard codes are redacted. Logs may contain order IDs, masked codes, giftcard IDs, balances, and payment amounts, so debug logging should be disabled after testing.

## Refund Assumption

The default refund behavior is manual review. Automatic restoration can be enabled only for full order refunds. Partial refunds are left manual because the plugin cannot reliably infer whether the refunded value should restore store credit, cash, shipping, or a mix.

## Uninstall and Deactivation

Deactivation:

- Clears scheduled giftcard email events only.
- Does not delete giftcards, ledger, or settings.

Uninstall:

- Deletes data only if the explicit setting is enabled.

## Official WooCommerce References Used

- Store API extensibility: https://github.com/woocommerce/woocommerce/blob/trunk/docs/apis/store-api/extending-store-api/README.md
- Store API endpoint data: https://github.com/woocommerce/woocommerce/blob/trunk/docs/apis/store-api/extending-store-api/extend-store-api-add-data.md
- Store API update callbacks: https://github.com/woocommerce/woocommerce/blob/trunk/docs/apis/store-api/extending-store-api/extend-store-api-update-cart.md
- Cart/Checkout Blocks extensibility: https://github.com/woocommerce/woocommerce/blob/trunk/docs/block-development/extensible-blocks/cart-and-checkout-blocks/README.md
- Blocks integration interface: https://github.com/woocommerce/woocommerce/blob/trunk/docs/block-development/reference/integration-interface.md
- Checkout processing hook notes: https://github.com/woocommerce/woocommerce/blob/trunk/docs/block-development/extensible-blocks/cart-and-checkout-blocks/how-checkout-processes-an-order.md
- Cart total lifecycle and `woocommerce_after_calculate_totals`: https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-cart.html

## Cart Block Troubleshooting

If a normal non-giftcard product also leaves the Cart Block on loading placeholders, the failure is likely outside the giftcard line-item logic. Check browser console errors and Store API network responses before testing giftcard redemption. Uncaught JavaScript errors from theme scripts, consent/cookie plugins, cache/minify plugins, or shipping/VAT extensions can prevent WooCommerce Blocks from completing frontend rendering.
