<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Controller;

use ClickTrail\Shopware\Consent\ConsentGate;
use ClickTrail\Shopware\Service\PluginConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * First-party proxy route: the storefront loader posts envelopes here instead
 * of straight to the ingest endpoint, so the event carries a first-party
 * cookie context.
 *
 * Route: POST /clicktrail/collect (storefront scope, CSRF off for the loader).
 *
 * # DEFERRED - live verification: queueing into BatchClient and forwarding to
 * the configured endpoint is deferred until task 0.9 (approved live target).
 */
#[Route(defaults: ['_routeScope' => ['storefront'], 'csrf_protected' => false])]
final class CollectController
{
    public function __construct(
        private readonly PluginConfig $config,
        private readonly ConsentGate $consentGate,
    ) {
    }

    #[Route(path: '/clicktrail/collect', name: 'frontend.clicktrail.collect', methods: ['POST'])]
    public function collect(Request $request): JsonResponse
    {
        if (!$this->config->firstPartyProxyEnabled()) {
            return new JsonResponse(['error' => 'disabled'], 404);
        }

        // Consent gate: analytics events require analytics_storage granted.
        if ($this->consentGate->allows(\ClickTrail\Consent\ConsentSnapshot::CAP_ANALYTICS) === null) {
            return new JsonResponse(['error' => 'consent_required'], 403);
        }

        // TODO verify + implement: validate envelope shape against the SDK,
        // queue into BatchClient, flush on kernel.terminate. Not called until
        // live verification lands (# DEFERRED).
        return new JsonResponse(['queued' => 0], 202);
    }
}
