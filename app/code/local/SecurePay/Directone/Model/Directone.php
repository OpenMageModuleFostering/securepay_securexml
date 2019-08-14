<?php
/**
 * SecurePay Directone Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category	SecurePay
 * @package		SecurePay_Directone
 * @author		Andrew Dubbeld
 * @license		http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * The heart of the Directone payment model
 * Doesn't do much.
 */
class SecurePay_Directone_Model_Directone extends Mage_Payment_Model_Method_Abstract
{
	const REQUEST_AMOUNT_EDITABLE = 'N';
	const RETURN_TEXT_DEFAULT = 'Click here to complete the order';

	protected $_code  = 'directone';
	protected $_formBlockType = 'SecurePay_Directone_block_form';
	protected $_allowCurrencyCode = array('AUD');
	
	/**
	 * Assign data to info model instance
	 */
	public function assignData($data)
	{
		$details = array();
		if ($this->getUsername())
		{
			$details['username'] = $this->getUsername();
		}
		if (!empty($details)) 
		{
			$this->getInfoInstance()->setAdditionalData(serialize($details));
		}
		return $this;
	}

	public function getUsername()
	{
		return $this->getConfigData('username');
	}
	
	public function getUrl()
	{
		$test = ($this->getConfigData('test')==0?false:true);
		
		$url = 'https://vault.safepay.com.au/cgi-bin/'.($test?'test':'make').'_payment.pl';
		
		return $url;
	}

	public function getReturn()
	{
		$text = $this->getConfigData('return_text');
		
		if($text==false)
		{
			$text = self::RETURN_TEXT_DEFAULT;
		}
		
		return $text;
	}
	
	/**
	 * Returns the active session instance (SecurePay_Directone_Model_Directone_Session)
	 */
	public function getSession()
	{
		return Mage::getSingleton('directone/directone_session');
	}

	/**
	 * Returns the active checkout instance (Mage_Checkout_Model_Session)
	 */
	public function getCheckout()
	{
		return Mage::getSingleton('checkout/session');
	}

	/**
	 * Get current quote
	 *
	 * @return Mage_Sales_Model_Quote
	 */
	public function getQuote()
	{
		return $this->getCheckout()->getQuote();
	}
	
	/**
	 * getCheckoutFormFields
	 *
	 * Creates an array of fields for submission to DirectOne.
	 */
	public function getCheckoutFormFields()
	{
		$saddress = $this->getQuote()->getShippingAddress();
		$baddress = $this->getQuote()->getBillingAddress();
		$currency_code = $this->getQuote()->getBaseCurrencyCode();
		$cost = $saddress->getBaseSubtotal() - $saddress->getBaseDiscountAmount();
		$shipping = $saddress->getBaseShippingAmount();
		$simple = $this->getConfigData('simple');

		$_shippingTax = $this->getQuote()->getShippingAddress()->getBaseTaxAmount();
		$_billingTax = $this->getQuote()->getBillingAddress()->getBaseTaxAmount();
		$tax = sprintf('%.2f', $_shippingTax + $_billingTax);
		$cost = sprintf('%.2f', $cost + $tax);
		$invoice = $this->getCheckout()->getLastOrderId();
		
		$fields = array(
			'vendor_name'		=> $this->getUsername(),
			'Email'				=> $baddress->getEmail(),
			'Name'				=> $baddress->getFirstname() . ' ' . $baddress->getLastname(),
			'Phone'				=> $baddress->getTelephone(),
			'Country'			=> $baddress->getCountry(),
			'Address'			=> $baddress->getStreet(1) .' '. $baddress->getStreet(2),
			'City'				=> $baddress->getCity(),
			'State'				=> $baddress->getRegion(),
			'Postcode'			=> $baddress->getPostcode(),
			'Invoice'			=> $invoice,
			'information_fields'=> 'Name,Invoice,Phone,Email,Address,City,State,Postcode,Country',
			'suppress_field_names'=> 'form_key',
		);
		
		if($simple)
		{
			$fields['Invoice '.$this->getCheckout()->getLastRealOrderId()] = '1,'.($cost + $shipping);
		}
		else
		{
			$products = $this->getQuote()->getAllItems();
			
			foreach($products as $item)
			{
				$value = str_replace('"','',str_replace("&","and",$item->getName()));
				$fields[trim($value)] = ''.$item->getQty().','.($item->getBaseCalculationPrice() - $item->getBaseDiscountAmount());
			}
			if($shipping)
			{
				$fields['Shipping'] = '1,'.$shipping;
			}
		}
		
		$filtered_fields = array();
		
		foreach ($fields as $k=>$v)
		{
			$value = str_replace("&","and",$v);
			$filtered_fields[$k] = $value;
		}
		
		$filtered_fields['return_link_url'] 	= Mage::getUrl('directone/directone/success');
		$filtered_fields['return_link_text'] 	= $this->getReturn();
		$filtered_fields['reply_link_url'] 		= Mage::getUrl('directone/directone/notify',array('_query'=>'response_amount=&amp;Invoice='));
		
		return $filtered_fields;
	}

	public function createFormBlock($name)
	{
		$block = $this->getLayout()->createBlock('directone/directone_form', $name)
			->setMethod('directone')
			->setPayment($this->getPayment())
			->setTemplate('directone/form.phtml');

		return $block;
	}

	public function validate()
	{
		parent::validate();
		$currency_code = $this->getQuote()->getBaseCurrencyCode();
		if (!in_array($currency_code,$this->_allowCurrencyCode))
		{
			Mage::throwException(Mage::helper('directone')->__('Selected currency code ('.$currency_code.') is not compatible with Directone'));
		}
		return $this;
	}

	public function onOrderValidate(Mage_Sales_Model_Order_Payment $payment)
	{
		return $this;
	}

	public function onInvoiceCreate(Mage_Sales_Model_Invoice_Payment $payment)
	{
		
	}

	public function canCapture()
	{
		return true;
	}

	public function getOrderPlaceRedirectUrl()
	{
		return Mage::getUrl('directone/directone/redirect');
	}
}
