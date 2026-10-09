<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\ProductAlertGraphQl\Model\Resolver;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves the `product` field on a ProductAlertEntry.
 *
 * ProductAlertManager::getSubscriptions() batch-loads the products and passes
 * each one under `model`; this returns its data with the model attached, which
 * is the contract the CatalogGraphQl ProductInterface field resolvers (name,
 * sku, price_range, image, …) read from. Null when the product was deleted.
 */
class ProductAlertProduct implements ResolverInterface
{
    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $product = $value['model'] ?? null;
        if (!$product instanceof ProductInterface) {
            return null;
        }

        $productData = $product->getData();
        $productData['model'] = $product;

        return $productData;
    }
}
