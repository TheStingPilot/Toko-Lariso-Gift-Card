# Manual Test Scenarios

Run these on a staging store with WooCommerce Blocks cart and checkout pages.

## 0. Baseline Cart Block

1. Temporarily empty the cart.
2. Add one regular, non-giftcard WooCommerce product.
3. Open the Cart Block page.
4. Inspect the browser console and Store API network request.

Expected:

- The normal product cart renders without grey loading placeholders.
- There are no uncaught frontend JavaScript errors.
- WooCommerce Store API cart requests return successful JSON responses.
- If this baseline fails, fix the theme/plugin/frontend error before testing giftcard behavior.

## 1. Buy a Giftcard

1. Create a `Toko Lariso giftcard` product.
2. Add a product image and at least two gallery images.
3. Open the product page.
4. Confirm the product page shows fixed amount presets, recipient fields, design selection, and an add-to-cart button.
5. Select EUR 25, enter recipient name/email/message, choose a design, add the giftcard to the cart, and place the order.
6. Pay with a normal payment method.

Expected:

- Product is non-virtual and non-taxable.
- The regular WooCommerce product price can be empty; the selected preset becomes the cart line price.
- Giftcard-only Cart and Checkout Blocks load without waiting for shipping rates.
- Giftcard-only shipping cost is EUR 0 because no paid shipping method is required.
- Order status becomes processing/completed.
- One giftcard row is created.
- Ledger contains `issued`.
- Recipient receives an email with the full code and selected image.
- Order item stores masked code and giftcard id.

## 2. Fully Use a Giftcard

1. Create a cart where total including VAT and shipping is less than or equal to the giftcard balance.
2. Apply the code in Checkout Block.
3. Place the order.

Expected:

- Giftcard appears as a partial payment, not as a coupon, cart discount, or negative fee.
- Checkout button shows the remaining payment-method amount as EUR 0.00.
- Order amount charged by the selected payment method becomes zero if balance covers the full total.
- No external Mollie payment is created for a zero-payment order.
- Giftcard status becomes `used`.
- Ledger contains `redeemed`.

## 3. Partially Use a Giftcard

1. Create a cart totaling EUR 41.90 including VAT and shipping.
2. Apply a EUR 15 giftcard.

Expected:

- Remaining payment-method amount is EUR 26.90.
- Checkout button shows EUR 26.90.
- Mollie or the selected payment method receives EUR 26.90, not EUR 41.90.
- Product and shipping tax lines are unchanged by the giftcard partial payment.
- Giftcard current balance is reduced by EUR 15 only after successful payment.
- Ledger contains one `redeemed` entry with amount `-15`.

## 3a. Giftcard Partial Payment Must Not Change VAT

1. Create a cart totaling more than EUR 50 including VAT and shipping.
2. Apply a EUR 50 giftcard.
3. Repeat with goods totaling EUR 44.45 including VAT and shipping of EUR 6.95 including VAT.

Expected:

- The WooCommerce subtotal, shipping, VAT table, and total including VAT remain based on the original product/shipping taxable amounts.
- The VAT table must not show negative goods, shipping, VAT, or total amounts.
- There is no WooCommerce totals line such as `Giftcard -EUR 43.49` or `Giftcard -EUR 50.00`.
- The giftcard summary separately shows `Paid with giftcard: EUR 50.00`.
- The giftcard summary separately shows `Amount to pay with selected payment method: EUR 1.40`.
- The place-order button shows EUR 1.40.
- The payment gateway receives EUR 1.40.
- In the EUR 44.45 + EUR 6.95 example, the WooCommerce/VAT total remains EUR 51.40 and the remaining payment-method amount is EUR 1.40.
- The giftcard widget shows plain text amounts such as `EUR 50.00`, without raw HTML entities.

## 3b. Customer-Limited Giftcard Use

1. Create a cart with a total of EUR 60.00 including VAT and shipping.
2. Apply a EUR 50 giftcard.
3. Enter EUR 25.00 in the optional giftcard amount field before applying.
4. Complete payment successfully.

Expected:

- Giftcard application is accepted.
- WooCommerce product, shipping, and VAT totals remain based on the EUR 60.00 order.
- Giftcard partial payment shows EUR 25.00.
- Checkout button and payment gateway receive EUR 35.00.
- Giftcard balance becomes EUR 25.00 only after successful payment.
- Ledger contains one `redeemed` entry with amount `-25`.

