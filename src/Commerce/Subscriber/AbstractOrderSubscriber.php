<?php

declare(strict_types=1);

namespace ClickTrail\Shopware\Commerce\Subscriber;

use ClickTrail\Consent\ConsentSnapshot;
use ClickTrail\Core\PayloadSerializer;
use ClickTrail\Shopware\Consent\ConsentGate;
use ClickTrail\Shopware\Service\PluginConfig;

/**
 * Shared wiring for order-lifecycle subscribers: config gate -> consent gate
 * (advertising_storage) -> PayloadSerializer envelope. Transport (BatchClient
 * queue) is # DEFERRED until BatchClient live-endpoint verification (task 0.9).
 */
abstract class AbstractOrderSubscriber
{
    public function __construct(
        protected readonly PluginConfig $config,
        protected readonly ConsentGate $consentGate,
        protected readonly \Doctrine\DBAL\Connection $connection,
    ) {
    }

    /** @return array{snapshot: ConsentSnapshot}|null null = suppressed */
    protected function gate(?string $salesChannelId = null): ?array
    {
        if (!$this->config->saleForwardingEnabled($salesChannelId)) {
            return null;
        }

        $snapshot = $this->consentGate->allows(\ClickTrail\Consent\ConsentSnapshot::CAP_ADVERTISING_STORAGE);

        return $snapshot === null ? null : ['snapshot' => $snapshot];
    }

    /**
     * Build the schema_version-stamped envelope for one order lifecycle event.
     *
     * @param array<string, mixed> $order row from order + order_customer
     * @return array<string, mixed>
     */
    protected function serialize(string $siteId, string $eventName, array $order): array
    {
        $serializer = new PayloadSerializer();
        // TODO verify attribution source for order events in v2: session state is
        // gone at offline processing time; persist StoredState with the order.
        return $serializer->serialize(
            $siteId,
            [
                'name' => $eventName,
                'id' => (string) ($order['order_id'] ?? ''),
                'object_type' => 'order',
                'object_id' => (string) ($order['order_number'] ?? ''),
                'value' => isset($order['amount_total']) ? (float) $order['amount_total'] : null,
                'currency' => isset($order['currency']) ? (string) $order['currency'] : null,
            ],
            \ClickTrail\Core\StoredState::empty(),
            [
                'customer' => ['id' => (string) ($order['customer_id'] ?? '')],
            ],
        );
    }

    /**
     * Minimal order projection loader. TODO verify against 6.6 schema:
     * join currency via sales channel context; amount_total lives on
     * price JSON column - parse or use the DAL repository instead.
     *
     * @return array<string, mixed>|null
     */
    protected function loadOrder(string $orderId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS order_id, order_number, price AS price_json, customer_id FROM `order` WHERE id = :id',
            ['id' => hex2bin(str_replace('-', '', $orderId))],
        );

        if (!\is_array($row)) {
            return null;
        }

        $price = json_decode((string) ($row['price_json'] ?? '{}'), true);
        $row['amount_total'] = \is_array($price) ? ($price['totalPrice'] ?? null) : null;
        unset($row['price_json']);

        return $row;
    }
}
