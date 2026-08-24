<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Storefront\Subscriber;

use ClickTrail\Core\AttributionInput;
use ClickTrail\Core\StoredState;
use ClickTrail\Core\TouchMerger;
use ClickTrail\Shopware\Service\PluginConfig;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Captures campaign touches on storefront requests.
 *
 * Flow: query + referrer -> TouchParser/TouchMerger::observe -> StoredState
 * JSON kept in the storefront session under self::SESSION_KEY. No identifiers
 * are persisted before functional consent is checked through ConsentGate once
 * the CMP integration lands (# DEFERRED).
 */
final class RequestSubscriber
{
    public const SESSION_KEY = 'clicktrail_attribution';

    public function __construct(
        private readonly PluginConfig $config,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->config->captureEnabled()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isStorefront($request)) {
            return;
        }

        if (!$this->requestStack->getMainRequest()?->hasSession()) {
            return;
        }
        $session = $this->requestStack->getSession();

        $stored = StoredState::fromJson($session->get(self::SESSION_KEY));

        $input = new AttributionInput(
            query: $request->query->all(),
            host: $request->getHost(),
            landingPage: $request->getUri(),
            referrer: $request->headers->get('referer'),
            touchTimestamp: gmdate('c'),
        );

        $updated = TouchMerger::observe($stored, $input);
        $session->set(self::SESSION_KEY, $updated->toJson());
    }

    /**
     * Storefront-only guard. TODO verify against 6.6: prefer the canonical
     * route-scope check (SalesChannelContext / _route_scope attribute).
     */
    private function isStorefront(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        $scope = $request->attributes->get('_route_scope');

        return is_array($scope) ? \in_array('storefront', $scope, true) : !str_starts_with($request->getPathInfo(), '/api');
    }
}
