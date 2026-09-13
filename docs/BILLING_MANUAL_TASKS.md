# Billing Operations — 2Checkout Manual Tasks

Manual actions that must be performed in the 2Checkout (Verifone) merchant
dashboard. Never commit secrets to the repository — all secret values live in
`.env` on the server.

## 1. INS webhook endpoint (REQUIRED BEFORE PRODUCTION)

The application consumes 2Checkout INS (Instant Notification Service)
messages. Nothing in the app grants paid access from a browser redirect — the
INS endpoint is the only authoritative payment confirmation.

- Dashboard location: **Account → Notifications (INS) → Settings** (integration
  section of the merchant area).
- Set the direct return/notification URL to:
  `https://exospace.gallery/webhooks/2checkout`
- Optional secondary URL (refund/chargeback redundancy):
  `https://exospace.gallery/webhooks/2checkout/refund`
- Set the **Secret word** used for `md5_hash` validation to the same value as
  `TWOCHECKOUT_SECRET_WORD` in `.env`. If you rotate the secret word there,
  rotate it in `.env` in the same change window — otherwise every webhook is
  rejected (403) and paid orders are not fulfilled.
- Use the dashboard's **test / send test notification** action after setup and
  confirm a `2Checkout Webhook Received` entry appears in the ops logs and the
  response is `200 OK`.

Affects existing customers: yes — without this, renewals and new orders are
not fulfilled automatically.
Safe on production: yes.

## 2. INS `md5_hash` validation (informational — implemented server-side)

The app validates the documented 2Checkout INS hash:
`UPPER(MD5(UPPER(MD5(SALE_ID)) + VENDOR_ID + INVOICE_ID + SECRET_WORD))`.
No dashboard action is needed beyond step 1 (same secret word). Do not enable
any "signature/HMAC" INS option aimed at a different integration format — the
app treats `md5_hash` as the authoritative mechanism.

## 3. Recurring products (REQUIRED before offering subscriptions)

`TWOCHECKOUT_RECURRING_PRODUCT_ID_PRO` and
`TWOCHECKOUT_RECURRING_PRODUCT_ID_STUDIO` are **not set** in production `.env`.
While unset, the monthly-subscription options are hidden and direct requests to
the recurring checkout fail safely with an error message.

To enable monthly subscriptions:

- Dashboard location: **Catalog → Products → Add product** (enable **Recurring
  billing**, set the billing cycle to monthly).
- Create one product per tier (Pro, Studio) at the intended monthly prices.
- Copy the numeric product IDs into `.env` as
  `TWOCHECKOUT_RECURRING_PRODUCT_ID_PRO` / `TWOCHECKOUT_RECURRING_PRODUCT_ID_STUDIO`.
- Set `TWOCHECKOUT_RECURRING_PRICE_PRO_MONTHLY` /
  `TWOCHECKOUT_RECURRING_PRICE_STUDIO_MONTHLY` to exactly the dashboard prices.
  These values are used to sign the buy link — a mismatch produces a signature
  the gateway rejects at checkout.

Affects existing customers: no (adds a purchase option).
Safe on production: yes.

## 4. One-time product prices and signature enforcement

`TWOCHECKOUT_PRICE_PRO=29.00` and `TWOCHECKOUT_PRICE_STUDIO=99.00` are used to
sign one-time buy links. Verify in **Catalog → Products** that the live prices
match exactly. If the gateway's link-signature enforcement is enabled
(account-level checkout settings), a mismatch makes checkout reject the link.

## 5. IP allowlist for INS (RECOMMENDED)

- Dashboard/documentation location: 2Checkout publishes the INS source IP
  ranges in the merchant documentation (Notifications section).
- Set `TWOCHECKOUT_WEBHOOK_IP_ALLOWLIST` in `.env` to a comma-separated list of
  those ranges. `md5_hash` remains the primary defense; this is
  defense-in-depth. When blank in production a warning is logged per webhook.

## 6. Refunds for recurring orders (OPERATIONAL)

A `REFUND_ISSUED` notification downgrades the local account when the refund is
full, but it does **not** terminate the 2Checkout subscription itself. When
refunding a subscription installment, also cancel the subscription:

- Dashboard location: **Orders & Subscriptions → Subscriptions → [subscription]
  → Cancel** (or use the refund flow's "cancel subscription" option if
  offered).
- Otherwise the next installment succeeds and the renewal notification arrives
  for an account that was already refunded (the renewal will not restore the
  plan automatically — support must handle it).

## 7. Superseded subscription alerts (OPERATIONAL)

When a subscriber completes a new purchase, the app cancels the replaced
subscription via the 2Checkout API. If that API call fails, a Slack alert
titled "2Checkout: superseded subscription still billing" is raised. If you see
one, cancel the named subscription manually in the dashboard so the customer is
not double-billed.

## 8. Sandbox verification checklist (before go-live)

1. Place a sandbox one-time order for Pro via the billing portal; confirm the
   `ORDER_CREATED` webhook upgrades the account.
2. Repeat with `demo=Y` if your account is in demo mode and confirm the order
   is ignored (no upgrade).
3. Trigger a sandbox refund and confirm the account is downgraded.
4. Start a sandbox recurring order, confirm `RECURRING_INSTALLMENT_SUCCESS`
   extends the period, then cancel it and confirm
   `RECURRING_ORDER_CANCELLED` marks the subscription cancelled.
