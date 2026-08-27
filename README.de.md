[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**vizuh/shopware-clicktrail**

Erfassen Sie beobachteten Akquisitionskontext in der Storefront und ordnen Sie
konfigurierte Shopware-6-Bestellstatus-Events zu. Persistenz an der Bestellung
und Live-Zustellung unterliegen den dokumentierten Zurückstellungen.

</div>

[![CI](https://github.com/vizuh/clicktrail-shopware/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-shopware/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Index

- [Warum](#warum)
- [Installation](#installation)
- [Schnellstart: Storefront-Besuch erfassen](#schnellstart-storefront-besuch-erfassen)
- [Plugin-Konfiguration](#plugin-konfiguration)
- [Weiterleitung des Bestell-Lebenszyklus](#weiterleitung-des-bestell-lebenszyklus)
- [Consent-Gate](#consent-gate)
- [First-Party-Proxy](#first-party-proxy)
- [Statusspeicherung](#statusspeicherung)
- [Worin es sich unterscheidet](#worin-es-sich-unterscheidet)
- [Anforderungen des Shopware-Store-Reviews](#anforderungen-des-shopware-store-reviews)
- [Status und Zurückstellungen](#status-und-zurückstellungen)
- [Tests](#tests)
- [Lizenz](#lizenz)

## Warum

Dieses Plugin zeigt derzeit zwei getrennte Pfade: Es erfasst beobachteten
Akquisitionskontext in der Storefront-Session und ordnet konfigurierte
Bestellstatusänderungen consent-geprüften Event-Envelopes zu. Reine
Session-Speicherung kann diesen Kontext noch nicht in spätere
Bestelllebenszyklus-Events übertragen. Diese Version bietet daher keine
End-to-End-Commerce-Attribution. Lesen Sie vor Zustellungstests **Status und
Zurückstellungen**.

## Installation

Shopware 6.6 **Plugin** (keine App):

```bash
composer require vizuh/shopware-clicktrail
bin/console plugin:refresh
bin/console plugin:install --activate ClickTrail
```

Benötigt PHP >= 8.1, `shopware/core` ~6.6 und `clicktrail/php-sdk` (dev-main).

## Schnellstart: Storefront-Besuch erfassen

Bei jedem main Storefront-Request wendet der `kernel.request`-Subscriber die Merge-Gesetze des SDK auf Query-Parameter, Referrer und Landingpage an:

```php
// innerhalb von ClickTrail\Shopware\Storefront\Subscriber\RequestSubscriber:
$stored = StoredState::fromJson($session->get('clicktrail_attribution'));

$merged = TouchMerger::observe($stored, new AttributionInput(
    query: $request->query->all(),
    host: $request->getHost(),
    landingPage: $request->getUri(),
    referrer: $request->headers->get('referer'),
    touchTimestamp: gmdate('c'),
));

$session->set('clicktrail_attribution', $merged->toJson());
// Nach einer ?gclid=...-Landingpage enthält die Session first->source === 'google'
// mit gespeicherter Click-ID. Ein direkter Besuch ändert danach nichts:
// der First-Touch bleibt. Dieselben Merge-Gesetze wie in jedem anderen ClickTrail-Adapter.
```

Die Erfassung lässt sich pro Sales Channel über den Schalter *Capture storefront touches* abschalten (siehe unten).

## Plugin-Konfiguration

Settings → Extensions → ClickTrail (`src/Resources/config/config.xml`), pro Sales Channel lesbar über `Service\PluginConfig`:

| Einstellung | Bedeutung | Standard |
|---|---|---|
| Site ID | Vom Collector vergebene Kennung | leer |
| API endpoint | Ingest-Basis-URL | leer |
| Consent integration mode | `auto-detect` (CMP-Hook-Punkt) oder `custom` (Entwickler-Resolver) | auto-detect |
| Capture storefront touches | Aktiviert den Request-Subscriber | an |
| Forward sale / lifecycle events | Gate für alle Commerce-Subscriber | an |
| Forward refund events | Gate ausschließlich für Erstattungen | an |
| Enable first-party proxy route (`/clicktrail/collect`) | Standardmäßig aus; aktivieren, wenn Ihr Stack First-Party-Cookie-Kontext will | aus |

## Weiterleitung des Bestell-Lebenszyklus

Fünf Subscriber übersetzen Shopware-Statusänderungen über `PayloadSerializer` in schemaversionierte SDK-Envelopes, jeder durch Config-Gate → Consent-Gate → Serialisierung:

| Subscriber | Shopware-Event | Envelope |
|---|---|---|
| OrderPlacedSubscriber | `checkout.order.placed` | `sale` |
| OrderPaidSubscriber | `state_enter.order_transaction_state.paid` | `sale` (Stufe bezahlt) |
| OrderCompletedSubscriber | `state_enter.order_state.completed` | `sale` (abgeschlossen) |
| OrderCancelledSubscriber | `state_enter.order_state.cancelled` | `sale` + Extra `status=cancelled`; keine Erstattung |
| RefundSubscriber | `state_enter.order_transaction_state.refunded` | `refund` |

Das Envelope-Schema enthält Bestellwert, Währung, Kunden-ID und Bestellnummer.
Die aktuelle reine Session-Speicherung beweist noch nicht, dass
Storefront-Attribution diese Lebenszyklus-Envelopes erreicht.
State-Machine-Eventnamen bleiben vor dem ersten Release-Tag mit `TODO verify`
gegen Shopware 6.6 markiert.

## Consent-Gate

Ein einzelnes Gate bewacht jeden ausgehenden Pfad. Die Weiterleitung von Konversionsdaten erfordert erteiltes `advertising_storage`; unknown gilt als verweigert:

```php
// ClickTrail\Shopware\Consent\ConsentGate
$snapshot = $this->consentGate->allows(ConsentSnapshot::CAP_ADVERTISING_STORAGE);
if ($snapshot === null) {
    // kein Snapshot => Auslieferung unterdrückt; nichts verlässt den Shop
}
```

`ShopwareConsentResolver` ist der CMP-Hook-Punkt: Im Modus `auto-detect` liest er eine vom Loader-Snippet injizierte `window.__clicktrail_consent`-Payload; im Modus `custom` ersetzen Sie die Service-Definition in `services.xml`. Bis ein echter Resolver angebunden ist, liefert er einen all-unknown-Snapshot; standardmäßig geht also nichts raus.

## First-Party-Proxy

Die optionale Route `POST /clicktrail/collect` erlaubt dem Storefront-Loader, Envelopes an Ihre eigene Domain statt direkt an den Ingest-Endpunkt zu senden; mit First-Party-Cookie-Kontext:

```php
// CollectController: deaktivierte Route antwortet 404 {"error":"disabled"};
// fehlendes Analytics-Consent antwortet 403 {"error":"consent_required"}.
return new JsonResponse(['queued' => 0], 202);
```

Das Einreihen in `BatchClient` und Flush auf `kernel.terminate` ist zurückgestellt bis zur Live-Endpunkt-Verifikation.

## Statusspeicherung

Dokumentierte v1-Entscheidung: Der Attributionszustand lebt **nur in der Storefront-Session**. Keine Datenbanktabellen, daher keine Migrationen. Folge: Order-Lifecycle-Events können bisher keine Tage zuvor erfasste Attribution zurücklesen; das Persistieren von `StoredState` mit der Bestellung ist geplante v2-Arbeit.

## Worin es sich unterscheidet

| | Dieses Plugin | Generisches Ecommerce-Tracking |
|---|---|---|
| Attribution | Deterministische First-/Last-Touch-Merge-Gesetze aus dem gemeinsamen SDK, identisch in WordPress/GTM-Adaptern | Plattformspezifische Last-Click-Vermutungen |
| Erstattungen | `refund` bleibt der ursprünglichen Attribution zugeordnet | Meist ein losgelöstes Refund-Event |
| Consent | Normalisierter Vertrag, unknown = denied, durchgesetzt vor jedem Senden | Oft nur ein clientseitiges Flag |
| Speicherung | Session-only in v1, null Migrationen | Pluginspezifische Tabellen ab Tag eins |

## Anforderungen des Shopware-Store-Reviews

Der Vertrieb läuft über den Shopware Store, der Qualität, Sicherheit, Kompatibilität und Compliance vor dem Listing prüft:

- Kein dynamisches SQL; nur DAL-Repositories oder parametrisiertes DBAL.
- CSRF-Behandlung auf jeder Storefront-Write-Route.
- Keine PII über das hinaus, was Consent erlaubt.
- CSP-kompatible Skript-Injektion über Theme-Blocks.
- Das Consent-Verhalten folgt dem [Consent-Vertrag des gemeinsamen SDK](https://github.com/vizuh/clicktrail-php/tree/main/src/Consent) (unknown = denied).

## Status und Zurückstellungen

- Konkrete CMP-Integration (Cookiebot/CookieYes/iubenda-Bridge): ZURÜCKGESTELLT; der Resolver liefert heute all-unknown, es geht also nichts weiter, bis er angebunden ist.
- `BatchClient`-Queueing in `CollectController` und Commerce-Subscribers: ZURÜCKGESTELLT bis zur Live-Endpunkt-Verifikation.
- Twig-Blocknamen und State-Machine-Eventnamen: mit `TODO verify` gegen Shopware 6.6 markiert, vor dem ersten Release-Tag.

## Tests

Eine PHPUnit-Suite liegt noch nicht vor. CI lintet jede PHP-Datei unter PHP 8.1–8.3; die XML-Ressourcen wurden während des Scaffoldings geparst:

```bash
composer install --prefer-dist --no-interaction || echo "no deps"
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l   # endet sauber bei Erfolg
python3 -c "import xml.etree.ElementTree as ET; ET.parse('src/Resources/services.xml'); ET.parse('src/Resources/config/config.xml')"   # XML-Wohlgeformtheit
```

## Lizenz

MIT - Copyright (c) 2026 Vizuh OÜ. Siehe [LICENSE](LICENSE).
