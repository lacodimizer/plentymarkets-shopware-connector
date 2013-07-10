<?php
/**
 * plentymarkets shopware connector
 * Copyright © 2013 plentymarkets GmbH
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License, supplemented by an additional
 * permission, and of our proprietary license can be found
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "plentymarkets" is a registered trademark of plentymarkets GmbH.
 * "shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, titles and interests in the
 * above trademarks remain entirely with the trademark owners.
 *
 * @copyright  Copyright (c) 2013, plentymarkets GmbH (http://www.plentymarkets.com)
 * @author     Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */

require_once __DIR__ . '/../../Soap/Models/PlentySoapObject/Attribute.php';
require_once __DIR__ . '/../../Soap/Models/PlentySoapObject/AttributeValue.php';
require_once __DIR__ . '/../../Soap/Models/PlentySoapObject/AttributeValueSet.php';
require_once __DIR__ . '/../../Soap/Models/PlentySoapRequestObject/GetAttributeValueSets.php';
require_once __DIR__ . '/../../Soap/Models/PlentySoapRequest/GetAttributeValueSets.php';

function def($value, $default = null, $ref = 0)
{
	if ($value <= $ref)
	{
		return $default;
	}
	return $value;
}

require_once __DIR__ . '/../PlentymarketsVariantController.php';
require_once __DIR__ . '/PlentymarketsImportEntityItemPrice.php';
require_once __DIR__ . '/PlentymarketsImportEntityItemImage.php';

class PlentymarketsImportEntityItem
{

	/**
	 *
	 * @var PlentySoapObject_ItemBase
	 */
	protected $ItemBase;

	/**
	 * The main data
	 *
	 * @var array
	 */
	protected $data;

	/**
	 *
	 * @var array
	 */
	protected $details;

	/**
	 *
	 * @var unknown
	 */
	protected $variants = array();

	/**
	 *
	 * @var array
	 */
	protected $categories = array();

	/**
	 *
	 * @var integer
	 */
	static $numbersCreated = 0;

	/**
	 *
	 * @param PlentySoapObject_ItemBase $ItemBase
	 */
	public function __construct($ItemBase)
	{
		$this->ItemBase = $ItemBase;
	}

	/**
	 *
	 * @param integer $number
	 * @return boolean
	 */
	public static function itemNumberExists($number)
	{
		$detail = Shopware()->Models()
			->getRepository('Shopware\Models\Article\Detail')
			->findOneBy(array(
			'number' => $number
		));

		return !empty($detail);
	}

	/**
	 *
	 * @return string
	 */
	public static function getItemNumber()
	{
		$prefix = Shopware()->Config()->backendAutoOrderNumberPrefix;

		$sql = "SELECT number FROM s_order_number WHERE name = 'articleordernumber'";
		$number = Shopware()->Db()->fetchOne($sql);
		$number += self::$numbersCreated;

		do
		{
			++$number;
			++self::$numbersCreated;

			$sql = "SELECT id FROM s_articles_details WHERE ordernumber LIKE ?";
			$hit = Shopware()->Db()->fetchOne($sql, $prefix . $number);
		}
		while ($hit);

		Shopware()->Db()->query("UPDATE s_order_number SET number = ? WHERE name = 'articleordernumber'", array(
			$number
		));

		return $prefix . $number;
	}

	/**
	 *
	 * @param string $number
	 * @return string
	 */
	public static function getUsableNumber($number)
	{
		if (!empty($number) && !self::itemNumberExists($number))
		{
			return $number;
		}
		return self::getItemNumber();
	}

	/**
	 *
	 */
	protected function setData()
	{
		$this->data = array(
			'name' => $this->ItemBase->Texts->Name,
			'description' => $this->ItemBase->Texts->ShortDescription,
			'descriptionLong' => $this->ItemBase->Texts->LongDescription,
			'keywords' => $this->ItemBase->Texts->Keywords,
			'highlight' => ($this->ItemBase->WebShopSpecial == 3),
			'changed' => date('c', $this->ItemBase->LastUpdate),
			'availableTo' => null, // wird unten gesetzt
			'active' => $this->ItemBase->Availability->Inactive == 0,
			'taxId' => $this->getTaxId()
		);

		if ($this->ItemBase->Availability->AvailableUntil > 0)
		{
			$this->data['availableTo'] = date('c', $this->ItemBase->Availability->AvailableUntil);
		}

		try
		{
			$this->data['supplierId'] = PlentymarketsMappingController::getProducerByPlentyID($this->ItemBase->ProducerID);
		}
		catch (PlentymarketsMappingExceptionNotExistant $E)
		{
			if ($this->ItemBase->ProducerName)
			{
				$this->data['supplier'] = $this->ItemBase->ProducerName;
			}
			else
			{
				$this->data['supplierId'] = PlentymarketsConfig::getInstance()->getItemProducerID();
			}
		}
	}

