<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Consent;

use ClickTrail\Consent\ConsentSnapshot;
use ClickTrail\Consent\ConsentValue;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hook point between the normalized ClickTrail consent contract and a CMP.
 *
 * v1 returns an all-unknown snapshot (unknown = denied downstream), so nothing
 * is forwarded until a real resolver is wired.
 *
 * # DEFERRED - concrete CMP integration (Cookiebot/CookieYes/iubenda via
 * storefront JS bridge) is deferred; see docs/consent-compatibility-plan.md.
 * In "auto-detect" mode this resolver reads a window.__clicktrail_consent
 * payload injected by the loader snippet; in "custom" mode integrators replace
 * this service definition in services.xml.
 */
final class ShopwareConsentResolver
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function resolve(): ConsentSnapshot
    {
        // TODO verify: read CMP-provided state from request attributes / cookies.
        return new ConsentSnapshot(
            source: 'custom',
            collectedAt: gmdate('c'),
            functionalStorage: ConsentValue::Unknown,
            analyticsStorage: ConsentValue::Unknown,
            advertisingStorage: ConsentValue::Unknown,
            adUserData: ConsentValue::Unknown,
            adPersonalization: ConsentValue::Unknown,
        );
    }
}
