<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\ProductAlertGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magenx\ProductAlertGraphQl\Model\ProductAlertManager;

/**
 * Resolver for the `productAlertUnsubscribe` mutation.
 */
class Unsubscribe implements ResolverInterface
{
    /**
     * @param ProductAlertManager $manager
     * @param RequestReader $requestReader
     */
    public function __construct(
        private readonly ProductAlertManager $manager,
        private readonly RequestReader $requestReader
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $customerId = $this->requestReader->getCustomerId($context);
        $sku = $this->requestReader->getSku($args['input'] ?? null);
        // ProductAlertType is a schema enum, so GraphQL has already rejected anything else.
        $alertType = (string) $args['input']['alert_type'];

        $this->manager->unsubscribe($customerId, $sku, $alertType, $context->getExtensionAttributes()->getStore());

        // `product_alerts` (the full list) is resolved by CustomerProductAlerts
        // off the request context, and only when the client selects it.
        return [
            'product_sku' => $sku,
            'alert_type' => $alertType,
            'is_subscribed' => false,
        ];
    }
}
