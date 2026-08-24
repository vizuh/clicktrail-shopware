<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Typed reader for the plugin configuration defined in config.xml.
 */
final class PluginConfig
{
    public const CONFIG_DOMAIN = 'ClickTrailShopware.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    /** @param string|null $salesChannelId null = default scope */
    public function siteId(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->getString(self::CONFIG_DOMAIN . 'siteId', $salesChannelId);
    }

    public function apiEndpoint(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->getString(self::CONFIG_DOMAIN . 'apiEndpoint', $salesChannelId);
    }

    /** @return 'auto-detect'|'custom' */
    public function consentMode(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->getString(self::CONFIG_DOMAIN . 'consentMode', $salesChannelId);
    }

    public function captureEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->getBool(self::CONFIG_DOMAIN . 'enableCapture', $salesChannelId);
    }

    public function saleForwardingEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->getBool(self::CONFIG_DOMAIN . 'enableSaleForwarding', $salesChannelId);
    }

    public function refundForwardingEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->getBool(self::CONFIG_DOMAIN . 'enableRefundForwarding', $salesChannelId);
    }

    public function firstPartyProxyEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->getBool(self::CONFIG_DOMAIN . 'firstPartyProxyEnabled', $salesChannelId);
    }
}