	/**
	 *
	 */
	protected function setCategories()
	{
		$parentId = 1;
		$CategoryRepository = Shopware()->Models()->getRepository('Shopware\Models\Category\Category');

		// Kategorien
		foreach ($this->ItemBase->Categories->item as $Category)
		{
			$path = explode(';', $Category->ItemCategoryPath);
			try
			{
				$categoryID = PlentymarketsMappingController::getCategoryByPlentyID($Category->ItemCategoryPath);
				$this->categories[] = array(
					'id' => $categoryID
				);
			}

			// Kategorie ist noch nicht vorhanden
			catch (PlentymarketsMappingExceptionNotExistant $E)
			{
				// Prüfen, ob es ein Teilbaum ist..

				$categoryPathNames = explode(';', $Category->ItemCategoryPathNames);

				foreach ($categoryPathNames as $categoryName)
				{
					$CategoryFound = $CategoryRepository->findOneBy(array(
						'name' => $categoryName,
						'parentId' => $parentId
					));

					if ($CategoryFound instanceof \Shopware\Models\Category\Category)
					{
						$parentId = $CategoryFound->getId();
						$path[] = $parentId;
					}
					else
					{
						$params = array();
						$params['name'] = $categoryName;
						$params['parentId'] = $parentId;

						//todo: Shopware\Components\Api\Resource\Category;
						$CategoryModel = new \Shopware\Models\Category\Category();
						$CategoryModel->fromArray(array(
							'name' => $categoryName
						));

						$parent = $CategoryRepository->find($params['parentId']);
						$CategoryModel->setParent($parent);

						try
						{
							$Manager = Shopware()->Models();
							$Manager->persist($CategoryModel);
							$Manager->flush();
						}
						catch (Exception $e)
						{
						}

						$parentId = $CategoryModel->getId();
					}
				}

				PlentymarketsMappingController::addCategory($parentId, $Category->ItemCategoryPath);
				$this->categories[] = array(
					'id' => $parentId
				);
			}
		}
	}

