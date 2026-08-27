[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**vizuh/shopware-clicktrail**

Capture o contexto de aquisição observado na vitrine e mapeie eventos
configurados de estado de pedidos do Shopware 6. A persistência no pedido e a
entrega em runtime continuam sujeitas aos adiamentos documentados.

</div>

[![CI](https://github.com/vizuh/clicktrail-shopware/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-shopware/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Índice

- [Por quê](#por-quê)
- [Instalação](#instalação)
- [Início rápido: captura da visita à vitrine](#início-rápido-captura-da-visita-à-vitrine)
- [Configuração do plugin](#configuração-do-plugin)
- [Encaminhamento do ciclo de vida do pedido](#encaminhamento-do-ciclo-de-vida-do-pedido)
- [Gate de consentimento](#gate-de-consentimento)
- [Proxy first-party](#proxy-first-party)
- [Armazenamento de estado](#armazenamento-de-estado)
- [Como é diferente](#como-é-diferente)
- [Requisitos de review da Shopware Store](#requisitos-de-review-da-shopware-store)
- [Status e adiamentos](#status-e-adiamentos)
- [Testes](#testes)
- [Licença](#licença)

## Por quê

Este plugin demonstra hoje dois caminhos separados: captura o contexto de
aquisição observado na sessão da vitrine e mapeia mudanças configuradas de
estado do pedido para envelopes de evento condicionados a consentimento. O
armazenamento apenas em sessão ainda não leva esse contexto aos eventos
posteriores do pedido. Portanto, esta versão não oferece atribuição de comércio
end-to-end. Consulte **Status e adiamentos** antes de testar a entrega.

## Instalação

**Plugin** para Shopware 6.6 (não app):

```bash
composer require vizuh/shopware-clicktrail
bin/console plugin:refresh
bin/console plugin:install --activate ClickTrail
```

Requer PHP >= 8.1, `shopware/core` ~6.6 e `clicktrail/php-sdk` (dev-main).

## Início rápido: captura da visita à vitrine

Em toda request principal da vitrine, o subscriber `kernel.request` aplica a lei de merge do SDK sobre parâmetros de query, referrer e landing page:

```php
// dentro de ClickTrail\Shopware\Storefront\Subscriber\RequestSubscriber:
$stored = StoredState::fromJson($session->get('clicktrail_attribution'));

$merged = TouchMerger::observe($stored, new AttributionInput(
    query: $request->query->all(),
    host: $request->getHost(),
    landingPage: $request->getUri(),
    referrer: $request->headers->get('referer'),
    touchTimestamp: gmdate('c'),
));

$session->set('clicktrail_attribution', $merged->toJson());
// Após uma landing com ?gclid=..., a sessão guarda first->source === 'google'
// com o click ID armazenado. Uma visita direta depois não muda nada:
// o primeiro toque permanece. Mesmas leis de merge dos outros adapters ClickTrail.
```

A captura pode ser desligada por sales channel com o switch *Capture storefront touches* (veja abaixo).

## Configuração do plugin

Settings → Extensions → ClickTrail (`src/Resources/config/config.xml`), legível por sales channel via `Service\PluginConfig`:

| Configuração | Significado | Padrão |
|---|---|---|
| Site ID | Identificador emitido pelo coletor | vazio |
| API endpoint | URL base de ingestão | vazia |
| Consent integration mode | `auto-detect` (hook point de CMP) ou `custom` (resolver do desenvolvedor) | auto-detect |
| Capture storefront touches | Ativa o subscriber de request | ligado |
| Forward sale / lifecycle events | Condiciona todos os subscribers de comércio | ligado |
| Forward refund events | Condiciona apenas reembolsos | ligado |
| Enable first-party proxy route (`/clicktrail/collect`) | Desligado por padrão; ative quando sua stack quiser contexto de cookie first-party | desligado |

## Encaminhamento do ciclo de vida do pedido

Cinco subscribers traduzem mudanças de estado do Shopware em envelopes versionados do SDK via `PayloadSerializer`, todos passando por gate de config → gate de consentimento → serialização:

| Subscriber | Evento Shopware | Envelope |
|---|---|---|
| OrderPlacedSubscriber | `checkout.order.placed` | `sale` |
| OrderPaidSubscriber | `state_enter.order_transaction_state.paid` | `sale` (etapa pago) |
| OrderCompletedSubscriber | `state_enter.order_state.completed` | `sale` (concluído) |
| OrderCancelledSubscriber | `state_enter.order_state.cancelled` | `sale` + extra `status=cancelled`; não é reembolso |
| RefundSubscriber | `state_enter.order_transaction_state.refunded` | `refund` |

O schema do envelope inclui valor do pedido, moeda, ID do cliente e número do
pedido. O armazenamento atual apenas em sessão ainda não comprova que a
atribuição da vitrine chega a esses envelopes do ciclo de vida. Os nomes de
eventos da state machine continuam `TODO verify` contra Shopware 6.6 antes da
primeira tag de release.

## Gate de consentimento

Um único gate protege todos os caminhos de saída. Encaminhar dados de conversão exige `advertising_storage` concedido; desconhecido conta como negado:

```php
// ClickTrail\Shopware\Consent\ConsentGate
$snapshot = $this->consentGate->allows(ConsentSnapshot::CAP_ADVERTISING_STORAGE);
if ($snapshot === null) {
    // sem snapshot => entrega suprimida; nada sai da loja
}
```

`ShopwareConsentResolver` é o hook point de CMP: em modo `auto-detect` lê um payload `window.__clicktrail_consent` injetado pelo snippet loader; em modo `custom` você substitui a definição do serviço em `services.xml`. Até um resolver real ser conectado, ele retorna um snapshot all-unknown; ou seja, por padrão nada é encaminhado.

## Proxy first-party

A rota opcional `POST /clicktrail/collect` permite que o loader da vitrine envie envelopes para o seu próprio domínio em vez de direto ao endpoint de ingestão, preservando contexto de cookie first-party:

```php
// CollectController: rota desligada responde 404 {"error":"disabled"};
// falta de consentimento analytics responde 403 {"error":"consent_required"}.
return new JsonResponse(['queued' => 0], 202);
```

Enfileirar no `BatchClient` e fazer flush no `kernel.terminate` está adiado pendente de verificação do endpoint real.

## Armazenamento de estado

Escolha documentada da v1: o estado de atribuição vive **apenas na sessão da vitrine**. Nenhuma tabela de banco acompanha o plugin, portanto não há migrations. Consequência: eventos de ciclo de vida do pedido ainda não conseguem ler atribuição capturada dias antes; persistir `StoredState` junto do pedido é trabalho planejado para a v2.

## Como é diferente

| | Este plugin | Tracking genérico de e-commerce |
|---|---|---|
| Atribuição | Leis de merge determinísticas de primeiro/último toque do SDK compartilhado, idênticas nos adapters WordPress/GTM | Chutes de last-click por plataforma |
| Reembolsos | `refund` permanece vinculado à atribuição inicial | Normalmente um evento de reembolso solto |
| Consentimento | Contrato normalizado, unknown = negado, aplicado antes de qualquer envio | Muitas vezes apenas flag client-side |
| Armazenamento | Apenas sessão na v1, zero migrations | Tabelas próprias desde o primeiro dia |

## Requisitos de review da Shopware Store

A distribuição passa pela Shopware Store, que revisa qualidade, segurança, compatibilidade e conformidade antes de listar:

- Sem SQL dinâmico; apenas repositories DAL ou DBAL parametrizado.
- Tratamento de CSRF em toda rota de escrita da vitrine.
- Nenhum PII além do que o consentimento permite.
- Injeção de script compatível com CSP via theme blocks.
- O comportamento de consentimento segue o [contrato de consentimento do SDK compartilhado](https://github.com/vizuh/clicktrail-php/tree/main/src/Consent) (unknown = negado).

## Status e adiamentos

- Integração concreta de CMP (bridge Cookiebot/CookieYes/iubenda): ADIADO; o resolver retorna all-unknown hoje, então nada é encaminhado até ser conectado.
- Enfileiramento no `BatchClient` no `CollectController` e nos subscribers de comércio: ADIADO pendente de verificação do endpoint real.
- Nomes de blocos Twig e de eventos da state machine: marcados `TODO verify` contra o Shopware 6.6 antes da primeira tag.

## Testes

Ainda não há suíte PHPUnit. O CI aplica lint em todos os arquivos PHP no PHP 8.1–8.3; os recursos XML foram validados durante a scaffolding:

```bash
composer install --prefer-dist --no-interaction || echo "no deps"
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l   # termina limpo em caso de sucesso
python3 -c "import xml.etree.ElementTree as ET; ET.parse('src/Resources/services.xml'); ET.parse('src/Resources/config/config.xml')"   # XML bem-formado
```

## Licença

MIT - Copyright (c) 2026 Vizuh OÜ. Veja [LICENSE](LICENSE).
