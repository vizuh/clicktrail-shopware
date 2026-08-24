<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Commerce\Subscriber;

/**
 * checkout.order.placed -> sale.completed envelope.
 * TODO verify event FQCN/shape against 6.6 before wiring transport.
 */
final class OrderPlacedSubscriber extends AbstractOrderSubscriber
{
}
