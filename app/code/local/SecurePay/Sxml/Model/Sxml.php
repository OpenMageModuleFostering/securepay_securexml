<?php
/**
 * SecurePay Sxml Extension
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
 * @package		Sxml
 * @author		Andrew Dubbeld (support@securepay.com.au)
 * @license		http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 * @notes		Partially derived from the Fontis SecurePay module, Copyright (c) 2008 Fontis Pty. Ltd. (http://www.fontis.com.au)
 */

require_once('SecurePay/securepay_xml_api.php');
 
/**
 * SecurePay_Sxml_Model_Sxml
 *
 * The bulk of the SecurePay XML API payment module. It handles Preauth/Advice, Standard, Reverse and Refund transactions in the Magento application.
 */
class SecurePay_Sxml_Model_Sxml extends Mage_Payment_Model_Method_Cc
{	
    protected $_code  = 'Sxml';

    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = true;
    protected $_canRefund               = true;
    protected $_canVoid                 = true;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;
    protected $_canSaveCc               = false;
	
	protected $_formBlockType = 'SecurePay_Sxml_block_form_cc';
	
    const STATUS_APPROVED = 'Approved';

	const PAYMENT_ACTION_AUTH_CAPTURE = 'authorize_capture';
	const PAYMENT_ACTION_AUTH = 'authorize';
	
	public function getDebug()
	{
		return Mage::getStoreConfig('payment/Sxml/debug');
	}
	
	public function getMode()
	{
		if(Mage::getStoreConfig('payment/Sxml/test'))
		{
			return SECUREPAY_GATEWAY_MODE_TEST;
		}
		
		return SECUREPAY_GATEWAY_MODE_LIVE;
	}
	
	public function getLogPath()
	{
		return Mage::getBaseDir() . '/var/log/Sxml.log';
	}
	
	public function getUsername()
	{
		return Mage::getStoreConfig('payment/Sxml/username');
	}
	
	public function getPassword()
	{
		return Mage::getStoreConfig('payment/Sxml/password');
	}
	
	public function getCCVStatus()
	{
		return Mage::getStoreConfig('payment/Sxml/usecvv');
	}
	
	public function getCurrency()
	{
		return $this->getInfoInstance()->getQuote()->getBaseCurrencyCode();
	}


	/**
	 * validate
	 *
	 * Checks form data before it is submitted to processing functions.
	 *
	 * @param Varien_Object $payment
	 * @param int/float $amount
	 *
	 * @return Mage_Payment_Model_Method_Cc $this.
	 */
	public function validate()
    {
    	if($this->getDebug())
		{
	    	$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
		}
		
        //parent::validate();
        $paymentInfo = $this->getInfoInstance();
		
        if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment)
		{
            $currency_code = $paymentInfo->getOrder()->getBaseCurrencyCode();
        }
		else
		{
            $currency_code = $paymentInfo->getQuote()->getBaseCurrencyCode();
        }
		
