<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\ProductAlertGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Shared customer gate and input parsing for the product alert resolvers.
 */
class RequestReader
{
    /**
     * Return the logged-in customer's id, or throw for guests.
     *
     * @param ContextInterface $context
     * @return int
     * @throws GraphQlAuthorizationException
     */
    public function getCustomerId(ContextInterface $context): int
    {
        if (false === $context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(
                __('The current customer isn\'t authorized.')
            );
        }

        return (int) $context->getUserId();
    }

    /**
     * Read and validate a required, trimmed `product_sku` value.
     *
     * @param array|null $input
     * @return string
     * @throws GraphQlInputException
     */
    public function getSku(?array $input): string
    {
        $sku = isset($input['product_sku']) ? trim((string) $input['product_sku']) : '';
        if ($sku === '') {
            throw new GraphQlInputException(__('"product_sku" is required.'));
        }

        return $sku;
    }
}
