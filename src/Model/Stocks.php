<?php
/**
 * ScandiPWA - Progressive Web App for Magento
 *
 * Copyright © Scandiweb, Inc. All rights reserved.
 * See LICENSE for license details.
 *
 * @license OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * @package scandipwa/base-theme
 * @link https://github.com/scandipwa/base-theme
 */

declare(strict_types=1);

namespace ScandiPWA\Inventory\Model;

use Magento\CatalogInventory\Model\Configuration;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite;
use ScandiPWA\Performance\Model\Resolver\Products\DataPostProcessor\Stocks as BaseStocks;

/**
 * Class Images
 * @package ScandiPWA\Inventory\Model
 */
class Stocks extends BaseStocks
{
    /**
     * @var GetProductSalableQtyInterface
     */
    protected $getSalableQuantityDataBySku;

    /**
     * @var GetStockIdForCurrentWebsite
     */
    protected $getStockIdForCurrentWebsite;

    /**
     * Stocks constructor.
     * @param SourceItemRepositoryInterface $stockRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        SourceItemRepositoryInterface $stockRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ScopeConfigInterface $scopeConfig,
        GetProductSalableQtyInterface $getSalableQuantityDataBySku,
        GetStockIdForCurrentWebsite $getStockIdForCurrentWebsite
    ) {
        parent::__construct(
            $stockRepository,
            $searchCriteriaBuilder,
            $scopeConfig
        );

        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockRepository = $stockRepository;
        $this->scopeConfig = $scopeConfig;
        $this->getSalableQuantityDataBySku = $getSalableQuantityDataBySku;
        $this->getStockIdForCurrentWebsite = $getStockIdForCurrentWebsite;
    }

    /**
     * @inheritDoc
     */
    public function process(
        array $products,
        string $graphqlResolvePath,
        $graphqlResolveInfo,
        ?array $processorOptions = []
    ): callable {
        $productStocks = [];

        $fields = $this->getFieldsFromProductInfo(
            $graphqlResolveInfo,
            $graphqlResolvePath
        );

        if (!count($fields)) {
            return function (&$productData) {
            };
        }

        $productSKUs = array_map(function ($product) {
            return $product->getSku();
        }, $products);

        $thresholdQty = 0;

        if (in_array(self::ONLY_X_LEFT_IN_STOCK, $fields)) {
            $thresholdQty = (float) $this->scopeConfig->getValue(
                Configuration::XML_PATH_STOCK_THRESHOLD_QTY,
                ScopeInterface::SCOPE_STORE
            );
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(SourceItemInterface::SKU, $productSKUs, 'in')
            ->create();

        $stockItems = $this->stockRepository->getList($criteria)->getItems();

        if (!count($stockItems)) {
            return function (&$productData) {
            };
        }

        $formattedStocks = [];

        foreach ($stockItems as $stockItem) {
            // Added next fields to get stock status depending on salable qty of product
            try {
                $stockId = $this->getStockIdForCurrentWebsite->execute();
                $inStock = $this->getSalableQuantityDataBySku->execute($stockItem->getSku(), $stockId) > 0;
            } catch (\Exception $e) {
                $inStock = $stockItem->getStatus() === SourceItemInterface::STATUS_IN_STOCK;
            }

            $leftInStock = null;
            $qty = $stockItem->getQuantity();

            if ($thresholdQty !== (float) 0) {
                $isThresholdPassed = $qty <= $thresholdQty;
                $leftInStock = $isThresholdPassed ? $qty : null;
            }

            $formattedStocks[$stockItem->getSku()] = [
                self::STOCK_STATUS => $inStock ? self::IN_STOCK : self::OUT_OF_STOCK,
                self::ONLY_X_LEFT_IN_STOCK => $leftInStock
            ];
        }

        foreach ($products as $product) {
            $productId = $product->getId();
            $productSku = $product->getSku();

            if (isset($formattedStocks[$productSku])) {
                $productStocks[$productId] = $formattedStocks[$productSku];
            }
        }

        return function (&$productData) use ($productStocks) {
            if (!isset($productData['entity_id'])) {
                return;
            }

            $productId = $productData['entity_id'];

            if (!isset($productStocks[$productId])) {
                return;
            }

            foreach ($productStocks[$productId] as $stockType => $stockData) {
                $productData[$stockType] = $stockData;
            }
        };
    }
}