        return $this;
    }
	
	/**
	 * authorize
	 *
	 * Sends a preauth transaction to the SecurePay Gateway. Only called in "Authorize" mode (See module settings).
	 *
	 * @param Varien_Object $payment
	 * @param int/float $amount
	 *
	 * @return Mage_Payment_Model_Method_Cc $this. Failure will throw Mage::throwException(), and the except value will be displayed to the customer. (!)
	 */
	public function authorize(Varien_Object $payment, $amount)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
		}
		
		//Create the transaction object
		$sxml = new securepay_xml_transaction ($this->getMode(),trim($this->getUsername()),trim($this->getPassword()));
		
		$transaction_id = $payment->getOrder()->getIncrementId();
		
		//Issue the preauth
		$preauthID = $sxml->processCreditPreauth($amount,$transaction_id,$payment->getCcNumber(),
												$payment->getCcExpMonth(),$payment->getCcExpYear(),$payment->getCcCid());
		
		$txnResultCodeText = $sxml->getErrorString();
		$approved = strtoupper($sxml->getResultByKeyName('approved'))=='YES'?true:false;
		$status = $sxml->getResultByKeyName('responseCode');		
		
		$payment->setCcTransId(''.$preauthID);
		
		if($this->getDebug())
		{
			if($approved)
			{
				$logger->info( "Preauth Approved. Preauth: ".$payment->getCcTransId()  );
			}
			else
			{
				$logger->info( "Preauth Declined. ".$txnResultCodeText );
			}
		}
		
		if($status == '000' || $status == '008')
		{
			if ($approved==false)
			{
				$this->setError(array('message' => $txnResultCodeText,));
				Mage::throwException("" . $txnResultCodeText);
			}
		}
		else
		{
			$this->setError(array('message' => $txnResultCodeText,));
			Mage::throwException("" . $txnResultCodeText);
		}
		
		return $this;
	}
	
	/**
	 * capture
	 *
	 * Completes a preauthorised transaction in preauth (Authorize) mode OR processes a standard transaction in standard (Authorize+Capture) mode.
	 * This function can be called in two possible situations:
	 * 		1. When the payment module is set to "Authorize", the module is in Preauth/Advice mode, and payments are preauthorized ($this->authorize()) when a user
	 *			 submits an order. Later on, the store owner needs to manually capture the payment, and this function is called.
	 *		2. When the payment module is set to "Authorize & Capture", or Standard mode, credit-card/order details are passed directly to this function after
	 *			 customer form submission.
	 * This function will store a gateway response id in $payment->CcTransId to facilitate void/refunds.
	 *
	 * @param Varien_Object $payment
	 * @param int/float $amount
	 *
	 * @return Mage_Payment_Model_Method_Cc $this. Failure will throw Mage::throwException(); in Standard mode the except value is displayed to the customer (!)
	 */
	 
	public function capture(Varien_Object $payment, $amount)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
		}
		$preauth = $payment->getCcTransId();
		
		$txnType = "Advice";
		
		if(!$preauth)
		{
			if($payment->getCcExpYear())
			{
				$txnType = "Standard";
			}
			else
			{
				if($this->getDebug())
				{
					$logger->info( "SecurePay_Sxml_Model_Sxml->capture(): CC details are missing in 'Preauth + Capture'. This should not happen.");
				}
				Mage::throwException("CC details missing.");
			}
		}		
			
		//Create the transaction object
		$sxml = new securepay_xml_transaction ($this->getMode(),$this->getUsername(),$this->getPassword());
		
		$transaction_id = $payment->getOrder()->getIncrementId();
		
		if($txnType == "Advice")
		{
			//Issue an advice transaction
			$bankTxnID = $sxml->processCreditAdvice($amount,$transaction_id,$preauth);
		}
		else if ($txnType == "Standard")
		{
			//Issue a standard transaction
			$bankTxnID = $sxml->processCreditStandard($amount,$transaction_id,$payment->getCcNumber(),
												$payment->getCcExpMonth(),$payment->getCcExpYear(),$payment->getCcCid());
		}
		else
		{
			Mage::throwException("Unknown transaction type.");
		}
		
		$txnResultCodeText = $sxml->getErrorString();
		$approved = strtoupper($sxml->getResultByKeyName('approved'))=='YES'?true:false;
		$status = $sxml->getResultByKeyName('responseCode');
		
		if($bankTxnID)
		{
			$payment->setCcTransId(''.$bankTxnID);
		}
		
		if($this->getDebug())
		{
			if($approved)
			{
				$logger->info( "Advice/Standard Approved. Response ID: ".$payment->getCcTransId()  );
			}
			else
			{
				$logger->info( "Advice/Standard Declined. ".$txnResultCodeText );
			}
		}
		
		if($status == '000' || $status == '008')
		{
			if ($approved==false)
			{
				Mage::throwException("" . $txnResultCodeText);
			}
		}
		else
		{
			Mage::throwException("" . $txnResultCodeText);
		}
		
		return $this;
	}

	/**
	 * void
	 *
	 * Handles reverse transactions.
	 *
	 * @param Varien_Object $payment
	 * @param int/float $amount
	 *
	 * @return Mage_Payment_Model_Method_Cc $this. Failure will throw Mage::throwException('description')
	 */
	public function void(Varien_Object $payment)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
		}
		$amount = $payment->getOrder()->getData('grand_total');
		
		$bankRespID = $payment->getCcTransId();
		
		if(!$bankRespID)
		{
			Mage::throwException("Cannot issue a void on this transaction: bank response id is missing.");
		}
		if(!$amount)
		{
			Mage::throwException("Cannot issue a void on this transaction: transaction amount is missing.");
		}
		
		//Create the transaction object
		$sxml = new securepay_xml_transaction ($this->getMode(),$this->getUsername(),$this->getPassword());
		
		$transaction_id = $payment->getOrder()->getIncrementId();
		
		//Issue a reverse transaction
		$bankTxnID = $sxml->processCreditReverse($amount,$transaction_id,$bankRespID);
		
		$txnResultCodeText = $sxml->getErrorString();
		$approved = strtoupper($sxml->getResultByKeyName('approved'))=='YES'?true:false;
		$status = $sxml->getResultByKeyName('responseCode');
		
		if($bankTxnID)
		{
			$payment->setCcTransId(''.$bankTxnID);
		}
		
		if($this->getDebug())
		{
			if($approved)
			{
				$logger->info( "Void Approved. Response ID: ".$payment->getCcTransId()  );
			}
			else
			{
				$logger->info( "Void Declined. ".$txnResultCodeText."; TransID: ".$payment->getCcTransId() );
			}
		}
		
		if($status == '000' || $status == '008')
		{
			if ($approved==false)
			{
				Mage::throwException("" . $txnResultCodeText);
			}
		}
		else
		{
			Mage::throwException("" . $txnResultCodeText);
		}

		return $this;
	}

	/**
	 * refund
	 *
	 * Processes a partial or whole refund on an existing transaction.
	 *
	 * @param Varien_Object $payment
	 * @param int/float $amount
	 *
	 * @return Mage_Payment_Model_Method_Cc $this. Failure will throw Mage::throwException('description')
	 */
	public function refund(Varien_Object $payment, $amount)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
		}
		
		$bankRespID = $payment->getCcTransId();
		
		if(!$bankRespID)
		{
			Mage::throwException("Cannot issue a refund on this transaction: bank response id is missing.");
		}
		
		//Create the transaction object
		$sxml = new securepay_xml_transaction ($this->getMode(),$this->getUsername(),$this->getPassword());
		
		$transaction_id = $payment->getOrder()->getIncrementId();
		
		//Issue a refund transaction
		$bankTxnID = $sxml->processCreditRefund($amount,$transaction_id,$bankRespID);
		
		$txnResultCodeText = $sxml->getErrorString();
		$approved = strtoupper($sxml->getResultByKeyName('approved'))=='YES'?true:false;
		$status = $sxml->getResultByKeyName('responseCode');
		
		/* Don't reset $payment->CcTransId for refunds, so that more than one is possible. This means that the gateway response id ($bankTxnID) is not stored here. If necessary, it can be recovered from the SecurePay Merchant Management Facility. http://securepay.com.au */
		
		if($this->getDebug())
		{
			if($approved)
			{
				$logger->info( "Refund Approved. Response ID: ".$bankTxnID );
			}
			else
			{
				$logger->info( "Refund Declined. ".$txnResultCodeText );
			}
		}
		
		if($status == '000' || $status == '008')
		{
			if ($approved==false)
			{
				Mage::throwException("" . $txnResultCodeText);
			}
		}
		else
		{
			Mage::throwException("" . $txnResultCodeText);
		}
		return $this;
	}
}
