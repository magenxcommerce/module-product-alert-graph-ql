<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\ProductAlertGraphQl\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magenx\ProductAlertGraphQl\Model\ProductAlertManager;

/**
 * Read-only lookups over the catalog and the Magento_ProductAlert tables.
 *
 * Plain indexed SELECTs for the product page hot path, so it never loads an
 * EAV product or an alert model just to answer a yes/no question. Writes go
 * through the core alert models (see ProductAlertManager) so their events fire.
 */
class Alert
{
    private const TABLE_PRICE = 'product_alert_price';
    private const TABLE_STOCK = 'product_alert_stock';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Resolve a SKU to a product id with one indexed query.
     *
     * When a website id is given, the product must also be assigned to it.
     * `catalog_product_website.product_id` holds the entity id on every edition.
     *
     * @param string $sku
     * @param int|null $websiteId
     * @return int|null
     */
    public function getProductIdBySku(string $sku, ?int $websiteId = null): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName('catalog_product_entity')], 'entity_id')
            ->where('e.sku = ?', $sku)
            ->limit(1);

        if ($websiteId !== null) {
            $select->join(
                ['pw' => $this->resourceConnection->getTableName('catalog_product_website')],
                'pw.product_id = e.entity_id',
                []
            )->where('pw.website_id = ?', $websiteId);
        }

        $productId = $connection->fetchOne($select);

        return $productId === false ? null : (int) $productId;
    }

    /**
     * Alert types the customer holds for a product in a website, in one UNION query.
     *
     * @param int $customerId
     * @param int $productId
     * @param int $websiteId
     * @return string[] ProductAlertManager::ALERT_TYPE_* values, possibly repeated
     */
    public function getSubscribedTypes(int $customerId, int $productId, int $websiteId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tables = [
            ProductAlertManager::ALERT_TYPE_PRICE => self::TABLE_PRICE,
            ProductAlertManager::ALERT_TYPE_STOCK => self::TABLE_STOCK,
        ];

        $selects = [];
        foreach ($tables as $alertType => $table) {
            $selects[] = $connection->select()
                ->from(
                    $this->resourceConnection->getTableName($table),
                    ['alert_type' => new Expression($connection->quote($alertType))]
                )
                ->where('customer_id = ?', $customerId)
                ->where('product_id = ?', $productId)
                ->where('website_id = ?', $websiteId);
        }

        return $connection->fetchCol($connection->select()->union($selects, Select::SQL_UNION_ALL));
    }
}
