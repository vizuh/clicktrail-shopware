<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Commerce\Subscriber;

/**
 * state_enter.order_state.completed -> sale.completed (fulfilled stage).
 * TODO verify state-machine event name and payload shape against 6.6.
 */
final class OrderCompletedSubscriber extends AbstractOrderSubscriber
{
}
