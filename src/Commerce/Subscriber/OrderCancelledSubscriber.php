<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Commerce\Subscriber;

/**
 * state_enter.order_state.cancelled -> sale.completed with status=cancelled
 * extra (not a refund). TODO verify state-machine event name against 6.6.
 */
final class OrderCancelledSubscriber extends AbstractOrderSubscriber
{
}
