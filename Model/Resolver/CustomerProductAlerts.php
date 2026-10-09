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
 * Resolves the customer's product alert subscriptions (price + stock).
 *
 * Used both by `Customer.product_alerts` and the mutation output's
 * `product_alerts` field — it always reads the customer from the request
 * context, so the parent value is irrelevant.
 */
class CustomerProductAlerts implements ResolverInterface
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
        $store = $context->getExtensionAttributes()->getStore();
        $websiteId = (int) $store->getWebsiteId();

        return [
            'price_alerts' => $this->manager->getSubscriptions(
                $customerId,
                ProductAlertManager::ALERT_TYPE_PRICE,
                $websiteId
            ),
            'stock_alerts' => $this->manager->getSubscriptions(
                $customerId,
                ProductAlertManager::ALERT_TYPE_STOCK,
                $websiteId
            ),
        ];
    }
}
