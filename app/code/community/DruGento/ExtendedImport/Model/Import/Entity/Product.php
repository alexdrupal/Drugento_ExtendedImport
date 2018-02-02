<?php 

/**
 * DruGento_ExtendedImport_Model_Import_Entity_Product
 **/
class DruGento_ExtendedImport_Model_Import_Entity_Product extends Mage_ImportExport_Model_Import_Entity_Product
{
  
    /**
     * Gather and save information about product entities.
     *
     * @return Mage_ImportExport_Model_Import_Entity_Product
     */
    protected function _saveProducts()
    {
        /** @var $resource Mage_ImportExport_Model_Import_Proxy_Product_Resource */
        $resource       = Mage::getModel('importexport/import_proxy_product_resource');
        $priceIsGlobal  = Mage::helper('catalog')->isPriceGlobal();
        $strftimeFormat = Varien_Date::convertZendToStrftime(Varien_Date::DATETIME_INTERNAL_FORMAT, true, true);
        $productLimit   = null;
        $productsQty    = null;

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entityRowsIn = array();
            $entityRowsUp = array();
            $attributes   = array();
            $websites     = array();
            $categories   = array();
            $tierPrices   = array();
            $groupPrices  = array();
            $mediaGallery = array();
            $uploadedGalleryFiles = array();
            $downloadedImageUrls = array();
            $previousType = null;
            $previousAttributeSet = null;

            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->validateRow($rowData, $rowNum)) {
                    continue;
                }
                $rowScope = $this->getRowScope($rowData);

                if (self::SCOPE_DEFAULT == $rowScope) {
                    $rowSku = $rowData[self::COL_SKU];

                    // 1. Entity phase
                    if (isset($this->_oldSku[$rowSku])) { // existing row
                        $entityRowsUp[] = array(
                            'updated_at' => now(),
                            'entity_id'  => $this->_oldSku[$rowSku]['entity_id']
                        );
                    } else { // new row
                        if (!$productLimit || $productsQty < $productLimit) {
                            $entityRowsIn[$rowSku] = array(
                                'entity_type_id'   => $this->_entityTypeId,
                                'attribute_set_id' => $this->_newSku[$rowSku]['attr_set_id'],
                                'type_id'          => $this->_newSku[$rowSku]['type_id'],
                                'sku'              => $rowSku,
                                'created_at'       => now(),
                                'updated_at'       => now()
                            );
                            $productsQty++;
                        } else {
                            $rowSku = null; // sign for child rows to be skipped
                            $this->_rowsToSkip[$rowNum] = true;
                            continue;
                        }
                    }
                } elseif (null === $rowSku) {
                    $this->_rowsToSkip[$rowNum] = true;
                    continue; // skip rows when SKU is NULL
                } elseif (self::SCOPE_STORE == $rowScope) { // set necessary data from SCOPE_DEFAULT row
                    $rowData[self::COL_TYPE]     = $this->_newSku[$rowSku]['type_id'];
                    $rowData['attribute_set_id'] = $this->_newSku[$rowSku]['attr_set_id'];
                    $rowData[self::COL_ATTR_SET] = $this->_newSku[$rowSku]['attr_set_code'];
                }
                if (!empty($rowData['_product_websites'])) { // 2. Product-to-Website phase
                    $websites[$rowSku][$this->_websiteCodeToId[$rowData['_product_websites']]] = true;
                }

                // 3. Categories phase
                $categoryPath = empty($rowData[self::COL_CATEGORY]) ? '' : $rowData[self::COL_CATEGORY];
                if (!empty($rowData[self::COL_ROOT_CATEGORY])) {
                    $categoryId = $this->_categoriesWithRoots[$rowData[self::COL_ROOT_CATEGORY]][$categoryPath];
                    $categories[$rowSku][$categoryId] = true;
                } elseif (!empty($categoryPath)) {
                    $categories[$rowSku][$this->_categories[$categoryPath]] = true;
                }

