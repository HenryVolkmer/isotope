<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2016 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @link       https://isotopeecommerce.org
 * @license    https://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Module;

use Haste\Util\Url;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Isotope;
use Isotope\Model\ProductCollection;
use Isotope\Model\ProductCollectionItem;
use Isotope\Template;


/**
 * @property bool   $iso_continueShopping
 * @property int    $iso_cart_jumpTo
 * @property int    $iso_checkout_jumpTo
 * @property int    $iso_gallery
 * @property string $iso_collectionTpl
 * @property string $iso_orderCollectionBy
 */
abstract class AbstractProductCollection extends Module
{
    /**
     * Disable caching of the frontend page if this module is in use.
     * @var boolean
     */
    protected $blnDisableCache = true;

    /**
     * FORM_SUBMIT value for this module
     * @var string
     */
    protected $strFormId = 'iso_collection_';

    /**
     * Display a wildcard in the back end
     * @return string
     */
    public function generate()
    {
        // Add current module ID to FORM_SUBMIT
        $this->strFormId .= $this->id;

        return parent::generate();
    }

    /**
     * @inheritdoc
     */
    protected function compile()
    {
        $collection = $this->getCollection();

        if (null === $collection) {
            return;
        }

        $this->Template->jumpTo     = $this->iso_cart_jumpTo;

        if ($collection->isEmpty()) {
            $this->Template->empty   = true;
            $this->Template->type    = 'empty';
            $this->Template->message = $this->iso_emptyMessage ? $this->iso_noProducts : $this->getEmptyMessage();

            return;
        }

        Isotope::setConfig($collection->getConfig());

        $objTemplate = $this->getCollectionTemplate();

        $collection->addToTemplate(
            $objTemplate,
            array(
                'module'  => $this,
                'gallery' => $this->iso_gallery,
                'sorting' => ProductCollection::getItemsSortingCallable($this->iso_orderCollectionBy),
            )
        );

        $blnReload   = false;
        $arrQuantity = \Input::post('quantity');
        $arrItems    = $objTemplate->items;

        if (!is_array($arrQuantity)) {
            $arrQuantity = [];
        } else {
            $arrQuantity = array_filter(
                $arrQuantity,
                function ($v) {
                    return '' !== $v;
                }
            );
        }


        foreach ($arrItems as $k => $data) {
            /** @var ProductCollectionItem $item */
            $item = $data['item'];

            $arrItems[$k] = $this->updateItemTemplate($collection, $item, $data, $arrQuantity, $blnReload);
        }

        // Must be before the reload because buttons can have actions
        $buttons = $this->generateButtons();

        // Reload the page if no button has handled it
        if ($blnReload) {
            if ($collection instanceof ProductCollection\Cart) {
                // Unset payment and shipping method because they could get invalid due to the change
                if (($objShipping = $collection->getShippingMethod()) !== null && !$objShipping->isAvailable()) {
                    $collection->setShippingMethod(null);
                }

                if (($objPayment = $collection->getPaymentMethod()) !== null && !$objPayment->isAvailable()) {
                    $collection->setPaymentMethod(null);
                }
            }

            \Controller::reload();
        }

        $objTemplate->items         = $arrItems;
        $objTemplate->jumpTo        = $this->iso_cart_jumpTo;
        $objTemplate->buttons       = $buttons;

        
        $this->Template->empty      = false;
        $this->Template->collection = $collection;
        $this->Template->products   = $objTemplate->parse();
    }

    /**
     * @return IsotopeProductCollection|null
     */
    abstract protected function getCollection();

    /**
     * @return string
     */
    abstract protected function getEmptyMessage();

    /**
     * @return bool
     */
    abstract protected function canEditQuantity();

    /**
     * @return bool
     */
    abstract protected function canRemoveProducts();

    /**
     * @return Template
     */
    protected function getCollectionTemplate()
    {
        $template = new Template($this->iso_collectionTpl);

        $template->isEditable    = $this->canEditQuantity();
        $template->linkProducts  = true;
        $template->formId        = $this->strFormId;
        $template->formSubmit    = $this->strFormId;
        $template->action        = \Environment::get('request');

        return $template;
    }

    /**
     * @param IsotopeProductCollection $collection
     * @param array                    $data
     * @param array                    $quantity
     * @param bool                     $hasChanges
     *
     * @return array
     */
    protected function updateItemTemplate(
        IsotopeProductCollection $collection,
        ProductCollectionItem $item,
        array $data,
        array $quantity,
        &$hasChanges
    ) {
        // Update cart data if form has been submitted
        if ($this->canEditQuantity()
            && \Input::post('FORM_SUBMIT') === $this->strFormId
            && array_key_exists($item->id, $quantity)
        ) {
            $hasChanges = true;
            $collection->updateItemById($item->id, array('quantity' => $quantity[$item->id]));

            return $data; // no need to do anything else, we reload anyway
        }

        if ($this->canRemoveProducts()) {
            if ((int) \Input::get('remove') === (int) $item->id) {
                $collection->deleteItemById($item->id);
                \Controller::redirect(Url::removeQueryString(['remove']));
            }

            $data['remove_href']  = Url::addQueryString('remove=' . $item->id);
            $data['remove_title'] = specialchars(sprintf($GLOBALS['TL_LANG']['MSC']['removeProductLinkTitle'], $data['name']));
            $data['remove_link']  = $GLOBALS['TL_LANG']['MSC']['removeProductLinkText'];
        }

        return $data;
    }

    /**
     * Generate buttons for collection view template
     *
     * @param array $buttons
     *
     * @return array
     */
    protected function generateButtons(array $buttons = [])
    {
        return $buttons;
    }

    /**
     * @param array           $buttons
     * @param string          $name
     * @param string          $label
     * @param \Closure|string $action
     * @param array           $additional
     */
    protected function addButton(array &$buttons, $name, $label, $action = null, array $additional = [])
    {
        $button = array_merge(
            [
                'type'      => 'submit',
                'name'      => 'button_' . $name,
                'label'     => $label,
            ],
            $additional
        );

        if (is_string($action)) {
            $button['href'] = $action;
        }

        if (null !== $action
            && \Input::post('FORM_SUBMIT') === $this->strFormId
            && '' !== (string) \Input::post('button_' . $name)
        ) {
            if (is_string($action)) {
                \Controller::redirect($action);
            }

            call_user_func($action, $button);
        }

        $buttons[$name] = $button;
    }
}
