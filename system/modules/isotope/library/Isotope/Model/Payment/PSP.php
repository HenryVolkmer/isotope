<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2013 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://isotopeecommerce.org
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Model\Payment;

use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Isotope;
use Isotope\Model\Payment;
use Isotope\Model\ProductCollection\Order;


/**
 * Class PSP
 *
 * Handle PSP payments
 * @copyright  Isotope eCommerce Workgroup 2009-2013
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 */
abstract class PSP extends Payment
{

    /**
     * Process payment on confirmation page
     * @return  boolean
     */
    public function processPayment()
    {
        // In processPayment, the parameters are always in GET
        $this->psp_http_method = 'GET';

        if (($objOrder = $this->getPostsaleOrder()) === null) {
            \System::log('Order ID "' . \Input::get('orderID') . '" not found', __METHOD__, TL_ERROR);

            return false;
        }

        return $this->processPostsale($objOrder);
    }


    /**
     * Process post-sale requestion from the PSP payment server.
     * @param   IsotopeProductCollection
     */
    public function processPostsale(IsotopeProductCollection $objOrder)
    {
        if (!$this->validateSHASign()) {
            \System::log('Received invalid postsale data for order ID "' . $objOrder->id . '"', __METHOD__, TL_ERROR);
            return false;
        }

        // If order status is set, we check order total instead of cart total
        if ($objOrder->order_status > 0) {
            $objCart = $objOrder;

        } elseif (($objCart = $objOrder->getRelated('source_collection_id')) === null) {
            \System::log('Cart ID ' . $objOrder->source_collection_id . ' for Order ID ' . $objOrder->id . ' not found', __METHOD__, TL_ERROR);
            return false;
        }

        // Validate payment data
        if ($objOrder->currency != $this->getRequestData('currency') || $objCart->getTotal() != $this->getRequestData('amount')) {
            \System::log('Postsale checkout manipulation in payment for Order ID ' . $objOrder->id . '!', __METHOD__, TL_ERROR);
            return false;
        }

        if (!$objOrder->checkout()) {
            \System::log('Post-Sale checkout for Order ID "' . $objOrder->id . '" failed', __METHOD__, TL_ERROR);
            return false;
        }

        // Validate payment status
        switch ($this->getRequestData('STATUS')) {

            case 9:  // Zahlung beantragt (Authorize & Capture)
                $objOrder->date_paid = time();
                // no break

            case 5:  // Genehmigt (Authorize ohne Capture)
                $objOrder->updateOrderStatus($this->new_order_status);
                break;

            case 41: // Unbekannter Wartezustand
            case 51: // Genehmigung im Wartezustand
            case 91: // Zahlung im Wartezustand
            case 52: // Genehmigung nicht bekannt
            case 92: // Zahlung unsicher
                if (($objConfig = $objOrder->getRelated('config_id')) === null) {
                    $this->log('Config for Order ID ' . $objOrder->id . ' not found', __METHOD__, TL_ERROR);
                    return false;
                }

                $objOrder->updateOrderStatus($objConfig->orderstatus_error);
                break;

            case 0:  // Ungültig / Unvollständig
            case 1:  // Zahlungsvorgang abgebrochen
            case 2:  // Genehmigung verweigert
            case 4:  // Gespeichert
            case 93: // Bezahlung verweigert
            default:
                return false;
        }

        $objOrder->save();

        return true;
    }


    /**
     * {@inheritdoc}
     */
    public function getPostsaleOrder()
    {
        return Order::findByPk($this->getRequestData('orderID'));
    }


