[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**vizuh/shopware-clicktrail**

ClickTrail attribution across the full Shopware 6 commerce lifecycle — capture the storefront visit, then forward consent-gated sale and refund events that stay attached to the original attribution.

</div>

[![CI](https://github.com/vizuh/clicktrail-shopware/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-shopware/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Index

- [Why](#why)
- [Installation](#installation)
- [Quick start: storefront visit capture](#quick-start-storefront-visit-capture)
- [Plugin configuration](#plugin-configuration)
- [Order lifecycle forwarding](#order-lifecycle-forwarding)
- [Consent gating](#consent-gating)
- [First-party proxy](#first-party-proxy)
- [State storage](#state-storage)
- [How it differs](#how-it-differs)
- [Shopware Store review requirements](#shopware-store-review-requirements)
- [Status and deferrals](#status-and-deferrals)
- [Testing](#testing)
- [License](#license)

## Why

Ecommerce trackers usually fire a purchase pixel and forget it. This Shopware 6.6 plugin attaches the deterministic ClickTrail core ([`clicktrail/php-sdk`](https://github.com/vizuh/clicktrail-php)) to the whole commerce lifecycle: a paid-search visit is captured on the storefront, and every later state change — placed, paid, completed, cancelled, refunded — becomes a consent-gated event envelope carrying order value, currency, customer and order IDs. A refund is never an orphan row; it stays attached to the attribution that created the sale.

## Installation

Shopware 6.6 **plugin** (not app):

```bash
composer require vizuh/shopware-clicktrail
bin/console plugin:refresh
bin/console plugin:install --activate ClickTrail
```

Requires PHP >= 8.1, `shopware/core` ~6.6, and `clicktrail/php-sdk` (dev-main).

## Quick start: storefront visit capture

On every main storefront request, the `kernel.request` subscriber runs the SDK merge law against query parameters, referrer and landing page:

```php
// inside ClickTrail\Shopware\Storefront\Subscriber\RequestSubscriber:
$stored = StoredState::fromJson($session->get('clicktrail_attribution'));

$merged = TouchMerger::observe($stored, new AttributionInput(
    query: $request->query->all(),
    host: $request->getHost(),
    landingPage: $request->getUri(),
    referrer: $request->headers->get('referer'),
    touchTimestamp: gmdate('c'),
));

$session->set('clicktrail_attribution', $merged->toJson());
// After a ?gclid=... landing, the session holds first->source === 'google'
// with the click ID stored. A direct visit afterwards changes nothing:
// first touch stays. Same merge laws as every other ClickTrail adapter.
```

Capture can be turned off per sales channel with the *Capture storefront touches* switch (see below).

## Plugin configuration

Settings → Extensions → ClickTrail (`src/Resources/config/config.xml`), readable per sales channel through `Service\PluginConfig`:

| Setting | Meaning | Default |
|---|---|---|
| Site ID | Identifier issued by the collector | empty |
| API endpoint | Ingest base URL | empty |
| Consent integration mode | `auto-detect` (CMP hook point) or `custom` (developer-supplied resolver) | auto-detect |
| Capture storefront touches | Enables the request subscriber | on |
| Forward sale.completed / lifecycle events | Gates all commerce subscribers | on |
| Forward sale.refunded events | Gates refund forwarding only | on |
| Enable first-party proxy route (`/clicktrail/collect`) | Off by default; enable when your stack wants a first-party cookie context | off |

## Order lifecycle forwarding

Five subscribers translate Shopware state changes into schema-versioned SDK envelopes via `PayloadSerializer`, each passing config gate → consent gate → serialization:

| Subscriber | Shopware event | Envelope |
|---|---|---|
| OrderPlacedSubscriber | `checkout.order.placed` | `sale.completed` |
| OrderPaidSubscriber | `state_enter.order_transaction_state.paid` | `sale.completed` (paid stage) |
| OrderCompletedSubscriber | `state_enter.order_state.completed` | `sale.completed` (fulfilled) |
| OrderCancelledSubscriber | `state_enter.order_state.cancelled` | `sale.completed` + `status=cancelled` extra — not a refund |
| RefundSubscriber | `state_enter.order_transaction_state.refunded` | `sale.refunded` |

Every envelope carries order value, currency, customer ID and order number. The refund envelope keeps the link to the attribution captured at `storefront_visit`. State-machine event names are marked `TODO verify` against 6.6 before the first release tag.

## Consent gating

One gate guards every outbound path. Forwarding conversion data requires `advertising_storage` granted; unknown counts as denied:

```php
// ClickTrail\Shopware\Consent\ConsentGate
$snapshot = $this->consentGate->allows(ConsentSnapshot::CAP_ADVERTISING_STORAGE);
if ($snapshot === null) {
    // no snapshot => delivery suppressed; nothing leaves the shop
}
```

`ShopwareConsentResolver` is the CMP hook point: in `auto-detect` mode it reads a `window.__clicktrail_consent` payload injected by the loader snippet; in `custom` mode you replace the service definition in `services.xml`. Until a real resolver is wired it returns an all-unknown snapshot — so by default, nothing forwards.

## First-party proxy

Optional route `POST /clicktrail/collect` lets the storefront loader post envelopes to your own domain instead of straight to the ingest endpoint, keeping a first-party cookie context:

```php
// CollectController: disabled route answers 404 {"error":"disabled"};
// missing analytics consent answers 403 {"error":"consent_required"}.
return new JsonResponse(['queued' => 0], 202);
```

Queueing into `BatchClient` and flushing on `kernel.terminate` is deferred pending live-endpoint verification.

## State storage

A documented v1 choice: attribution state lives **in the storefront session only**. No database tables ship, therefore no migrations. Consequence: order lifecycle events cannot read back attribution captured days earlier yet — persisting `StoredState` with the order is planned v2 work.

## How it differs

| | This plugin | Generic ecommerce tracking |
|---|---|---|
| Attribution | Deterministic first/last-touch merge laws from the shared SDK, identical in WordPress/GTM adapters | Per-platform last-click guesses |
| Refunds | `sale.refunded` stays attached to the initial attribution | Usually a detached refund event |
| Consent | Normalized contract, unknown = denied, enforced before any send | Often a client-side flag only |
| Storage | Session-only v1, zero migrations | Plugin-specific tables from day one |

## Shopware Store review requirements

Distribution goes through the Shopware Store, which reviews quality, security, compatibility and compliance before listing:

- No dynamic SQL — DAL repositories or parameterized DBAL only.
- CSRF handling on any storefront write route.
- No PII beyond what consent allows.
- CSP-compatible script injection through theme blocks.
- Consent behavior follows the normalized contract in [`docs/consent-compatibility-plan.md`](../docs/consent-compatibility-plan.md) (unknown = denied).

## Status and deferrals

- Concrete CMP integration (Cookiebot/CookieYes/iubenda bridge): DEFERRED — the resolver returns all-unknown today, so nothing forwards until wired.
- `BatchClient` queueing in `CollectController` and commerce subscribers: DEFERRED pending live-endpoint verification.
- Twig block names and state-machine event names: marked `TODO verify` against Shopware 6.6 before the first release tag.

## Testing

No PHPUnit suite ships yet. CI lints every PHP file on PHP 8.1–8.3; XML resources were parsed during scaffolding validation:

```bash
composer install --prefer-dist --no-interaction || echo "no deps"
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l   # exits clean on success
python3 -c "import xml.etree.ElementTree as ET; ET.parse('src/Resources/services.xml'); ET.parse('src/Resources/config/config.xml')"   # XML well-formedness
```

## License

MIT - Copyright (c) 2026 Vizuh OÜ. See [LICENSE](LICENSE).
