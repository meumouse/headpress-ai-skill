# Payment gateways in the headless checkout

The storefront places orders with `POST /wc/store/v1/checkout` (via `hp.checkout.place()`). A gateway
works headless only if its WordPress plugin processes that request. Canonical examples: the PayPal
Store API and Asaas Store API plugins (`admin/src/Store_Api/Payment_Processor.php`), Headpress
`GET /store/checkout/payment-methods`, the starter's `docs/INTEGRATIONS.md`.

## Contents
1. Classify the gateway
2. WordPress side — processing the Store API checkout
3. Public client config
4. Extra routes the storefront calls
5. Webhooks and confirmation
6. Headpress module for the gateway
7. Storefront side — payment provider
8. Order received, polling and retries
9. Security rules
10. Checklist per gateway

---

## 1. Classify the gateway

| Kind | Examples | Storefront does |
|---|---|---|
| `offline` / async | Pix, boleto, bank transfer | Places the order, shows QR/copy-paste or slip, **polls** status |
| `redirect` (hosted) | Hosted checkout pages | Follows `payment_result.redirect_url` (outside `/checkout`) |
| `card` | Transparent card gateways | Tokenizes **in the browser** with the PSP's script, sends only the token |
| `wallet` | PayPal buttons | Renders the PSP's buttons/iframes; approval id goes in `payment_data` |

The starter hides `card` and `wallet` methods that have no provider, and treats unknown kinds as
offline with a description. Make sure each gateway publishes the right `kind`.

## 2. WordPress side

Register a normal `WC_Payment_Gateway` (`woocommerce_payment_gateways`). Then either:

**A. Context handler (recommended).** Hook before WooCommerce's legacy fallback (priority 999):

```php
add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'process' ), 500, 2 );

public function process( $context, &$result ): void {
	if ( ! $context instanceof PaymentContext || ! $result instanceof PaymentResult ) return;
	if ( 'my_gateway' !== $context->payment_method || $result->status ) return;
	$data = (array) $context->payment_data;                 // key => value from payment_data[]
	// … call the PSP; on a shopper-fixable failure:
	// throw new RouteException( 'my_gateway_payment_error', wp_strip_all_tags( $msg ), 400 );
	$result->set_status( 'success' );                        // success | pending | failure | error
	$result->set_redirect_url( $context->order->get_checkout_order_received_url() ); // Headpress rewrites it to the storefront
	$result->set_payment_details( array_merge( $result->payment_details, array( 'pix_code' => $code, 'pix_expires_at' => $iso ) ) ); // scalars only
}
```

Reusing a classic gateway: define `WOOCOMMERCE_CHECKOUT`, put `payment_data` into a request holder the
classic code reads instead of `$_POST`, run `validate_fields()` + `process_payment( $order_id )`, map
its result.

**B. Legacy fallback.** Do nothing: WooCommerce copies `payment_data` into `$_POST` and calls
`process_payment()`. Works for simple classic gateways, but you control less (errors, details).

Store API request/response:

```http
POST /wp-json/wc/store/v1/checkout
Cart-Token: …
{ "payment_method": "my_gateway", "payment_data": [ { "key": "token", "value": "…" } ],
  "billing_address": { …, "email": "…" }, "shipping_address": { … }, "extensions": { "headpress": { "attribution": {…}, "consent": {…} } } }
→ { "order_id", "order_key", "status", "payment_result": { "payment_status", "payment_details": [ {key,value} ], "redirect_url" } }
```

## 3. Public client config

The Store API only lists gateway ids. Headpress `GET /headpress/v1/store/checkout/payment-methods`
returns `{ id, title, description, icon, order_button_text, supports[], config{} }` per enabled
gateway; fill `config` with:

```php
add_filter( 'Headpress/Store/Payment_Method_Config/my_gateway', function( array $config, $gateway ) {
	return $config + array(
		'kind'           => 'card',          // offline | redirect | card | wallet
		'publishable_key'=> '…',              // PUBLIC values only — never secrets
		'installments'   => array( 'max' => 12, 'interest_free_up_to' => 3 ),
		'routes'         => array( 'createOrder' => rest_url( 'my-gateway/v1/orders' ) ),
	);
}, 10, 2 );
```

Flush the store config when the gateway's settings change
(`update_option_woocommerce_my_gateway_settings` → `public_config->flush_and_notify`). Store titles
as plain text — HTML entities double-escaped through several plugins show up as `R&#36;` in the UI.

## 4. Extra routes the storefront calls

Create-order / session-token / status routes (own namespace, added to CORS):

- Authorize with the **Cart-Token** (load the cart: validate with `CartTokenUtils::validate_cart_token()`,
  switch to the Store API session handler, `wc_load_cart()`, `calculate_totals()`) or with
  `order_id` + `hash_equals( $order->get_order_key(), $key )` — never id alone.