    /**
     * Return the payment form
     * @return  string
     */
    public function checkoutForm()
    {
        if (($objOrder = Order::findOneBy('source_collection_id', Isotope::getCart()->id)) === null) {
            \Isotope\Module\Checkout::redirectToStep('failed');
        }

        $arrParams = $this->preparePSPParams($objOrder);

        // SHA-1 must be generated on alphabetically sorted keys.
        // Use the natural order algorithm so ITEM10 gets listed after ITEM2
        // We can only use ksort($arrParams, SORT_NATURAL) as of PHP 5.4
        uksort($arrParams, 'strnatcasecmp');

        $strSHASign = '';
        foreach ($arrParams as $k => $v) {
            if ($v == '')
                continue;

            $strSHASign .= $k . '=' . htmlspecialchars_decode($v) . $this->psp_hash_in;
        }

        $arrParams['SHASIGN'] = strtoupper(hash($this->psp_hash_method, $strSHASign));

        $objTemplate = new \Isotope\Template($this->strTemplate);
        $objTemplate->setData($this->arrData);

        $objTemplate->params   = $arrParams;
        $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][0];
        $objTemplate->message  = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][1];
        $objTemplate->slabel   = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][2];

        return $objTemplate->parse();
    }

    /**
     * Prepare PSP params
     * @param   Order
     * @return  array
     */
    protected function preparePSPParams($objOrder)
    {
        $objBillingAddress = $objOrder->getBillingAddress();

        return array
        (
            'PSPID'         => $this->psp_pspid,
            'ORDERID'       => $objOrder->id,
            'AMOUNT'        => round((Isotope::getCart()->getTotal() * 100)),
            'CURRENCY'      => Isotope::getConfig()->currency,
            'LANGUAGE'      => $GLOBALS['TL_LANGUAGE'] . '_' . strtoupper($GLOBALS['TL_LANGUAGE']),
            'CN'            => $objBillingAddress->firstname . ' ' . $objBillingAddress->lastname,
            'EMAIL'         => $objBillingAddress->email,
            'OWNERZIP'      => $objBillingAddress->postal,
            'OWNERADDRESS'  => $objBillingAddress->street_1,
            'OWNERADDRESS2' => $objBillingAddress->street_2,
            'OWNERCTY'      => strtoupper($objBillingAddress->country),
            'OWNERTOWN'     => $objBillingAddress->city,
            'OWNERTELNO'    => $objBillingAddress->phone,
            'ACCEPTURL'     => \Environment::get('base') . \Haste\Util\Url::addQueryString('uid=' . $objOrder->uniqid, \Isotope\Module\Checkout::generateUrlForStep('complete')),
            'DECLINEURL'    => \Environment::get('base') . \Isotope\Module\Checkout::generateUrlForStep('failed'),
            'BACKURL'       => \Environment::get('base') . \Isotope\Module\Checkout::generateUrlForStep('review'),
            'PARAMPLUS'     => 'mod=pay&amp;id=' . $this->id,
            'TP'            => $this->psp_dynamic_template ? : ''
        );
    }

    /**
     * Gets the request data based on the chosen HTTP method
     * @param   string Key
     * @return  mixed
     */
    private function getRequestData($strKey)
    {
        if ($this->psp_http_method == 'GET') {
            return \Input::get($strKey);
        }

        return \Input::post($strKey);
    }


    /**
     * Validate SHA-OUT signature
     * @return  boolean
     */
    private function validateSHASign()
    {
        $strSHASign = '';
        $arrParams  = array();

        foreach (array_keys(($this->psp_http_method == 'GET' ? $_GET : $_POST)) as $key) {
            if (in_array(strtoupper($key), static::$arrShaOut)) {
                $arrParams[$key] = $this->getRequestData($key);
            }
        }

        // SHA-1 must be generated on alphabetically sorted keys.
        // Use the natural order algorithm so ITEM10 gets listed after ITEM2
        // We can only use ksort($arrParams, SORT_NATURAL) as of PHP 5.4
        uksort($arrParams, 'strnatcasecmp');

        foreach ($arrParams as $k => $v) {
            if ($v == '') {
                continue;
            }

            $strSHASign .= strtoupper($k) . '=' . $v . $this->psp_hash_out;
        }

        if ($this->getRequestData('SHASIGN') == strtoupper(hash($this->psp_hash_method, $strSHASign))) {
            return true;
        }

        return false;
    }
}