                if (!empty($rowData['_tier_price_website'])) { // 4.1. Tier prices phase
                    $tierPrices[$rowSku][] = array(
                        'all_groups'        => $rowData['_tier_price_customer_group'] == self::VALUE_ALL,
                        'customer_group_id' => ($rowData['_tier_price_customer_group'] == self::VALUE_ALL)
                            ? 0 : $rowData['_tier_price_customer_group'],
                        'qty'               => $rowData['_tier_price_qty'],
                        'value'             => $rowData['_tier_price_price'],
                        'website_id'        => (self::VALUE_ALL == $rowData['_tier_price_website'] || $priceIsGlobal)
                            ? 0 : $this->_websiteCodeToId[$rowData['_tier_price_website']]
                    );
                }
                if (!empty($rowData['_group_price_website'])) { // 4.2. Group prices phase
                    $groupPrices[$rowSku][] = array(
                        'all_groups'        => $rowData['_group_price_customer_group'] == self::VALUE_ALL,
                        'customer_group_id' => ($rowData['_group_price_customer_group'] == self::VALUE_ALL)
                            ? 0 : $rowData['_group_price_customer_group'],
                        'value'             => $rowData['_group_price_price'],
                        'website_id'        => (self::VALUE_ALL == $rowData['_group_price_website'] || $priceIsGlobal)
                            ? 0 : $this->_websiteCodeToId[$rowData['_group_price_website']]
                    );
                }
                foreach ($this->_imagesArrayKeys as $imageCol) {
                    if (!empty($rowData[$imageCol])) { // 5. Media gallery phase
                        if (strpos($rowData[$imageCol], 'http') !== FALSE) {
                            if (!isset($downloadedImageUrls[$rowData[$imageCol]])) {
                               $downloadedImageUrls[$rowData[$imageCol]] = $this->_uploadAndGetName($rowData[$imageCol]);
                            }
                            $rowData[$imageCol] = $downloadedImageUrls[$rowData[$imageCol]];
                        }
                        if (!array_key_exists($rowData[$imageCol], $uploadedGalleryFiles)) {
                            $uploadedGalleryFiles[$rowData[$imageCol]] = $this->_uploadMediaFiles($rowData[$imageCol]);
                        }
                        $rowData[$imageCol] = $uploadedGalleryFiles[$rowData[$imageCol]];
                    }
                }
                if (!empty($rowData['_media_image'])) {
                    $mediaGallery[$rowSku][] = array(
                        'attribute_id'      => $rowData['_media_attribute_id'],
                        'label'             => $rowData['_media_lable'],
                        'position'          => $rowData['_media_position'],
                        'disabled'          => $rowData['_media_is_disabled'],
                        'value'             => $rowData['_media_image']
                    );
                }
                // 6. Attributes phase
                $rowStore     = self::SCOPE_STORE == $rowScope ? $this->_storeCodeToId[$rowData[self::COL_STORE]] : 0;
                $productType  = $rowData[self::COL_TYPE];
                if(!is_null($rowData[self::COL_TYPE])) {
                    $previousType = $rowData[self::COL_TYPE];
                }
                if(!is_null($rowData[self::COL_ATTR_SET])) {
                    $previousAttributeSet = $rowData[Mage_ImportExport_Model_Import_Entity_Product::COL_ATTR_SET];
                }
                if (self::SCOPE_NULL == $rowScope) {
                    // for multiselect attributes only
                    if(!is_null($previousAttributeSet)) {
                        $rowData[Mage_ImportExport_Model_Import_Entity_Product::COL_ATTR_SET] = $previousAttributeSet;
                    }
                    if(is_null($productType) && !is_null($previousType)) {
                        $productType = $previousType;
                    }
                    if(is_null($productType)) {
                        continue;
                    }
                }
                $rowData      = $this->_productTypeModels[$productType]->prepareAttributesForSave($rowData);
                $product      = Mage::getModel('importexport/import_proxy_product', $rowData);