- **Recompute amounts on the server**; never trust a client amount.
- Idempotency keys toward the PSP (e.g. derived from cart hash + amount) so retries don't double charge.
- Responses `Cache-Control: no-store, private`, `X-Robots-Tag: noindex`.
- Status endpoint for async methods, e.g. `GET /headpress/v1/store/<gateway>/orders/{id}/payment?key=`
  (store scope, key pattern `^wc_order_[A-Za-z0-9]+$`, full `schema()`), returning
  `{ status: pending|paid|expired, … }`.
- If the customer Bearer token must reach those routes, add them to `Headpress/Accounts/Token_Routes`.

## 5. Webhooks and confirmation

- Verify every PSP webhook: signature check with the PSP, or **re-fetch the payment from the PSP API**
  by id and trust only that. Process idempotently.
- Put webhooks under `/wc-api/<id>` (already allowlisted) or a REST route; a custom rewrite endpoint
  must be excluded from headless redirects (`Headpress/Headless/Should_Redirect`).
- On confirmation, move the order to `processing`/`completed` with WooCommerce CRUD — Headpress fires
  `order.paid` (server-side analytics, emails, revalidation of `order:{id}`).
- Expiry checks (Pix) as Action Scheduler jobs.
- **Pix/boleto validity:** short windows (30 min) cancel high-ticket orders whose buyers pay later.
  Align the gateway's expiry with `woocommerce_hold_stock_minutes`, and rely on Headpress's unpaid-order
  limit to stop stock locking.

## 6. Headpress module for the gateway

If the gateway plugin integrates with Headpress, give it a module (`id` = gateway slug, `category`
`commerce`, `default_enabled` true, `requirements` `['woocommerce']`). In `boot()`: payment-method
config filter, CORS namespace, token routes, `Headpress/Accounts/Order` (payment details in the
customer's order view), events (`<gateway>.payment_confirmed` …) with revalidation tag `order:{id}`,
split-payment filters (`Headpress/Split_Payments/Gateway_Kind`, `/Process_Payment`,
`/Payment_Details`), health checks (credentials, webhook registered, currency). `public_config()`
exposes only public values (client id, mode, endpoints).

## 7. Storefront side — payment provider

In the starter (`docs/INTEGRATIONS.md`):

1. `src/integrations/<gateway>/` with a form component taking `PaymentFormProps`
   (`method`, `cart`, `amount`, `guest`, `getPayer()`, `register()`, `placeOrder()`, `onError()`).
2. The form calls `register({ submit })`; `submit()` validates, tokenizes in the browser and resolves
   the `payment_data` array (`[{ key, value }]`) — or throws `PaymentError` with a message already in
   the UI language.
3. Export a `PaymentProvider { id, supports(method), Form }` (+ optional `pendingPayment`,
   `pendingFromDetails`, `pollStatus`, `submitsItself` for gateways with their own button).
4. Add one line to `providers` in `src/features/checkout/payments/registry.ts` — the only file that
   names gateways.
5. Charge `amount` from the props, not the cart total (split payments pass one part).

Run PSP scripts that the PSP must serve itself (3DS scripts with integrity checks) from the PSP's
origin. Calls that the PSP rate-limits by buyer IP run in the browser on purpose.

## 8. Order received, polling and retries

- Hosted redirect outside `/checkout` → `window.location.assign(redirectUrl)`; otherwise go to the
  order-received route with `order_id` + `key`, keep the pending payment in `sessionStorage`, poll
  `hp.payments.summary()` / the gateway status route, then `trackPurchase()` **once paid**.
- The order-received link must work on another device (it only needs id + key).
- A card failure needs "try another method" without recreating the order
  (`hp.checkout.retryPayment(orderId, { key, paymentMethod })`, `POST /checkout/{id}`).
- Checkout timeout ~60 s; never auto-retry `place()`.
- Free orders (`needs_payment = false`) skip the payment step.

## 9. Security rules

- Card numbers never reach the storefront server or WordPress (PSP tokenization / iframes).
- Secrets encrypted at rest, overridable by `wp-config.php` constants, never in `config` or logs.
- Error codes prefixed with the plugin (`my_gateway_*`), real HTTP statuses.
- Discount and installment rules (Pix discount, interest) come from the server (installments module
  / gateway config); the UI never hard-codes them.

## 10. Checklist per gateway

- [ ] Appears in `/store/checkout/payment-methods` with the right `kind` and public `config`.
- [ ] Sandbox order succeeds; failure shows a fixable message; retry with another method works.
- [ ] Async: QR/slip shown, polling flips to paid after the webhook, expiry handled.
- [ ] Webhook verified, idempotent, reachable with headless redirects on.
- [ ] Order-received page correct on another device; purchase tracked once, after payment.
- [ ] Split payments (if enabled): each leg charges its own amount.
