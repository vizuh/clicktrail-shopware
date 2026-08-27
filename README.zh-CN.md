[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**vizuh/shopware-clicktrail**

采集店面中观测到的获客上下文，并映射已配置的 Shopware 6 订单状态事件。订单级持久化和实时投递仍受已记录的推迟事项约束。

</div>

[![CI](https://github.com/vizuh/clicktrail-shopware/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-shopware/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## 目录

- [为什么](#为什么)
- [安装](#安装)
- [快速上手：店面访问采集](#快速上手店面访问采集)
- [插件配置](#插件配置)
- [订单生命周期转发](#订单生命周期转发)
- [同意门控](#同意门控)
- [第一方代理](#第一方代理)
- [状态存储](#状态存储)
- [差异对比](#差异对比)
- [Shopware Store 审核要求](#shopware-store-审核要求)
- [状态与推迟事项](#状态与推迟事项)
- [测试](#测试)
- [许可证](#许可证)

## 为什么

此插件目前展示两条独立路径：它在店面会话中采集观测到的获客上下文，并将已配置的订单状态变化映射为经同意门控的事件 envelope。仅使用会话存储还无法把该上下文带到后续订单生命周期事件中，因此此版本不提供端到端电商归因。测试投递前请阅读“状态与推迟事项”。

## 安装

Shopware 6.6 **plugin**（非 app）：

```bash
composer require vizuh/shopware-clicktrail
bin/console plugin:refresh
bin/console plugin:install --activate ClickTrail
```

需要 PHP >= 8.1、`shopware/core` ~6.6 以及 `clicktrail/php-sdk`（dev-main）。

## 快速上手：店面访问采集

在每次店面主请求上，`kernel.request` 订阅者会对查询参数、referrer 和落地页执行 SDK 的合并法则：

```php
// 位于 ClickTrail\Shopware\Storefront\Subscriber\RequestSubscriber：
$stored = StoredState::fromJson($session->get('clicktrail_attribution'));

$merged = TouchMerger::observe($stored, new AttributionInput(
    query: $request->query->all(),
    host: $request->getHost(),
    landingPage: $request->getUri(),
    referrer: $request->headers->get('referer'),
    touchTimestamp: gmdate('c'),
));

$session->set('clicktrail_attribution', $merged->toJson());
// ?gclid=... 落地后，session 中保存 first->source === 'google' 及对应 click ID。
// 之后的直接访问不会改变任何内容：首触保持不变。
// 与所有其他 ClickTrail adapter 使用相同的合并法则。
```

可以通过 *Capture storefront touches* 开关按销售渠道关闭采集（见下文）。

## 插件配置

Settings → Extensions → ClickTrail（`src/Resources/config/config.xml`），通过 `Service\PluginConfig` 可按销售渠道读取：

| 配置项 | 含义 | 默认值 |
|---|---|---|
| Site ID | 由采集端下发的标识 | 空 |
| API endpoint | 数据接入基础 URL | 空 |
| Consent integration mode | `auto-detect`（CMP 挂载点）或 `custom`（开发者提供的 resolver） | auto-detect |
| Capture storefront touches | 启用请求订阅者 | 开 |
| Forward sale / lifecycle events | 所有电商订阅者的总门控 | 开 |
| Forward refund events | 仅控制退款转发 | 开 |
| Enable first-party proxy route (`/clicktrail/collect`) | 默认关闭；当你的架构需要第一方 cookie 上下文时启用 | 关 |

## 订单生命周期转发

五个订阅者通过 `PayloadSerializer` 将 Shopware 状态变化转换为带 schema 版本的 SDK 信封，每个都经过配置门控 → 同意门控 → 序列化：

| 订阅者 | Shopware 事件 | 信封 |
|---|---|---|
| OrderPlacedSubscriber | `checkout.order.placed` | `sale` |
| OrderPaidSubscriber | `state_enter.order_transaction_state.paid` | `sale`（已支付阶段） |
| OrderCompletedSubscriber | `state_enter.order_state.completed` | `sale`（已完成） |
| OrderCancelledSubscriber | `state_enter.order_state.cancelled` | `sale` + `status=cancelled` 附加字段；不算退款 |
| RefundSubscriber | `state_enter.order_transaction_state.refunded` | `refund` |

事件 envelope 的 schema 包含订单金额、币种、客户 ID 和订单号。当前仅使用会话的存储方式还不能证明店面归因会进入这些生命周期 envelope。状态机事件名在首个 release tag 前仍标记为针对 Shopware 6.6 的 `TODO verify`。

## 同意门控

一个门控保护所有出站路径。转发转化数据需要 `advertising_storage` 为 granted；unknown 一律按拒绝处理：

```php
// ClickTrail\Shopware\Consent\ConsentGate
$snapshot = $this->consentGate->allows(ConsentSnapshot::CAP_ADVERTISING_STORAGE);
if ($snapshot === null) {
    // 没有 snapshot => 发送被抑制；任何数据都不会离开店铺
}
```

`ShopwareConsentResolver` 是 CMP 挂载点：`auto-detect` 模式读取由 loader 片段注入的 `window.__clicktrail_consent` 载荷；`custom` 模式则由你替换 `services.xml` 中的服务定义。在接入真正的 resolver 之前，它返回 all-unknown 快照；因此默认情况下什么都不转发。

## 第一方代理

可选路由 `POST /clicktrail/collect` 让店面 loader 把信封发送到你自己的域名，而不是直接发到接入端点，从而保留第一方 cookie 上下文：

```php
// CollectController：路由关闭时返回 404 {"error":"disabled"}；
// 缺少 analytics 同意时返回 403 {"error":"consent_required"}。
return new JsonResponse(['queued' => 0], 202);
```

写入 `BatchClient` 队列并在 `kernel.terminate` 时 flush，推迟到完成线上端点验证后实现。

## 状态存储

v1 的明确设计选择：归因状态**只保存在店面 session 中**。不附带数据库表，因此没有 migration。后果：订单生命周期事件目前还无法读回数天前采集的归因；将 `StoredState` 随订单持久化是计划中的 v2 工作。

## 差异对比

| | 本插件 | 通用电商追踪 |
|---|---|---|
| 归因 | 共享 SDK 的确定性首触/末触合并法则，与 WordPress/GTM adapter 完全一致 | 各平台自行的末次点击猜测 |
| 退款 | `refund` 保持与最初归因的关联 | 通常是孤立的退款事件 |
| 同意 | 规范化契约，unknown = denied，在任何发送之前强制执行 | 往往只是客户端标志位 |
| 存储 | v1 仅使用 session，零 migration | 从第一天起就有插件自建表 |

## Shopware Store 审核要求

分发需经过 Shopware Store，上架前会审核质量、安全、兼容性与合规性：

- 不使用动态 SQL；只用 DAL repository 或参数化的 DBAL。
- 店面写路由必须处理 CSRF。
- 除同意允许的范围外不含 PII。
- 通过 theme block 进行兼容 CSP 的脚本注入。
- 同意行为遵循[共享 SDK 同意契约](https://github.com/vizuh/clicktrail-php/tree/main/src/Consent)（unknown = denied）。

## 状态与推迟事项

- 具体 CMP 集成（Cookiebot/CookieYes/iubenda 桥接）：已推迟；resolver 目前返回 all-unknown，接入前不会有任何转发。
- `CollectController` 与电商订阅者中的 `BatchClient` 排队：已推迟，等待线上端点验证。
- Twig block 名称与状态机事件名称：已在首个 release tag 前标记为对 Shopware 6.6 的 `TODO verify`。

## 测试

目前尚未附带 PHPUnit 测试套件。CI 在 PHP 8.1–8.3 下对所有 PHP 文件做 lint；XML 资源在脚手架阶段已做解析验证：

```bash
composer install --prefer-dist --no-interaction || echo "no deps"
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l   # 成功时干净退出
python3 -c "import xml.etree.ElementTree as ET; ET.parse('src/Resources/services.xml'); ET.parse('src/Resources/config/config.xml')"   # XML 格式校验
```

## 许可证

MIT - Copyright (c) 2026 Vizuh OÜ。见 [LICENSE](LICENSE)。
