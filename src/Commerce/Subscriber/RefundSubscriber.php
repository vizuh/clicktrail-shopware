<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Commerce\Subscriber;

/**
 * state_enter.order_transaction_state.refunded -> refund envelope
 * carrying refunded value; refund lifecycle stays attached to the initial
 * attribution captured at storefront_visit. TODO verify event name + partial
 * refund handling (refunded amount vs total) against 6.6.
 */
final class RefundSubscriber extends AbstractOrderSubscriber
{
}
