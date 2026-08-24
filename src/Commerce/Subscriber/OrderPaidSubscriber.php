<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Commerce\Subscriber;

/**
 * state_enter.order_transaction_state.paid -> sale.completed (lifecycle stage).
 * TODO verify state-machine event name and payload shape against 6.6.
 */
final class OrderPaidSubscriber extends AbstractOrderSubscriber
{
}