	protected function setDetails()
	{
		$active = $this->ItemBase->Availability->Inactive == 0 && $this->ItemBase->Availability->Webshop == 1;

		$base = array(
			'active' => $active,
			'ean' => $this->ItemBase->EAN1,
			'minPurchase' => def($this->ItemBase->Availability->MinimumSalesOrderQuantity),
			'purchaseSteps' => def($this->ItemBase->Availability->IntervalSalesOrderQuantity),
			'maxPurchase' => def($this->ItemBase->Availability->MaximumSalesOrderQuantity),
			'purchaseUnit' => def($this->ItemBase->PriceSet->Lot),
			'referenceUnit' => def($this->ItemBase->PriceSet->PackagingUnit),
			'packUnit' => trim($this->ItemBase->PriceSet->Unit1),
			'releaseDate' => ($this->ItemBase->Published == 0 ? null : date('c', $this->ItemBase->Published)),
			'weight' => null,
			'width' => null,
			'len' => null,
			'height' => null,
			'attribute' => array(
				'attr1' => $this->ItemBase->FreeTextFields->Free1,
				'attr2' => $this->ItemBase->FreeTextFields->Free2,
				'attr3' => $this->ItemBase->FreeTextFields->Free3,
				'attr4' => $this->ItemBase->FreeTextFields->Free4,
				'attr5' => $this->ItemBase->FreeTextFields->Free5,
				'attr6' => $this->ItemBase->FreeTextFields->Free6,
				'attr7' => $this->ItemBase->FreeTextFields->Free7,
				'attr8' => $this->ItemBase->FreeTextFields->Free8,
				'attr9' => $this->ItemBase->FreeTextFields->Free9,
				'attr10' => $this->ItemBase->FreeTextFields->Free10,
				'attr11' => $this->ItemBase->FreeTextFields->Free11,
				'attr12' => $this->ItemBase->FreeTextFields->Free12,
				'attr13' => $this->ItemBase->FreeTextFields->Free13,
				'attr14' => $this->ItemBase->FreeTextFields->Free14,
				'attr15' => $this->ItemBase->FreeTextFields->Free15,
				'attr16' => $this->ItemBase->FreeTextFields->Free16,
				'attr17' => $this->ItemBase->FreeTextFields->Free17,
				'attr18' => $this->ItemBase->FreeTextFields->Free18,
				'attr19' => $this->ItemBase->FreeTextFields->Free19,
				'attr20' => $this->ItemBase->FreeTextFields->Free20
			)
		);

		if ($this->ItemBase->PriceSet->WeightInGramm > 0)
		{
			$base['weight'] = $this->ItemBase->PriceSet->WeightInGramm / 1000;
		}

		if ($this->ItemBase->PriceSet->WidthInMM > 0)
		{
			$base['width'] = $this->ItemBase->PriceSet->WidthInMM / 100;
		}

		if ($this->ItemBase->PriceSet->LengthInMM > 0)
		{
			$base['len'] = $this->ItemBase->PriceSet->LengthInMM / 100;
		}

		if ($this->ItemBase->PriceSet->HeightInMM > 0)
		{
			$base['height'] = $this->ItemBase->PriceSet->HeightInMM / 100;
		}

		if (strlen($this->ItemBase->PriceSet->Unit))
		{
			try
			{
				$base['unitId'] = PlentymarketsMappingController::getMeasureUnitByPlentyID($this->ItemBase->PriceSet->Unit);
			}
			catch (PlentymarketsMappingExceptionNotExistant $E)
			{
				$base['unitId'] = null;
			}
		}

		if (!is_null($this->ItemBase->AttributeValueSets))
		{
			foreach ($this->ItemBase->AttributeValueSets->item as $AttributeValueSet)
			{
				$AttributeValueSet instanceof PlentySoapObject_ItemAttributeValueSet;

				$details = $base;
				$sku = sprintf('%s-%s-%s', $this->ItemBase->ItemID, $AttributeValueSet->PriceID, $AttributeValueSet->AttributeValueSetID);

				try
				{
					// Set the details id
					$details['id'] = PlentymarketsMappingController::getItemVariantByPlentyID($sku);
				}
				catch (PlentymarketsMappingExceptionNotExistant $e)
				{
					// neue nummer
					$details['number'] = self::getUsableNumber($AttributeValueSet->ColliNo);
				}

				// $details['isMain'] = $isMain;
				$details['additionaltext'] = $AttributeValueSet->AttributeValueSetName;
				$details['ean'] = $AttributeValueSet->EAN;

				//
				$details['X_plentyVariantId'] = $AttributeValueSet->AttributeValueSetID;
				$details['X_plentySku'] = $sku;

				$this->variants[$AttributeValueSet->AttributeValueSetID] = $details;
			}
		}

		$this->details = $base;
	}

