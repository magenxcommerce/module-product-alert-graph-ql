<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\ProductAlertGraphQl\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\ProductAlert\Model\Observer;
use Magento\ProductAlert\Model\PriceFactory;
use Magento\ProductAlert\Model\ResourceModel\Price\CollectionFactory as PriceCollectionFactory;
use Magento\ProductAlert\Model\ResourceModel\Stock\CollectionFactory as StockCollectionFactory;
use Magento\ProductAlert\Model\StockFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Subscribe, unsubscribe and read Product Alert subscriptions for a customer.
 *
 * Wraps the legacy Magento_ProductAlert price/stock models so the GraphQL
 * resolvers stay thin and mirror exactly what the storefront controllers
 * (Controller/Add/{Price,Stock}, Controller/Unsubscribe/{Price,Stock}) do.
 */
class ProductAlertManager
{
    public const ALERT_TYPE_PRICE = 'PRICE';
    public const ALERT_TYPE_STOCK = 'STOCK';

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param PriceFactory $priceFactory
     * @param StockFactory $stockFactory
     * @param PriceCollectionFactory $priceCollectionFactory
     * @param StockCollectionFactory $stockCollectionFactory
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PriceFactory $priceFactory,
        private readonly StockFactory $stockFactory,
        private readonly PriceCollectionFactory $priceCollectionFactory,
        private readonly StockCollectionFactory $stockCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the given alert type is enabled for the store.
     *
     * @param string $alertType
     * @param StoreInterface $store
     * @return bool
     */
    public function isAllowed(string $alertType, StoreInterface $store): bool
    {
        $path = $alertType === self::ALERT_TYPE_PRICE
            ? Observer::XML_PATH_PRICE_ALLOW
            : Observer::XML_PATH_STOCK_ALLOW;

        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $store->getId());
    }

    /**
     * Subscribe the customer to a price or stock alert for the given product SKU.
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
        $product = $this->getProduct($sku, $store);

        if ($alertType === self::ALERT_TYPE_PRICE) {
            $model = $this->priceFactory->create()
                ->setCustomerId($customerId)
                ->setProductId((int) $product->getId())
                ->setPrice((float) $product->getFinalPrice())
                ->setWebsiteId((int) $store->getWebsiteId())
                ->setStoreId((int) $store->getId());
        } else {
            $model = $this->stockFactory->create()
                ->setCustomerId($customerId)
                ->setProductId((int) $product->getId())
                ->setWebsiteId((int) $store->getWebsiteId())
                ->setStoreId((int) $store->getId());
        }

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
     * @param int $customerId
     * @param string $sku
     * @param string $alertType
     * @param StoreInterface $store
     * @return void
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    public function unsubscribe(int $customerId, string $sku, string $alertType, StoreInterface $store): void
    {
        $product = $this->getProduct($sku, $store);

        $model = $alertType === self::ALERT_TYPE_PRICE
            ? $this->priceFactory->create()
            : $this->stockFactory->create();

        $model->setCustomerId($customerId)
            ->setProductId((int) $product->getId())
            ->setWebsiteId((int) $store->getWebsiteId())
            ->setStoreId((int) $store->getId())
            ->loadByParam();

        if (!$model->getId()) {
            return;
        }

        try {
            $model->delete();
        } catch (\Exception $e) {
            throw new GraphQlInputException(
                __("The alert subscription couldn't be removed. Please try again later."),
                $e
            );
        }
    }

    /**
     * Whether the customer is subscribed to the given alert type for the product.
     *
     * @param int $customerId
     * @param int $productId
     * @param string $alertType
     * @param StoreInterface $store
     * @return bool
     */
    public function isSubscribed(int $customerId, int $productId, string $alertType, StoreInterface $store): bool
    {
        $model = $alertType === self::ALERT_TYPE_PRICE
            ? $this->priceFactory->create()
            : $this->stockFactory->create();

        $model->setCustomerId($customerId)
            ->setProductId($productId)
            ->setWebsiteId((int) $store->getWebsiteId())
            ->setStoreId((int) $store->getId())
            ->loadByParam();

        return (bool) $model->getId();
    }

    /**
     * Return a customer's alert subscriptions of the given type as plain arrays
     * (shaped for the ProductAlertEntry GraphQL type).
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

        $entries = [];
        foreach ($collection as $alert) {
            $productId = (int) $alert->getProductId();
            $storeId = (int) $alert->getStoreId();
            $isPriceAlert = $alertType === self::ALERT_TYPE_PRICE;
            $currentPrice = $isPriceAlert ? $this->getCurrentPrice($productId, $storeId) : null;
            $recordedPrice = $isPriceAlert ? (float) $alert->getPrice() : null;

            $entries[] = [
                'id' => (int) $alert->getId(),
                'alert_type' => $alertType,
                'product_id' => $productId,
                'store_id' => $storeId,
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
     * Current final price of a product, or null if it can no longer be loaded.
     *
     * @param int $productId
     * @param int $storeId
     * @return float|null
     */
    private function getCurrentPrice(int $productId, int $storeId): ?float
    {
        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        return (float) $product->getFinalPrice();
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
     * Resolve a product model by SKU, scoped to the store.
     *
     * @param string $sku
     * @param StoreInterface $store
     * @return ProductInterface
     * @throws GraphQlNoSuchEntityException
     */
    private function getProduct(string $sku, StoreInterface $store): ProductInterface
    {
        try {
            return $this->productRepository->get($sku, false, (int) $store->getId());
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(
                __('The product that was requested doesn\'t exist. Verify the product and try again.'),
                $e
            );
        }
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
        if (!$this->isAllowed($alertType, $store)) {
            throw new GraphQlInputException(
                $alertType === self::ALERT_TYPE_PRICE
                    ? __('Price alerts are not enabled for this store.')
                    : __('Stock alerts are not enabled for this store.')
            );
        }
    }
}
