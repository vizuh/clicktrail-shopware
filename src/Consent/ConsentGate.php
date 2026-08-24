<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Consent;

use ClickTrail\Consent\ConsentBehavior;
use ClickTrail\Consent\ConsentSnapshot;

/**
 * Single gate every outbound forwarding path must pass.
 *
 * Rule (consent plan): forwarding conversion data to advertising destinations
 * requires advertising_storage granted; enhanced-conversion uploads additionally
 * require ad_user_data. Unknown counts as denied.
 */
final class ConsentGate
{
    public function __construct(
        private readonly ShopwareConsentResolver $resolver,
    ) {
    }

    public function allows(string $capability): ?ConsentSnapshot
    {
        $snapshot = $this->resolver->resolve();

        return ConsentBehavior::can($snapshot, $capability) ? $snapshot : null;
    }
}
