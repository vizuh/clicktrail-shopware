# ClickTrail for Shopware 6

Shopware 6.6 **plugin** (not app) that attaches ClickTrail attribution to the
full commerce lifecycle: capture the storefront visit, then forward consent-
gated `sale.completed` ... `sale.refunded` envelopes with order value,
currency, customer and order IDs - each refund lifecycle event stays attached
to the initial attribution.

Part of the ClickTrail adapter family (see `../PLAN.md`). The deterministic
core lives in [`clicktrail/php-sdk`](../clicktrail-php); this repo owns only
platform effects (config, session storage, HTTP transport wiring, consent
hook point).

## What it does

| Concern | Implementation |
|---|---|
| Touch capture | `kernel.request` storefront subscriber -> `TouchMerger::observe` -> session-stored `StoredState` |
| Order lifecycle | Subscribers on placed/paid/completed/cancelled/refunded state changes -> `PayloadSerializer` envelopes |
| Consent | Normalized `ConsentSnapshot` gate (`advertising_storage` required for forwarding; unknown = denied). CMP integration hook point in `Consent/ShopwareConsentResolver` |
| First-party proxy | Optional `POST /clicktrail/collect` route feeding `BatchClient` |

## State storage (documented choice)

v1 keeps attribution state **in the storefront session only**. No database
tables, therefore **no migrations** ship with this plugin. Consequence: order
lifecycle events cannot read back attribution captured days earlier yet -
persisting `StoredState` with the order is planned v2 work.

## Submission route notes (Shopware Store)

- Distribution: plugin submitted to the Shopware Store; it passes quality,
  security, compatibility and compliance review before listing.
- Security expectations: no dynamic SQL (DAL repository or parameterized DBAL),
  CSRF handling on any storefront write route, no PII beyond what consent
  allows, CSP-compatible script injection through theme blocks.
- Compliance: consent behavior follows the normalized contract in
  `../../docs/consent-compatibility-plan.md` (unknown = denied).

## Status / deferrals

- Concrete CMP integration (Cookiebot/CookieYes/iubenda bridge): DEFERRED -
  resolver returns all-unknown today, so nothing forwards until wired.
- BatchClient queueing in `CollectController` and commerce subscribers:
  DEFERRED pending live-endpoint verification (NEXT-TASKS #0.9).
- Twig block names and state-machine event names marked `TODO verify` against
  Shopware 6.6 before first release tag.

## Development

- Lint every PHP file: see `.github/workflows/ci.yml`.
- Local validation used during scaffolding: `php -l` under PHP 8.1 and 8.3
  containers; XML files parsed with Python `xml.etree`.

## License

MIT - Copyright (c) 2026 Vizuh OÜ. See [LICENSE](LICENSE).
