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

namespace ScandiPWA\Inventory\Plugin;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\ConfigurableProduct\Api\LinkManagementInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;

/*
 * This Plugin is used to set correct stock status for products in order
 */
class ChangeStockStatusAfterOrderPlacementPlugin {
    /**
     * @var GetProductSalableQtyInterface
     */
    protected $getSalableQuantityDataBySku;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var Configurable
     */
    protected $configurableProductType;

    /**
     * @var LinkManagementInterface
     */
    protected $linkManagment;

    /**
     * @var StockRegistryInterface
     */
    protected $stockRegistry;

    /**
     * @var GetStockIdForCurrentWebsite
     */
    protected $getStockIdForCurrentWebsite;

    /**
     * ChangeStockStatusAfterOrderPlacementPlugin constructor.
     * @param GetProductSalableQtyInterface $getSalableQuantityDataBySku
     * @param ProductRepositoryInterface $productRepository
     * @param Configurable $configurableProductType
     */
    public function __construct(
        GetProductSalableQtyInterface $getSalableQuantityDataBySku,
        ProductRepositoryInterface $productRepository,
        Configurable $configurableProductType,
        LinkManagementInterface $linkManagement,
        StockRegistryInterface $stockRegistry,
        GetStockIdForCurrentWebsite $getStockIdForCurrentWebsite
    ) {
        $this->getSalableQuantityDataBySku = $getSalableQuantityDataBySku;
        $this->productRepository = $productRepository;
        $this->configurableProductType = $configurableProductType;
        $this->linkManagment = $linkManagement;
        $this->stockRegistry = $stockRegistry;
        $this->getStockIdForCurrentWebsite = $getStockIdForCurrentWebsite;
    }


    /**
     * @param OrderManagementInterface $subject
     * @param OrderInterface $result
     * @return OrderInterface
     * @throws NoSuchEntityException
     * @throws InputException
     * @throws LocalizedException
     */
    public function afterPlace(
        OrderManagementInterface $subject,
        OrderInterface $result
    ) {
        foreach ($result->getItems() as $item) {
            $stockId = $this->getStockIdForCurrentWebsite->execute();
            $prevSalableQty = $this->getSalableQuantityDataBySku->execute($item->getSku(), $stockId);

            if ($prevSalableQty - $item->getQtyOrdered() <= 0) {
                $product = $this->productRepository->get($item->getSku());
                $product->setQuantityAndStockStatus(['is_in_stock' => false])->save();

                $parentProductId =  $this->configurableProductType->getParentIdsByChild($product->getId());

                if ($parentProductId) {
                    $this->handleConfigurableIsOutOfStock($parentProductId[0]);
                }
            }
        }

        return $result;
    }

    /**
     * @param $parentProductId
     * @throws NoSuchEntityException
     */
    public function handleConfigurableIsOutOfStock($parentProductId) {
        $parentProduct = $this->productRepository->getById($parentProductId);
        $childProducts = $this->linkManagment->getChildren($parentProduct->getSku());
        $isOutOfStock = true;

        foreach ($childProducts as $product) {
            if ($this->stockRegistry->getStockItem($product->getId())->getIsInStock()) {
                $isOutOfStock = false;
            }
        }

        if ($isOutOfStock) {
            $parentProduct->setStockData([ 'is_in_stock' => 0 ])->save();
        }
    }
}
