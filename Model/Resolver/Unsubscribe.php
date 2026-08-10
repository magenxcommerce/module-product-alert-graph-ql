<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\ProductAlertGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
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
        if (false === $context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(
                __('The current customer isn\'t authorized.')
            );
        }

        $sku = isset($args['input']['product_sku']) ? trim((string) $args['input']['product_sku']) : '';
        $alertType = (string) ($args['input']['alert_type'] ?? '');
        if ($sku === '') {
            throw new GraphQlInputException(__('"product_sku" is required.'));
        }

        $store = $context->getExtensionAttributes()->getStore();
        $customerId = (int) $context->getUserId();

        $this->manager->unsubscribe($customerId, $sku, $alertType, $store);

        return [];
    }
}
