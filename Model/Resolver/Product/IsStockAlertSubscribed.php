<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\ProductAlertGraphQl\Model\Resolver\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magenx\ProductAlertGraphQl\Model\ProductAlertManager;

/**
 * Resolves ProductInterface.is_stock_alert_subscribed.
 *
 * Null for guests (the field is only meaningful for a logged-in customer).
 */
class IsStockAlertSubscribed implements ResolverInterface
{
    /**
     * @param ProductAlertManager $manager
     */
    public function __construct(
        private readonly ProductAlertManager $manager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        if (!isset($value['model']) || !$value['model'] instanceof ProductInterface) {
            throw new GraphQlInputException(__('"model" value should be specified.'));
        }

        if (false === $context->getExtensionAttributes()->getIsCustomer()) {
            return null;
        }

        /** @var ProductInterface $product */
        $product = $value['model'];

        return $this->manager->isSubscribed(
            (int) $context->getUserId(),
            (int) $product->getId(),
            ProductAlertManager::ALERT_TYPE_STOCK,
            $context->getExtensionAttributes()->getStore()
        );
    }
}
