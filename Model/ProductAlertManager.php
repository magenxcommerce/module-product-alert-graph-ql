<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\ProductAlertGraphQl\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\ProductAlert\Model\Observer;
use Magento\ProductAlert\Model\PriceFactory;
use Magento\ProductAlert\Model\ResourceModel\Price\CollectionFactory as PriceCollectionFactory;
use Magento\ProductAlert\Model\ResourceModel\Stock\CollectionFactory as StockCollectionFactory;
use Magento\ProductAlert\Model\StockFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magenx\ProductAlertGraphQl\Model\ResourceModel\Alert as AlertResource;

/**
 * Subscribe, unsubscribe and read Product Alert subscriptions for a customer.
 *
 * Wraps the legacy Magento_ProductAlert price/stock tables. The hot paths (the
 * product page status lookup and the subscribe/unsubscribe toggles) resolve the
 * SKU with a single indexed query (ResourceModel\Alert) and never load the
 * full EAV product, except for a price subscription, which has to record the
 * product's final price. Writes go through the core alert models.
 */
class ProductAlertManager
{
    public const ALERT_TYPE_PRICE = 'PRICE';
    public const ALERT_TYPE_STOCK = 'STOCK';

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ProductCollectionFactory $productCollectionFactory
     * @param PriceFactory $priceFactory
     * @param StockFactory $stockFactory
     * @param PriceCollectionFactory $priceCollectionFactory
     * @param StockCollectionFactory $stockCollectionFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param AlertResource $alertResource
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly PriceFactory $priceFactory,
        private readonly StockFactory $stockFactory,
        private readonly PriceCollectionFactory $priceCollectionFactory,
        private readonly StockCollectionFactory $stockCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AlertResource $alertResource
    ) {
    }

    /**
     * Subscribe the customer to a price or stock alert for the given product SKU.
     *
     * Idempotent: the core resource models reuse an existing row for the same
     * customer/product/website/store instead of inserting a duplicate.
     *
     * @param int $customerId
     * @param string $sku
     * @param string $alertType
     * @param StoreInterface $store
     * @return void
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    public function subscribe(int $customerId, string $sku, string $alertType, StoreInterface $store): void
    {
        $this->assertAllowed($alertType, $store);
        $productId = $this->alertResource->getProductIdBySku($sku, (int) $store->getWebsiteId());
        if ($productId === null) {
            throw $this->productNotFound();
        }

        if ($alertType === self::ALERT_TYPE_PRICE) {
            $model = $this->priceFactory->create()
                ->setPrice($this->getFinalPrice($productId, (int) $store->getId()));
        } else {
            $model = $this->stockFactory->create();
        }

        $model->setCustomerId($customerId)
            ->setProductId($productId)
            ->setWebsiteId((int) $store->getWebsiteId())
            ->setStoreId((int) $store->getId());

        try {
            $model->save();
        } catch (\Exception $e) {
            throw new GraphQlInputException(
                __("The alert subscription couldn't be saved. Please try again later."),
                $e
            );
        }
    }

    /**
     * Remove a customer's price or stock alert subscription for the given SKU.
     *
     * Deletes every row for the product in the current website (the same scope
     * the subscription list and status lookup read). Rows are deleted through
     * the core alert models so `*_delete_before/after` events and resource
     * model plugins fire. Removing an alert that doesn't exist is a no-op.
     *
     * @param int $customerId
     * @param string $sku
     * @param string $alertType
     * @param StoreInterface $store
     * @return void
     * @throws GraphQlInputException
     */
    public function unsubscribe(int $customerId, string $sku, string $alertType, StoreInterface $store): void
    {
        // Not restricted to products still assigned to the website: an alert
        // for a product removed from the catalog must stay removable.
        $productId = $this->alertResource->getProductIdBySku($sku);
        if ($productId === null) {
            return;
        }

        $collection = $alertType === self::ALERT_TYPE_PRICE
            ? $this->priceCollectionFactory->create()
            : $this->stockCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('product_id', $productId)
            ->addFieldToFilter('website_id', (int) $store->getWebsiteId());

        try {
            foreach ($collection as $alert) {
                $alert->delete();
            }
        } catch (\Exception $e) {
            throw new GraphQlInputException(
                __("The alert subscription couldn't be removed. Please try again later."),
                $e
            );
        }
    }

    /**
     * Which alert types the customer is subscribed to for a product SKU.
     *
     * One SKU lookup plus one UNION query over both alert tables. An unknown
     * SKU, or one not assigned to the store's website, reads as unsubscribed.
     *
     * @param int $customerId
     * @param string $sku
     * @param StoreInterface $store
     * @return array<string, bool> Keyed by ALERT_TYPE_PRICE / ALERT_TYPE_STOCK
     */
    public function getStatus(int $customerId, string $sku, StoreInterface $store): array
    {
        $status = [self::ALERT_TYPE_PRICE => false, self::ALERT_TYPE_STOCK => false];

        $websiteId = (int) $store->getWebsiteId();
        $productId = $this->alertResource->getProductIdBySku($sku, $websiteId);
        if ($productId === null) {
            return $status;
        }

        foreach ($this->alertResource->getSubscribedTypes($customerId, $productId, $websiteId) as $type) {
            $status[$type] = true;
        }

        return $status;
    }

    /**
     * Return a customer's alert subscriptions of the given type as plain arrays
     * (shaped for the ProductAlertEntry GraphQL type).
     *
     * Products are loaded in one collection per store and handed on under
     * `model`, so neither `current_price` nor the entry's `product` field load
     * products one by one.
     *
     * @param int $customerId
     * @param string $alertType
     * @param int $websiteId
     * @return array<int, array<string, mixed>>
     */
    public function getSubscriptions(int $customerId, string $alertType, int $websiteId): array
    {
        $collection = $alertType === self::ALERT_TYPE_PRICE
            ? $this->priceCollectionFactory->create()
            : $this->stockCollectionFactory->create();

        $collection->addFieldToFilter('customer_id', $customerId);
        if ($websiteId) {
            $collection->addFieldToFilter('website_id', $websiteId);
        }
        $collection->setOrder('add_date', 'DESC');

        $alerts = $collection->getItems();
        $productIdsByStore = [];
        foreach ($alerts as $alert) {
            $productIdsByStore[(int) $alert->getStoreId()][] = (int) $alert->getProductId();
        }
        $products = $this->loadProducts($productIdsByStore);

        $isPriceAlert = $alertType === self::ALERT_TYPE_PRICE;
        $entries = [];
        foreach ($alerts as $alert) {
            $productId = (int) $alert->getProductId();
            $storeId = (int) $alert->getStoreId();
            $product = $products[$storeId][$productId] ?? null;
            $currentPrice = $isPriceAlert && $product !== null ? (float) $product->getFinalPrice() : null;
            $recordedPrice = $isPriceAlert ? (float) $alert->getPrice() : null;

            $entries[] = [
                'id' => (int) $alert->getId(),
                'alert_type' => $alertType,
                'model' => $product,
                'price' => $recordedPrice,
                'current_price' => $currentPrice,
                'price_diff' => $currentPrice !== null ? $recordedPrice - $currentPrice : null,
                'add_date' => (string) $alert->getAddDate(),
                'status' => (int) $alert->getStatus() === 1 ? 'SENT' : 'ACTIVE',
                'status_changed_at' => $this->normalizeDate(
                    (string) ($isPriceAlert ? $alert->getLastSendDate() : $alert->getSendDate())
                ),
            ];
        }

        return $entries;
    }

    /**
     * Load the given products, one collection per store.
     *
     * @param array<int, int[]> $productIdsByStore
     * @return array<int, array<int, ProductInterface>> Keyed by store id, then product id
     */
    private function loadProducts(array $productIdsByStore): array
    {
        $products = [];
        foreach ($productIdsByStore as $storeId => $productIds) {
            $collection = $this->productCollectionFactory->create()
                ->setStoreId($storeId)
                ->addIdFilter(array_unique($productIds))
                ->addAttributeToSelect('*');

            foreach ($collection as $product) {
                $products[$storeId][(int) $product->getId()] = $product;
            }
        }

        return $products;
    }

    /**
     * Current final price of a product in the store.
     *
     * @param int $productId
     * @param int $storeId
     * @return float
     * @throws GraphQlNoSuchEntityException
     */
    private function getFinalPrice(int $productId, int $storeId): float
    {
        try {
            return (float) $this->productRepository->getById($productId, false, $storeId)->getFinalPrice();
        } catch (NoSuchEntityException $e) {
            throw $this->productNotFound($e);
        }
    }

    /**
     * Normalize a possibly empty/zero timestamp into a nullable date string.
     *
     * @param string $date
     * @return string|null
     */
    private function normalizeDate(string $date): ?string
    {
        return $date === '' || $date === '0000-00-00 00:00:00' ? null : $date;
    }

    /**
     * The error for a SKU that doesn't exist or isn't sold on this website.
     *
     * @param \Exception|null $cause
     * @return GraphQlNoSuchEntityException
     */
    private function productNotFound(?\Exception $cause = null): GraphQlNoSuchEntityException
    {
        return new GraphQlNoSuchEntityException(
            __('The product that was requested doesn\'t exist. Verify the product and try again.'),
            $cause
        );
    }

    /**
     * Throw if the alert type is disabled for the store.
     *
     * @param string $alertType
     * @param StoreInterface $store
     * @return void
     * @throws GraphQlInputException
     */
    private function assertAllowed(string $alertType, StoreInterface $store): void
    {
        $path = $alertType === self::ALERT_TYPE_PRICE
            ? Observer::XML_PATH_PRICE_ALLOW
            : Observer::XML_PATH_STOCK_ALLOW;

        if (!$this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $store->getId())) {
            throw new GraphQlInputException(
                $alertType === self::ALERT_TYPE_PRICE
                    ? __('Price alerts are not enabled for this store.')
                    : __('Stock alerts are not enabled for this store.')
            );
        }
    }
}
