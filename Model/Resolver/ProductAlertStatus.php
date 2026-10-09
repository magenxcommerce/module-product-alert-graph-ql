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
 * Resolver for the `productAlertStatus` query.
 *
 * Drives the product page subscribe buttons with two small SQL queries instead
 * of a `products` search.
 */
class ProductAlertStatus implements ResolverInterface
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
        $sku = $this->requestReader->getSku($args);

        $status = $this->manager->getStatus($customerId, $sku, $context->getExtensionAttributes()->getStore());

        return [
            'product_sku' => $sku,
            'is_price_alert_subscribed' => $status[ProductAlertManager::ALERT_TYPE_PRICE],
            'is_stock_alert_subscribed' => $status[ProductAlertManager::ALERT_TYPE_STOCK],
        ];
    }
}