                foreach ($rowData as $attrCode => $attrValue) {
                    $attribute = $resource->getAttribute($attrCode);
                    if('multiselect' != $attribute->getFrontendInput()
                        && self::SCOPE_NULL == $rowScope) {
                        continue; // skip attribute processing for SCOPE_NULL rows
                    }
                    $attrId    = $attribute->getId();
                    $backModel = $attribute->getBackendModel();
                    $attrTable = $attribute->getBackend()->getTable();
                    $storeIds  = array(0);

                    if ('datetime' == $attribute->getBackendType() && strtotime($attrValue)) {
                        $attrValue = gmstrftime($strftimeFormat, strtotime($attrValue));
                    } elseif ($backModel) {
                        $attribute->getBackend()->beforeSave($product);
                        $attrValue = $product->getData($attribute->getAttributeCode());
                    }
                    if (self::SCOPE_STORE == $rowScope) {
                        if (self::SCOPE_WEBSITE == $attribute->getIsGlobal()) {
                            // check website defaults already set
                            if (!isset($attributes[$attrTable][$rowSku][$attrId][$rowStore])) {
                                $storeIds = $this->_storeIdToWebsiteStoreIds[$rowStore];
                            }
                        } elseif (self::SCOPE_STORE == $attribute->getIsGlobal()) {
                            $storeIds = array($rowStore);
                        }
                    }
                    foreach ($storeIds as $storeId) {
                        if('multiselect' == $attribute->getFrontendInput()) {
                            if(!isset($attributes[$attrTable][$rowSku][$attrId][$storeId])) {
                                $attributes[$attrTable][$rowSku][$attrId][$storeId] = '';
                            } else {
                                $attributes[$attrTable][$rowSku][$attrId][$storeId] .= ',';
                            }
                            $attributes[$attrTable][$rowSku][$attrId][$storeId] .= $attrValue;
                        } else {
                            $attributes[$attrTable][$rowSku][$attrId][$storeId] = $attrValue;
                        }
                    }
                    $attribute->setBackendModel($backModel); // restore 'backend_model' to avoid 'default' setting
                }
            }
            $this->_saveProductEntity($entityRowsIn, $entityRowsUp)
                ->_saveProductWebsites($websites)
                ->_saveProductCategories($categories)
                ->_saveProductTierPrices($tierPrices)
                ->_saveProductGroupPrices($groupPrices)
                ->_saveMediaGallery($mediaGallery)
                ->_saveProductAttributes($attributes);
        }
        return $this;
    }

    private function _uploadAndGetName($url)
    {
        $paths = pathinfo($url);
        $fileName = $paths['basename'];
        $fileName = Varien_File_Uploader::getCorrectFileName($fileName);
        $destinationFile = Mage::getConfig()->getOptions()->getMediaDir() . '/import';
        $this->_createDestinationFolder($destinationFile);

        $dispretionPath = Varien_File_Uploader::getDispretionPath($fileName);
        $destinationFile .= $dispretionPath;
        $this->_createDestinationFolder($destinationFile);

        $fileName = Varien_File_Uploader::getNewFileName($this->_addDirSeparator($destinationFile) . $fileName);
        $destinationFile = $this->_addDirSeparator($destinationFile);
        if (!file_exists($destinationFile . $fileName)) {
            $image = file_get_contents($url);
            file_put_contents($destinationFile.$fileName, $image);
        }

        return $dispretionPath . DS . $fileName;
    }

    private function _createDestinationFolder($destinationFolder)
    {
        if (!$destinationFolder) {
            return $this;
        }

        if (substr($destinationFolder, -1) == DIRECTORY_SEPARATOR) {
            $destinationFolder = substr($destinationFolder, 0, -1);
        }

        if (!(@is_dir($destinationFolder) || @mkdir($destinationFolder, 0750, true))) {
            throw new Exception("Unable to create directory '{$destinationFolder}'.");
        }
        return $this;
    }

    private function _addDirSeparator($dir)
    {
        if (substr($dir,-1) != DIRECTORY_SEPARATOR) {
            $dir.= DIRECTORY_SEPARATOR;
        }
        return $dir;
    }
}
