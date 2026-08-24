<?php

declare(strict_types=1);

namespace ClickTrail\Shopware;

use Shopware\Core\Framework\Plugin;

/**
 * Plugin base class for the ClickTrail Shopware adapter.
 *
 * Migration/serve config stubs: v1 keeps attribution state in the storefront
 * session only - no database tables, therefore no migrations
 * (documented choice; see README "State storage").
 */
final class ClickTrail extends Plugin
{
}