	/**
	 *
	 */
	public function setProperties()
	{
		if (is_null($this->ItemBase->ItemProperties))
		{
			return;
		}

		$groups = array();

		foreach ($this->ItemBase->ItemProperties->item as $ItemProperty)
		{
			$ItemProperty instanceof PlentySoapObject_ItemProperty;
			$groups[$ItemProperty->PropertyGroupID][] = $ItemProperty;
		}

		$groupId = -1;
		$numberOfValuesMax = 0;

		foreach ($groups as $groupIdx => $values)
		{
			if (count($values) > $numberOfValuesMax)
			{
				$groupId = $groupIdx;
				$numberOfValuesMax = count($values);
			}
		}

		// Check for filterId
		try
		{
			$filterGroupId = PlentymarketsMappingController::getPropertyGroupByPlentyID($groupId);
		}
		catch (PlentymarketsMappingExceptionNotExistant $E)
		{
			// Gruppe erstellen
			$PropertyGroupResource = Shopware\Components\Api\Manager::getResource('PropertyGroup');
			$group = $PropertyGroupResource->create(array(
				'name' => $ItemProperty->PropertyGroupFrontendName
			));
			$filterGroupId = $group->getId();

			PlentymarketsMappingController::addPropertyGroup($filterGroupId, $groupId);
			PlentymarketsLogger::getInstance()->message('Sync:Item', 'The property group ' . $ItemProperty->PropertyGroupFrontendName . ' has been created');
		}
		// Properties

		$this->data['filterGroupId'] = $filterGroupId;
		$this->data['propertyValues'] = array();

		foreach ($groups[$groupId] as $ItemProperty)
		{
			$ItemProperty instanceof PlentySoapObject_ItemProperty;

			// Mapping GroupId;ValueId -> ValueId
			try
			{
				list ($unused, $optionId) = explode(';', PlentymarketsMappingController::getPropertyByPlentyID($ItemProperty->PropertyID));
			}
			catch (PlentymarketsMappingExceptionNotExistant $E)
			{

				$Option = new \Shopware\Models\Property\Option();
				$Option->fromArray(array(
					'name' => $ItemProperty->PropertyName,
					'filterable' => 1
				));

				try
				{
					Shopware()->Models()->persist($Option);
					Shopware()->Models()->flush();
					PlentymarketsLogger::getInstance()->message('Sync:Item', 'The property ' . $ItemProperty->PropertyName . ' has been created');
				}
				catch (Exception $e)
				{
					//
					PlentymarketsLogger::getInstance()->error('Sync:Item', 'The property ' . $ItemProperty->PropertyName . ' could not be created');
					PlentymarketsLogger::getInstance()->error('Sync:Item', $e->getMessage());
				}

				if (!isset($group))
				{
					/* @var $group Group */
					$group = Shopware()->Models()
						->getRepository('Shopware\Models\Property\Group')
						->find($filterGroupId);
				}

				if (!$group)
				{
					PlentymarketsLogger::getInstance()->message(__METHOD__, 'cannot load group with id : ' . $filterGroupId);
					return;
				}

				$group->addOption($Option);

				try
				{
					Shopware()->Models()->flush();
				}
				catch (\Exception $e)
				{
				}

				$option = Shopware()->Models()->toArray($Option);
				$optionId = $option['id'];

				// Mapping speichern
				PlentymarketsMappingController::addProperty($filterGroupId . ';' . $optionId, $ItemProperty->PropertyID);
			}

			$this->data['propertyValues'][] = array(
				'option' => array(
					'id' => $optionId
				),
				'value' => $ItemProperty->PropertyValue
			);
		}
	}