## 4. Mixed 9 Percent, 21 Percent, and Shipping

1. Add one product taxed at 9 percent.
2. Add one product taxed at 21 percent.
3. Add taxable shipping.
4. Apply giftcard payment.

Expected:

- VAT lines remain based on product/shipping taxable amounts.
- Giftcard payment is not applied as a coupon, cart fee, discount, or taxable discount.
- The giftcard section shows the payment-method amount as WooCommerce total including VAT/shipping minus giftcard payment.

## 5. Virtual and Physical Product

1. Add a regular virtual product and a regular physical product.
2. Select a shipping method.
3. Apply giftcard payment in Cart Block.

Expected:

- Shipping remains required because the physical product is in cart.
- Giftcard partial payment can cover the final cart total including shipping without changing shipping or VAT calculations.

## 6. Giftcard Product Shipping

Giftcard-only cart:

1. Add only a `Toko Lariso giftcard` product to the cart.
2. Open Cart Block or Checkout Block.

Expected:

- The product remains non-virtual in WooCommerce.
- Cart and Checkout Blocks load normally.
- No shipping charge or shipping address is required for the giftcard-only cart.
- If another integration creates a giftcard-only shipping package, its rates are forced to EUR 0.

Mixed cart:

1. Add a `Toko Lariso giftcard` product and a normal physical product.
2. Open checkout.

Expected:

- Cart and Checkout Blocks load normally.
- The mixed cart remains one shipping package.
- Normal physical products keep normal shipping.
- Giftcard value is excluded from the package contents cost used for shipping-rate calculations.
- Giftcard value is excluded from free-shipping thresholds. For example, with a EUR 45 free-shipping threshold, a EUR 50 giftcard plus a EUR 3.95 physical product must still require EUR 41.05 more physical-product value before paid shipping becomes free.
- Third-party shipping methods must not show free delivery just because the combined giftcard plus product cart total is above the threshold.

## 7. Cart Block

1. Open the Cart Block page.
2. Apply a valid giftcard.
3. Remove it.
4. Apply an invalid code.

Expected:

- Valid code updates totals through Store API.
- Remove updates totals through Store API.
- Invalid code shows an error and does not reveal whether a specific code exists.

## 8. Checkout Block

1. Open Checkout Block with a valid cart.
2. Apply a giftcard.
3. Place the order.

Expected:

- The order is created with pending giftcard payment metadata and without a negative giftcard fee.
- The order total handed to the payment gateway is the amount after giftcard partial payment.
- The ledger redemption links to the order only after successful payment.
- Full code is not exposed in Store API responses.

## 8b. External Payment Cancelled After Giftcard Application

1. Open Checkout Block with goods and shipping totaling EUR 51.40.
2. Apply a EUR 50 giftcard.
3. Confirm the button shows EUR 1.40.
4. Place the order with Mollie/iDEAL.
5. On the Mollie page, cancel the payment.
6. Check the giftcard in admin.

Expected:

- Mollie shows EUR 1.40.
- The order has pending giftcard metadata.
- The giftcard balance remains EUR 50.00.
- No `redeemed` ledger entry exists for the cancelled/failed payment.
- If the customer retries and pays the order successfully, the giftcard is then redeemed once.

## 8c. Debug Mollie Amount Mismatch

1. Enable WooCommerce > Toko Lariso Giftcards > Settings > Debug logging.
2. Empty cache/minification.
3. Open Checkout Block with a cart totaling EUR 41.90.
4. Apply a giftcard that fully covers the order.
5. Confirm the button shows EUR 0.00.
6. Place the order with Mollie/iDEAL.
7. Open WooCommerce > Status > Logs and choose the latest `toko-lariso-giftcards` log.

Expected:

- `store_api_cart_update_apply_success` shows the masked giftcard and remaining total EUR 0.00.
- `prepare_order_saved` or `prepare_order_reapplied_prepared_total` shows `payment_due` 0.
- `order_total_filter_applied` appears only if a gateway tries to read a stale full total.
- Mollie should not receive EUR 41.90 for a fully covered order.
- Disable debug logging after the test.

## 8a. Repeated Apply Clicks During Checkout Updates

1. Open Checkout Block with a valid cart.
2. Enter a valid giftcard code.
3. Click Apply repeatedly while the checkout totals are updating.

