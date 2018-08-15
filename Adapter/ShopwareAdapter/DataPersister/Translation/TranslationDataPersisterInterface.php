<?php

namespace ShopwareAdapter\DataPersister\Translation;

use PlentyConnector\Connector\TransferObject\Product\Product;
use PlentyConnector\Connector\TransferObject\Media\Media;


/**
 * Interface TranslationDataPersisterInterface
 */
interface TranslationDataPersisterInterface
{
    /**
     * @param Product $product
     */
    public function writeProductTranslations(Product $product);

    /**
     * @param Media $media
     */
    public function writeMediaTranslations(Media $media);
}