	public function import()
	{
		$this->setData();
		$this->setDetails();
		$this->setCategories();
		$this->setProperties();

		$data = $this->data;
		$data['categories'] = $this->categories;
		$mainDetailId = -1;

		$ArticleResource = \Shopware\Components\Api\Manager::getResource('Article');
		$VariantResource = \Shopware\Components\Api\Manager::getResource('Variant');

		try
		{
			// Ein ganz normaler Artikel
			$SHOPWARE_itemID = PlentymarketsMappingController::getItemByPlentyID($this->ItemBase->ItemID);

			// Artikel aktualisieren
			$Article = $ArticleResource->update($SHOPWARE_itemID, $data);

			// Für die Preise
			$mainDetailId = $Article->getMainDetail()->getId();

			//
			$variants = array();
			$variantsToBe = array();
			$update = array();
			$number2sku = array();
			$keep = array(
				'numbers' => array(),
				'ids' => array()
			);

			// Es gibt varianten
			if (count($this->variants))
			{
				//
				$VariantController = new PlentymarketsVariantController($this->ItemBase);

				// War der Artikel vorher schn eine Variante?
				// Wenn nicht muss aus das Konfigurator set angelegt werden
				$numberOfVariantsUpdated = 0;
				$numberOfVariantsCreated = 0;

				foreach ($this->variants as $variantId => $variant)
				{
					// Markup
					$variant['X_plentyMarkup'] = $VariantController->getMarkupByVariantId($variantId);

					// Variante ist bereits vorhanden
					if (array_key_exists('id', $variant))
					{
						// $ShopwareVariant = $VariantResource->getOne($variant['id']);
						// $variant
						++$numberOfVariantsUpdated;
						$keep['ids'][] = $variant['id'];
						$variants[] = $variant;
					}

					// Variante muss erstellt werden
					else
					{
						++$numberOfVariantsCreated;
						$variantsToBe[$variantId] = $variant;
						$keep['numbers'][] = $variant['number'];

						// Nur die neuen kommen da rein.
						$number2sku[$variant['number']] = $variant['X_plentySku'];
						$number2markup[$variant['number']] = $variant['X_plentyMarkup'];
					}
				}

				if ($numberOfVariantsCreated)
				{
					foreach ($variantsToBe as $variantId => $variant)
					{
						$variant['configuratorOptions'] = $VariantController->getOptionsByVariantId($variantId);

						// Anhängen
						$variants[] = $variant;
					}

					// Set muss ggf. aktualisiert werden
					$update['configuratorSet'] = array(
						'groups' => $VariantController->getGroups()
					);
				}

				// Varianten löschen, wenn nicht eine aktualisiert worden ist
				if ($numberOfVariantsUpdated == 0)
				{
					$Article = $ArticleResource->update($SHOPWARE_itemID, array(
						'configuratorSet' => array(
							'groups' => array()
						),
						'variations' => array()
					));
				}

				$update['variants'] = $variants;
				$ArticleResource->update($SHOPWARE_itemID, $update);
				$article = $ArticleResource->getOne($SHOPWARE_itemID);

				// Mapping für die Varianten
				foreach ($article['details'] as $detail)
				{
					// Muss gelöscht werden -- Achtung!! MainDetail muss ggf. neu gesetzt werden
					if (!in_array($detail['number'], $keep['numbers']) && !in_array($detail['id'], $keep['ids']))
					{
						//
						$VariantResource->deleteByNumber($detail['number']);

						// Mapping löschen
						PlentymarketsMappingController::deleteItemVariantByShopwareID($detail['id']);
						continue;
					}

					// Alles nur für die neuen. aktualsiert sind sie schon, preise macht der andere prozess
					if (!array_key_exists($detail['number'], $number2sku))
					{
						continue;
					}

					PlentymarketsMappingController::addItemVariant($detail['id'], $number2sku[$detail['number']]);

					// Preise
					$PlentymarketsImportEntityItemPrice = new PlentymarketsImportEntityItemPrice($this->ItemBase->PriceSet, $number2markup[$detail['number']]);
					$PlentymarketsImportEntityItemPrice->updateVariant($detail['id']);
				}

				$VariantController->map($article);

				// Log
				PlentymarketsLogger::getInstance()->message('Sync:Item', 'Variants updated: ' . $numberOfVariantsUpdated);
			}
			else
			{
				// Preise eines Normalen Artikels aktualisieren
				$PlentymarketsImportEntityItemPrice = new PlentymarketsImportEntityItemPrice($this->ItemBase->PriceSet);
				$PlentymarketsImportEntityItemPrice->update($SHOPWARE_itemID);
			}

			// Bilder
			$PlentymarketsImportEntityItemImage = new PlentymarketsImportEntityItemImage($this->ItemBase->ItemID, $SHOPWARE_itemID);
			$PlentymarketsImportEntityItemImage->image();
		}

		// Artikel muss importiert werden / Es ist kein Basisartikel
		catch (PlentymarketsMappingExceptionNotExistant $E)
		{

			try
			{
				// Normaler ARtikel
				if (!count($this->variants))
				{
					$data['mainDetail'] = $this->details;

					// todo: sicherstellen, dass eine vernünftige Nummer vergeben wird
					$data['mainDetail']['number'] = self::getUsableNumber($this->ItemBase->ItemNo);

					// Anlegen
					$Article = $ArticleResource->create($data);

					//
					$SHOPWARE_itemID = $Article->getId();

					// Log
					PlentymarketsLogger::getInstance()->message('Sync:Item', 'Item "' . $this->data['name'] . '" created with number ' . $data['mainDetail']['number']);

					// Mapping speichern
					PlentymarketsMappingController::addItem($Article->getId(), $this->ItemBase->ItemID);

					// Media

					// Preise
					$PlentymarketsImportEntityItemPrice = new PlentymarketsImportEntityItemPrice($this->ItemBase->PriceSet);
					$PlentymarketsImportEntityItemPrice->update($Article->getId());

					// Merkmale
					// Wenn kein Mapping für die GruppenId vorhanden ist, muss diese über ein extra Aufruf vorher erstellt werden
					// Wenn kein Mapping für ItemProperties->item->PropertyGroupID, dann muss sie abgerufen werden

					// PlentymarketsLogger::getInstance()->message('Sync:Item', '$mainDetailId: ' . $mainDetailId);
				}

				else
				{
					// Den Typ des Basisartikels auf 2 stellen,

					// Basisartikel anlegen
					$data['mainDetail'] = $this->details;

					// Set the id of the first variant
					$mainVariant = array_shift(array_values($this->variants));
					$data['mainDetail']['number'] = $mainVariant['number'];

					// Anlegen
					$Article = $ArticleResource->create($data);
					PlentymarketsLogger::getInstance()->message('Sync:Item', 'Variant base item "' . $this->data['name'] . '" created with number ' . $data['mainDetail']['number']);

					//
					$SHOPWARE_itemID = $Article->getId();

					// Mapping speichern
					PlentymarketsMappingController::addItem($Article->getId(), $this->ItemBase->ItemID);

					$VariantController = new PlentymarketsVariantController($this->ItemBase);

					//
					$variants = array();
					$number2markup = array();
					$number2sku = array();

					//
					foreach ($this->variants as $variantId => &$variant)
					{
						$variant['configuratorOptions'] = $VariantController->getOptionsByVariantId($variantId);
						$number2markup[$variant['number']] = $VariantController->getMarkupByVariantId($variantId);
						$number2sku[$variant['number']] = $variant['X_plentySku'];
					}

					// Varianten
					$id = $Article->getId();

					$updateArticle = array(

						'configuratorSet' => array(
							'groups' => $VariantController->getGroups()
						),

						'variants' => array_values($this->variants)
					);

					PlentymarketsLogger::getInstance()->message('Sync:Item:Variant', 'Starting to create variants for item "' . $this->data['name'] . '" (' . $data['mainDetail']['number'] . ')');

					$Article = $ArticleResource->update($id, $updateArticle);

					// Mapping für die Varianten
					foreach ($Article->getDetails() as $detail)
					{
						PlentymarketsMappingController::addItemVariant($detail->getId(), $number2sku[$detail->getNumber()]);

						// Preise
						$PlentymarketsImportEntityItemPrice = new PlentymarketsImportEntityItemPrice($this->ItemBase->PriceSet, $number2markup[$detail->getNumber()]);
						$PlentymarketsImportEntityItemPrice->updateVariant($detail->getId());
					}

					$VariantController->map($ArticleResource->getOne($id));

					PlentymarketsLogger::getInstance()->message('Sync:Item:Variant', 'Variants created for item "' . $this->data['name'] . '" (' . $data['mainDetail']['number'] . ')');
				}

				// Bilder
				$PlentymarketsImportEntityItemImage = new PlentymarketsImportEntityItemImage($this->ItemBase->ItemID, $SHOPWARE_itemID);
				$PlentymarketsImportEntityItemImage->image();
			}
			catch (Shopware\Components\Api\Exception\OrmException $E)
			{
				PlentymarketsLogger::getInstance()->error('Sync:Item', 'Item could not be created: ' . $E->getMessage());
			}
			catch (Exception $E)
			{
				PlentymarketsLogger::getInstance()->error('Sync:Item', 'Item could not be created: ' . $this->data['name']);
			}
		}

		$ArticleResource->flush();

		// Der Hersteller ist neu angelegt worden
		if (array_key_exists('supplier', $this->data))
		{
			// dann das mapping speichern
			PlentymarketsLogger::getInstance()->message('Sync:Item', 'Producer created: ' . $Article->getSupplier()
				->getName());
			PlentymarketsMappingController::addProducer($Article->getSupplier()->getId(), $this->ItemBase->ProducerID);
		}
	}

	protected function getTaxId()
	{
		try
		{
			$taxID = PlentymarketsMappingController::getVatByPlentyID($this->ItemBase->VATInternalID);
		}
		catch (PlentymarketsMappingExceptionNotExistant $E)
		{
			// Retry
			$taxID = PlentymarketsMappingController::getVatByPlentyID($this->ItemBase->VATInternalID);
		}

		return $taxID;
	}
}