Expected:

- Only one giftcard Store API update is sent while the first request is in flight.
- The input and Apply button are disabled during the update.
- The giftcard is not applied twice.
- With `wc-postcode-checker` 3.7.2 active, clicking the giftcard Apply button is not intercepted by the plugin's broad `.wc-block-components-button` document click handler.
- Console messages from external shipping observers, such as `Observer for shipping is already active.`, do not indicate duplicate giftcard redemption when they are triggered by real address/shipping actions.

## 9. Refund Scenario

Manual mode:

1. Place an order using giftcard payment.
2. Refund the order.

Expected:

- Giftcard balance is not restored automatically.
- Order receives an admin note to review manually.

Full-refund restore mode:

1. Set refund behavior to `Restore giftcard credit on full order refund`.
2. Place an order using giftcard payment.
3. Fully refund the order.

Expected:

- Giftcard credit is restored once.
- Ledger contains `refunded`.
- Repeating the refund/status action does not restore twice.

## 10. SendCloud Giftcard-only Checkout

1. Activate SendCloud Shipping with service points enabled.
2. Add only a `Toko Lariso giftcard` product to the cart.
3. Open Checkout Block.
4. Select a normal payment method.
5. Place the order.

Expected:

- Store API checkout does not return HTTP 500.
- No fatal is logged from SendCloud's `wc_blocks_validate_service_point_selection()`.
- The order is created without a shipping line item.
- The giftcard remains a non-virtual order line item.
- Giftcard issuance runs after successful payment/status transition.

## 11. Admin Full Code Access

1. Buy a giftcard in a staging store where outbound email is disabled.
2. Open WooCommerce > Toko Lariso Giftcards.
3. Open the generated giftcard detail page.

Expected:

- The giftcard list still shows the masked code only.
- The detail screen shows a readonly `Full code` field.
- The full code can be selected/copied by an admin with `manage_woocommerce`.
- Store API responses and customer-facing totals still expose only masked codes.

## 12. My Account Balance Checker

1. Open My Account > Giftcards.
2. Enter a valid full giftcard code.
3. Enter an invalid code.
4. Add `[tokolariso_giftcard_balance]` to a staging page and repeat the checks.

Expected:

- Valid lookup shows masked code, current balance, status, and expiry date.
- Valid lookup shows spending/activity rows for redeemed, refunded, adjusted, and expired ledger entries.
- Invalid lookup uses a generic error.
- Full code is not printed back to the page.
- Internal ledger reasons and customer/order address data are not printed back to the page.
- Expired cards are marked expired before showing the result.

## 13. Giftcard Product Page Zoom and Admin Version

1. Open a published `Toko Lariso giftcard` product with product and gallery images.
2. Hover and click the giftcard gallery images.
3. Open WooCommerce > Toko Lariso Giftcards.

Expected:

- The giftcard image/design selection works.
- No magnifying-glass zoom overlay appears on the giftcard product page.
- The default WooCommerce product gallery is not visible above or next to the giftcard builder.
- The giftcard builder appears once, not once inside a default simple-product form and again below the product summary.
- The quantity control is hidden/forced to `1`.
- Catalog/category add-to-cart buttons do not AJAX-add a giftcard directly; they open the product page so the builder can capture the recipient, amount, message, and design.
- The admin page shows the installed plugin version near the top.

## 14. Giftcard Product Visual Builder and PDF Download

1. Open a published `Toko Lariso giftcard` product with at least one product image and gallery image.
2. Select different fixed amounts and design images.
3. Enter receiver name, receiver email, sender name, and a message.
4. Place and successfully pay the order.
5. Open the order details page after payment.
6. Click the PDF download button.

Expected:

- The product form shows a live preview, amount buttons, selectable picture thumbnails, recipient fields, sender name, message counter, and delivery date field.
- The visual builder is the primary product interface and uses the full product content width.
- The builder appears even when the theme does not render WooCommerce's custom product-type add-to-cart action.
- Changing amount/design updates the preview.
- After payment, the order details page shows a `Giftcard PDFs` section.
- The PDF download is available only after the giftcard has been issued.
- The PDF contains the full giftcard code, amount, recipient, optional sender name, expiry date, and message.
- The download link fails without the nonce/order key or an authorized logged-in user.
