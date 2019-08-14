<?php

/**
 * securepay_xml_api.php:
 *
 * Contains a class for sending transaction requests to SecurePay,
 * and receiving responses from the SecurePay via the XML API
 * 
 * This class requires cURL to be available to PHP
 *
 */

define( 'SECUREPAY_GATEWAY_MODE_TEST',			 1);
define( 'SECUREPAY_GATEWAY_MODE_LIVE',			 2);
define( 'SECUREPAY_GATEWAY_MODE_PERIODIC_TEST',	 3);
define( 'SECUREPAY_GATEWAY_MODE_PERIODIC_LIVE',	 4);

/* The valid transaction types for this interface. */

define( 'SECUREPAY_TXN_STANDARD',		 0);
define( 'SECUREPAY_TXN_REFUND',			 4);
define( 'SECUREPAY_TXN_REVERSE',		 6);
define( 'SECUREPAY_TXN_PREAUTH', 		10); //To be included in a future revision
define( 'SECUREPAY_TXN_ADVICE', 		11);

define( 'SECUREPAY_TXN_DIRECTDEBIT',	15); //To be included in a future revision
define( 'SECUREPAY_TXN_DIRECTCREDIT', 	17);

define( 'SECUREPAY_TXN_IVR',		 	20);

define( 'SECUREPAY_REQ_ECHO', 		  'Echo'); //To be included in a future revision
define( 'SECUREPAY_REQ_PAYMENT',   'Payment');
define( 'SECUREPAY_REQ_PERIODIC', 'Periodic'); //To be included in a future revision

define( 'CURRENCY_DEFAULT',			 'AUD');

/**
 * securepay_xml_transaction
 *
 * This class handles XML SecurePay transactions
 *
 * It supports the following tranactions:
 * 		Credit Payment (standard)
 *		Credit Refund
 *		Credit Reversal
 *		Credit Preauthorisation
 *		Credit Preauthorised completion (Advice)
 *
 * It partially supports the following transactions (which are not yet required):
 *		Direct Entry Credit
 *		Direct Entry Debit
 *
 * It can support the following transactions in future:
 * 		Add Trigger/Peridic Payment
 *		Delete Trigger/Periodic Payment
 *		Trigger Triggered payment
 *
 * @param int mode - The kind of transaction object you would like to open. i.e. SECUREPAY_GATEWAY_MODE_TEST See top of this file for definitions.
 * @param string merchantID - The merchant's login ID, received from SecurePay
 * @param string merchantPW - The merchant's login password
 */

class securepay_xml_transaction
{
	const SECUREPAY_TEST_HOST = "https://www.securepay.com.au/test/payment";
	const SECUREPAY_LIVE_HOST = "https://www.securepay.com.au/xmlapi/payment";

	const TIMEOUT="60";

	const GATEWAY_ERROR_OBJECT_INVALID = "The Gateway Object is invalid (constructor failure?)";
	const GATEWAY_ERROR_CURL_ERROR = "CURL failed and reported the following error";
	const GATEWAY_ERROR_INVALID_CCNUMBER = "Parameter Check failure: Invalid credit card number";
	const GATEWAY_ERROR_INVALID_CCEXPIRY = "Parameter Check failure: Invalid credit card expiry date";
	const GATEWAY_ERROR_INVALID_CC_CVC = "Parameter Check failure: Invalid credit card verification code";
	const GATEWAY_ERROR_INVALID_TXN_AMT = "Parameter Check failure: Invalid transaction amount";
	const GATEWAY_ERROR_INVALID_REF_ID = "Parameter Check failure: Invalid transaction reference number";
	const GATEWAY_ERROR_INVALID_ACCOUNTNUMBER = "Parameter Check failure: Invalid account number";
	const GATEWAY_ERROR_INVALID_ACCOUNTNAME = "Parameter Check failure: Invalid account name";
	const GATEWAY_ERROR_INVALID_ACCOUNTBSB = "Parameter Check failure: Invalid BSB";
	const GATEWAY_ERROR_RESPONSE_ERROR = "A general response error was detected";
	const GATEWAY_ERROR_RESPONSE_INVALID = "A unspecified error was detected in the response content";
	const GATEWAY_ERROR_XML_PARSE_FAILED = "The response message could not be parsed (invalid XML?)";
	const GATEWAY_ERROR_RESPONSE_XML_MESSAGE_ERROR = "An unspecified error was found in the response message (missing field?)";
	const GATEWAY_ERROR_SECUREPAY_STATUS = "The remote Gateway reported the following status error";
	const GATEWAY_ERROR_TXN_DECLINED = "Transaction Declined";

	private $errorString;
	private $gatewayObjectValid = true;
	private $gatewayURL;
	private $merchantID;
	private $merchantPW;
	private $responseArray = array();
	private $txnType;
	
	private $ccNumber;
	private $ccVerify;
	private $ccExpiryMonth;
	private $ccExpiryYear;
	
	private $accNumber;
	private $accBSB;
	private $accName;
	
	private $txnReference;
	private $amount;
	
	private $currency=CURRENCY_DEFAULT;
	
	private $requestType;
	private $periodicType;
	private $periodicInterval;
	
	private $bankTxnID = 0;

	/**
	 * __construct
	 *
	 * @param integer $gatewaymode 
	 * @param string $setup_merchantID
	 * @param string $setup_merchantPW
	 *
	 */
	public function __construct( $gatewaymode, $setup_merchantID, $setup_merchantPW)
	{

		switch ( $gatewaymode )
		{
			case SECUREPAY_GATEWAY_MODE_TEST:
				$this->gatewayURL = self::SECUREPAY_TEST_HOST;
				break;

			case SECUREPAY_GATEWAY_MODE_LIVE:
				$this->gatewayURL = self::SECUREPAY_LIVE_HOST;
				break;

			default:
				$this->gatewayObjectValid = false;
				return;
		}

		if (	strlen( $setup_merchantID ) == 0
			||	strlen( $setup_merchantPW ) == 0 )
		{
			$this->gatewayObjectValid = false;
			return;
		}
		
		$this->setAuth($setup_merchantID,$setup_merchantPW);

	}
	
	/**
	 * reset
	 * 
	 * To clear response variables: prevents mismatched results in certain failure cases.
	 * This is called before each transaction, so be sure to check these values between transactions.
	 */
	public function reset()
	{
		$this->errorString = NULL;
		$this->responseArray = array();
		$this->bankTxnID = 0;
	}
	
	public function isGatewayObjectValid()
	{
		return $this->gatewayObjectValid;
	}

	public function getAmount()
	{
		return $this->amount;
	}
	
	/**
	 * setAmount
	 *
	 * Takes amount as a float; requires currency to be set
	 *
	 * @param float amount
	 */
	public function setAmount($amount)
	{
		if($this->getCurrency() == 'JPY')
			$this->amount = $amount;
		else
			$this->amount = round($amount*100,0);
		return;
	}
	
	public function getCurrency()
	{
		return $this->currency;
	}
	
	public function setCurrency($cur)
	{
		$this->currency = $cur;
		return;
	}
	
	public function getTxnReference()
	{
		return $this->txnReference;
	}
	
	public function setTxnReference($ref)
	{
		$this->txnReference = $ref;
		return;
	}

	public function getTxnType()
	{
		return $this->txnType;
	}
	
	public function setTxnType($type)
	{
		$this->txnType = $type;
		return;
	}
	
	public function getPreauthID()
	{
		return $this->preauthID;
	}
	
	public function setPreauthID($id)
	{
		$this->preauthID = $id;
		return;
	}
	
	public function getAccBSB()
	{
		return $this->accBSB;
	}
	
	public function setAccBSB($bsb)
	{
		$this->accBSB = $bsb;
		return;
	}
	
	public function getAccNumber()
	{
		return $this->accNumber;
	}
	
	public function setAccNumber($Number)
	{
		$this->accNumber = $Number;
		return;
	}

	public function getAccName()
	{
		return $this->accName;
	}
	
	public function setAccName($name)
	{
		$this->accName = $name;
		return;
	}
	
	public function getCCNumber()
	{
		return $this->ccNumber;
	}
	
	public function setCCNumber($ccNumber)
	{
		$this->ccNumber = $ccNumber;
		return;
	}
	
	public function getClearCCNumber()
	{
		$t = $this->getCCNumber();
		$this->setCCNumber("0");
		return $t;
	}
	
	public function getCCVerify()
	{
		return $this->ccVerify;
	}
	
	public function setCCVerify($ver)
	{
		$this->ccVerify = $ver;
		return;
	}
	
	public function getClearCCVerify()
	{
		$t = $this->getCCVerify();
		$this->setCCVerify(0);
		return $t;
	}
	
	/* @return string month MM*/
	public function getCCExpiryMonth()
	{
		return $this->ccExpiryMonth;
	}
	
	/* @param string/int month MM or month M - If there are leading zeros, type needs to be a string*/
	public function setCCExpiryMonth($month)
	{
		$l = strlen(trim($month));
		if($l == 1)
			$this->ccExpiryMonth = sprintf("%02d",ltrim($month,'0'));
		else
			$this->ccExpiryMonth = $month;
		return;
	}

	/* @return string year YY*/
	public function getCCExpiryYear()
	{
		return $this->ccExpiryYear;
	}
	
	/* @param string year YY or year YYYY - If there are leading zeros, type needs to be a string*/
	public function setCCExpiryYear($year)
	{
		$y = ltrim(trim((string)$year),"0");
		$l = strlen($y);
		if($l==4)
			$this->ccExpiryYear = substr($y,2);
		else if($l>=5)
			$this->ccExpiryYear = 0;
		else if($l==1)
			$this->ccExpiryYear = sprintf("%02d",$y);
		else
			$this->ccExpiryYear = $year;
		
		return;
	}
	
	public function getMerchantID()
	{
		return $this->merchantID;
	}
	
	public function setMerchantID($id)
	{
		$this->merchantID = $id;
		return;
	}
	
	public function getMerchantPW ()
	{
		return $this->merchantPW;
	}
	
	public function setMerchantPW ($pw)
	{
		$this->merchantPW = $pw;
		return;
	}
	
	public function getBankTxnID ()
	{
		return $this->bankTxnID;
	}
	
	public function setBankTxnID ($id)
	{
		$this->bankTxnID = $id;
		return;
	}
	
	public function getRequestType ()
	{
		return $this->requestType;
	}

	public function setRequestType ($t)
	{
		$this->requestType = $t;
		return;
	}
	
	public function getPeriodicType ()
	{
		return $this->periodicType;
	}

	public function setPeriodicType ($t)
	{
		$this->periodicType = $t;
		return;
	}
	
	public function getPeriodicInterval ()
	{
		return $this->periodicInterval;
	}

	public function setPeriodicInterval ($t)
	{
		$this->periodicInterval = $t;
		return;
	}
	
	public function getErrorString ()
	{
		return $this->errorString;
	}
	
	public function getResultArray ()
	{
		return $this->responseArray;
	}

	public function getResultByKeyName ( $keyName)
	{
		if ( array_key_exists( $keyName, $this->responseArray) === true )
		{
			return $this->responseArray[$keyName];
		}
		else
			return false;
	}
	
	public function getTxnWasSuccesful()
	{
		if (	array_key_exists( "txnResult", $this->responseArray) === true 
			&& 	$this->responseArray["txnResult"] === true )
			return true;
		else
			return false;
	}
	
	public function setAuth($id, $pw)
	{
		$this->setMerchantID($id);
		$this->setMerchantPW($pw);
		return;
	}
	
	/**
	 * processCreditStandard:
	 *
	 * Process a standard credit card payment
	 *
	 * @param float amount - Numeric and decimal only: no thousand separators
	 * @param string txnReference - Merchant's unique transaction ID
	 * @param int cardNumber - 12-18 digit credit-card number
	 * @param int cardMonth - 2 digit month
	 * @param int cardYear - 2 or 4 digit year
	 * @param int cardVerify - 3 or 4 digit CVV (optional)
	 * @param string currency - Exactly three characters. See SecurePay documentation for list of valid currencies. (optional)
	 *
	 * @return string txnID - Bank's unique transaction ID (use for reversal or refund), or FALSE in case of failure (check $this->getErrorText() afterwards).
	 */
	public function processCreditStandard($amount, $txnReference, $cardNumber, $cardMonth, $cardYear, $cardVerify=0, $currency=CURRENCY_DEFAULT)
	{
		$this->reset();
		
		$this->setTxnType(SECUREPAY_TXN_STANDARD);
		
		$this->setAmount($amount);
		$this->setTxnReference($txnReference);
		$this->setCCNumber($cardNumber);
		if(strlen($cardVerify)!=0)
			$this->setCCVerify($cardVerify);
		$this->setCCExpiryYear($cardYear);
		$this->setCCExpiryMonth($cardMonth);
		if($currency)
			$this->setCurrency($currency);
		
		if($this->processTransaction());
			if(array_key_exists('banktxnID',$this->responseArray))
				return $this->responseArray['banktxnID'];
		return false;
	}

	/**
	 * processCreditRefund:
	 *
	 * Refund a standard credit card payment. $amount can be less than the original transaction.
	 *
	 * @param float amount - Numeric and decimal only: no thousand separators
	 * @param string txnReference - Merchant's unique transaction ID: must be same as in initial transaction
	 * @param int txnID - Result of original transaction
	 *
	 * @return string txnID - Bank's unique transaction ID, or FALSE in case of failure (check $this->getErrorText() afterwards).
	 */
	public function processCreditRefund($amount, $txnReference, $txnID)
	{
		$this->reset();
		
		$this->setTxnType(SECUREPAY_TXN_REFUND);
		
		$this->setAmount($amount);
		$this->setTxnReference($txnReference);
		
		$this->setBankTxnID($txnID);
		
		if($this->processTransaction());
			if(array_key_exists('banktxnID',$this->responseArray))
				return $this->responseArray['banktxnID'];
		return false;
	}

	/**
	 * processCreditReverse:
	 *
	 * Reverse a standard credit card payment. $amount should be same as in original transaction.
	 *
	 * @param float amount - Numeric and decimal only: no thousand separators
	 * @param string txnReference - Merchant's unique transaction ID: must be same as in initial transaction
	 * @param int txnID - Result of original transaction
	 *
	 * @return string txnID - Bank's unique transaction ID, or FALSE in case of failure (check $this->getErrorText() afterwards).
	 */
	public function processCreditReverse($amount, $txnReference, $txnID)
	{
		$this->reset();
		
		$this->setTxnType(SECUREPAY_TXN_REVERSE);
		
		$this->setAmount($amount);
		$this->setTxnReference($txnReference);
		
		$this->setBankTxnID($txnID);
		
		if($this->processTransaction());
			if(array_key_exists('banktxnID',$this->responseArray))
				return $this->responseArray['banktxnID'];
		return false;
	}

	/**
	 * processCreditPreauth:
	 *
	 * Preauthorise a credit card payment
	 *
	 * @param float amount - Numeric and decimal only: no thousand separators
	 * @param string txnReference - Merchant's unique transaction ID
	 * @param int cardNumber - 12-18 digit credit-card number
	 * @param int cardMonth - 2 digit month
	 * @param int cardYear - 2 or 4 digit year
	 * @param int cardVerify - 3 or 4 digit CVV (optional)
	 * @param string currency - Exactly three characters. See SecurePay documentation for list of valid currencies. (optional)
	 *
	 * @return string preauthID - preauthorisation ID (use to execute transaction later (processCreditAdvice)), or FALSE (check $this->getErrorText() afterwards).
	 */
	public function processCreditPreauth($amount, $txnReference, $cardNumber, $cardMonth, $cardYear, $cardVerify=0, $currency=CURRENCY_DEFAULT)
	{
		$this->reset();
		
		$this->setTxnType(SECUREPAY_TXN_PREAUTH);
		
		$this->setAmount($amount);
		$this->setTxnReference($txnReference);
		$this->setCCNumber($cardNumber);
		if(strlen($cardVerify)!=0)
			$this->setCCVerify($cardVerify);
		$this->setCCExpiryYear($cardYear);
		$this->setCCExpiryMonth($cardMonth);
		
		if($currency)
			$this->setCurrency($currency);
		
		if($this->processTransaction())
		{
			if(array_key_exists('preauthID',$this->responseArray))
				return $this->responseArray['preauthID'];
		}
		
		return false;
	}

	/**
	 * processCreditAdvice:
	 *
	 * Execute a preauthorised transaction
	 *
	 * @param float amount - Numeric and decimal only: no thousand separators. Should be same as preauthorised amount.
	 * @param string txnReference - Merchant's unique transaction ID: must be same as in initial transaction
	 * @param string preauthID - Preauthorisation code which was returned from processCreditPreauth
	 *
	 * @return string txnID - Bank's unique transaction ID, or FALSE in case of failure (check $this->getErrorText() afterwards).
	 */
	public function processCreditAdvice($amount, $txnReference, $preauthID)
	{
		$this->reset();
		
		$this->setTxnType(SECUREPAY_TXN_ADVICE);
		
		$this->setAmount($amount);
		$this->setTxnReference($txnReference);
		$this->setPreauthID($preauthID);
		
		if($this->processTransaction());
			if(array_key_exists('banktxnID',$this->responseArray))
				return $this->responseArray['banktxnID'];
		return false;
	}
	
	/*
	//Disabled for the time being: not required yet
	public function processDirectCredit($amount, $txnReference, $accName, $accBSB, $accNumber)
	{
		$this->reset();
		
		$this->setTxnType(SECUREPAY_TXN_DIRECTCREDIT);

		$this->setAmount($amount);
		$this->setTxnReference($txnReference);
		$this->setAccName($accName);
		$this->setAccNumber($accNumber);
		$this->setAccBSB($accBSB);

		return $this->processTransaction();
	}
	
	public function processDirectDebit($amount, $txnReference, $accName, $accBSB, $accNumber)
	{
		$this->reset();
		
		$this->setTxnType(SECUREPAY_TXN_DIRECTDEBIT);

		$this->setAmount($amount);
		$this->setTxnReference($txnReference);
		$this->setAccName($accName);
		$this->setAccNumber($accNumber);
		$this->setAccBSB($accBSB);
		
		return $this->processTransaction();
	}
	*/
	
	/**
	 * processTransaction:
	 *
	 * this function attempts to process a payment  transaction using the
	 * supplied details on the SecurePay SecureXML Gateway
	 * 
	 * @return boolean Returns true for succesful (approved) transaction / false for failure (declined) or error
	 *
	 */
	private function processTransaction ()
	{
		// check that self is a valid gateway object
		if ( !$this->gatewayObjectValid )
		{
			$this->errorString = self::GATEWAY_ERROR_OBJECT_INVALID;
			return false;
		}

		// check parameters
		if( $this->getTxnType()==SECUREPAY_TXN_STANDARD ||
			$this->getTxnType()==SECUREPAY_TXN_PREAUTH )
		{
			if ($this->checkCCparameters() == false)
				return false;
		}
		else if ( $this->getTxnType()==SECUREPAY_TXN_DIRECTDEBIT ||
				  $this->getTxnType()==SECUREPAY_TXN_DIRECTCREDIT )
		{
			if ($this->checkDirectparameters() == false)
				return false;
		}
		if ($this->checkTxnParameters() == false)
		{
			return false;
		}

		// create request message. This function will retrieve and destroy CC details, if we're in credit-card mode (!)
		$requestMessage = $this->createXMLTransactionRequestString();

		$this->responseArray["raw-XML-request"] = htmlentities($requestMessage);

		// send request
		$response = $this->sendRequest( $this->gatewayURL, $requestMessage );

		$this->responseArray["raw-response"] = htmlentities($response);
		
		// was a response received?
		if ( $response === false )
		{
			if ( strlen( $this->errorString ) == 0 )
			{
				$this->errorString = self::GATEWAY_ERROR_RESPONSE_ERROR;
			}
			return false;
		}

		// process response for validity
		if ( $this->processTransactionResponseMessageIntoResponseArray( $response ) === false )
		{
			if ( strlen( $this->errorString ) == 0 )
			{
				$this->errorString = self::GATEWAY_ERROR_RESPONSE_INVALID;
			}
			return false;
		}

		// if we get this far, the transaction is succesful and "approved"
		$this->responseArray["txnResult"] = true;

		return true;
	}


	/**
	 * checkCCparameters
	 *
	 * Check the input parameters are valid for a credit card transaction
	 * 
	 * @return boolean Return TRUE for all checks passed OK, or FALSE if an error is detected
	 */
	private function checkCCparameters()
	{
		// the string ccNumber must be all numeric, and between 12 and 19 digits long
		if (strlen( $this->getCCNumber() ) < 12 ||
			strlen( $this->getCCNumber() ) > 19 ||
			preg_match("/\D/",$this->getCCNumber()) )// REGEXP: true if "any match for non-numeral"
		{
			$this->errorString = self::GATEWAY_ERROR_INVALID_CCNUMBER;
			return false;
		}
		
		// the string $ccExpiryMonth must be all numeric with value between 1 and 12
		if (preg_match("/\D/", $this->getCCExpiryMonth()) || // REGEXP: true if "any match for non-numeral"
			(int) $this->getCCExpiryMonth() < 1 ||
			(int) $this->getCCExpiryMonth() > 12 )
		{
			$this->errorString = self::GATEWAY_ERROR_INVALID_CCEXPIRY;
			return false;
		}

		// the string $ccExpiryYear must be all numeric with value between this year and this year + 12 years
		if (preg_match( "/\D/", $this->getCCExpiryYear()) || // REGEXP: true if "any match for non-numeral"
			(strlen($this->getCCExpiryYear()) != 2) || //YY form
			(int) $this->getCCExpiryYear() < (int) substr(date("Y"),2) || // Between now and now + 12
			(int) $this->getCCExpiryYear() > ( (int) substr(date("Y"),2) + 12 ) )
		{
			$this->errorString = self::GATEWAY_ERROR_INVALID_CCEXPIRY;
			return false;
		}
		
		// The CVC is an optional data item so only perform the checks if the parameter was present
		if ( strlen( $this->getCCVerify() ) != 0 )
		{
			// the string $ccVericationNumber must be all numeric with value between 000 and 9999
			if (preg_match( "/\D/", $this->getCCVerify() ) || // REGEXP: true if "any match for non-numeral"
				strlen( $this->getCCVerify() ) < 3 ||
				strlen( $this->getCCVerify() ) > 4 ||
				(int) $this->getCCVerify() < 0 ||
				(int) $this->getCCVerify() > 9999 )
			{
				$this->errorString = self::GATEWAY_ERROR_INVALID_CC_CVC;
				return false;
			}
		}
		return true;
	}

	/**
	 * checkCCparameters
	 *
	 * Check the input parameters are valid for a credit card transaction
	 * 
	 * @return boolean Return TRUE for all checks passed OK, or FALSE if an error is detected
	 */
	private function checkDirectparameters()
	{
		// the string accNumber must be all numeric, and between 12 and 19 digits long
		if (preg_match( "/\D/", $this->getAccNumber() ) )// REGEXP: true if "any match for non-numeral"
		{
			$this->errorString = self::GATEWAY_ERROR_INVALID_ACCOUNTNUMBER;
			return false;
		}
		
		// the string $accName must be all numeric with value between 1 and 12
		if (	preg_match( "/\D/", $this->getAccName() )) // REGEXP: true if "any match for non-numeral"
		{
			print "Month\n";
			$this->errorString = self::GATEWAY_ERROR_INVALID_ACCOUNTNAME;
			return false;
		}
		// the string $accBSB must be all numeric with value between 000 and 9999
		if (preg_match( "/\D/", $this->getAccBSB())) // REGEXP: true if "any match for non-numeral"
		{
			$this->errorString = self::GATEWAY_ERROR_INVALID_BSB;
			return false;
		}
		
		return true;
	}

	/**
	 * checkTxnParameters
	 *
	 * Check that the transaction input parameters are within requirements
	 *
	 * @param string $txnAmount
	 * @param string $txnReference
	 *
	 * @return TRUE for pass, FALSE for fail
	 */
	private function checkTxnParameters ()
	{
		$amount = $this->getAmount();
		if (preg_match( "/^[0-9]/", $amount)==false || (int) $amount < 0 )
		{
			$this->errorString = self::GATEWAY_ERROR_INVALID_TXN_AMT;
			return false;
		}
		
		$ref = $this->getTxnReference();
		if ( $this->getTxnType()==SECUREPAY_TXN_DIRECTDEBIT ||
			 $this->getTxnType()==SECUREPAY_TXN_DIRECTCREDIT )
		{
			// Direct Entry Payment References need to conform to EBCDIC, and should be <= 18 characters
			if (strlen($ref) == 0 || strlen($ref)>18 ||
				preg_match('/[^0-9a-zA-Z*\.&\/-_\']/', $ref)) // REGEXP: match any non-EBCDIC character
			{
				$this->errorString = self::GATEWAY_ERROR_INVALID_REF_ID;
				return false;
			}
		}
		else
		{
			// Standard/Credit References can have any character except space and single quote
			if (strlen($ref) == 0 || strlen($ref)>59 ||
				preg_match('/[^ \']/', $ref)==false) // REGEXP: match invalid characters
			{
				$this->errorString = self::GATEWAY_ERROR_INVALID_REF_ID;
				return false;
			}
		}	
		return true;
	}
	
    /**
	 * createXMLTransactionRequestString:
	 * Creates the XML request string for a transaction request message
	 *
	 * Note: calls to getClearCCNumber & getClearCCVerify: details are removed from their respective private variables here
     * 
	 * @return string xml_transaction
     */
	private function createXMLTransactionRequestString()
	{
		$x =
		"<?xml version=\"1.0\" encoding=\"UTF-8\"?>".
		"<SecurePayMessage>" .
			"<MessageInfo>" .
				"<messageID>".htmlentities($this->getTxnReference().date("his").current(split(' ',microtime( ))))."</messageID>".
				"<messageTimestamp>".htmlentities($this->getGMTTimeStamp())."</messageTimestamp>".
				"<timeoutValue>".self::TIMEOUT."</timeoutValue>".
				"<apiVersion>xml-4.2</apiVersion>" .
			"</MessageInfo>".
			"<MerchantInfo>".
				"<merchantID>".htmlentities($this->getMerchantID())."</merchantID>" .
				"<password>".htmlentities($this->getMerchantPW())."</password>" .
			"</MerchantInfo>".
			"<RequestType>Payment</RequestType>".
			"<Payment>".
				"<TxnList count=\"1\">".
					"<Txn ID=\"1\">".
						"<txnType>".htmlentities($this->getTxnType())."</txnType>".
						"<txnSource>23</txnSource>".
						"<amount>".htmlentities($this->getAmount())."</amount>";
			if(	($this->getTxnType()==SECUREPAY_TXN_STANDARD ||
				 $this->getTxnType()==SECUREPAY_TXN_PREAUTH ) &&
				$this->getCurrency()!=CURRENCY_DEFAULT)
			{
				$x .=	"<currency>".htmlentities($this->getCurrency())."</currency>";
			}
			$x .=		"<purchaseOrderNo>".htmlentities($this->getTxnReference())."</purchaseOrderNo>";
			if(	$this->getTxnType()==SECUREPAY_TXN_ADVICE)
			{
				$x .=	"<preauthID>".htmlentities($this->getPreauthID())."</preauthID>";
			}
			if(	$this->getTxnType()==SECUREPAY_TXN_REFUND ||
				$this->getTxnType()==SECUREPAY_TXN_REVERSE &&
				$this->getBankTxnID() != 0)
			{
				$x .=	"<txnID>".htmlentities($this->getBankTxnID())."</txnID>";
			}
			
			if(	$this->getTxnType()==SECUREPAY_TXN_STANDARD	||
				$this->getTxnType()==SECUREPAY_TXN_PREAUTH )
			{
				$x .=	"<CreditCardInfo>".
							"<cardNumber>".htmlentities($this->getClearCCNumber())."</cardNumber>";
				if (trim($this->getCCVerify()) <> "")
				{
					$x .=	"<cvv>".htmlentities($this->getClearCCVerify())."</cvv>";
				}
				$x .=		"<expiryDate>".htmlentities(sprintf("%02d",$this->getCCExpiryMonth())."/".sprintf("%02d",$this->getCCExpiryYear()))."</expiryDate>".
						"</CreditCardInfo>";
			}
			else if ( $this->getTxnType()==SECUREPAY_TXN_DIRECTDEBIT ||
					  $this->getTxnType()==SECUREPAY_TXN_DIRECTCREDIT )
			{
				$x .=	"<DirectEntryInfo>".
							"<bsbNumber>".htmlentities($this->getAccBSB())."</bsbNumber>".
							"<accountNumber>".htmlentities($this->getAccNumber())."</accountNumber>".
							"<accountName>".htmlentities($this->getAccName())."</accountName>".
						"</DirectEntryInfo>";
			}
			$x .=	"</Txn>".
				"</TxnList>".
			"</Payment>".
		"</SecurePayMessage>";
		
		return $x;
	}


	/**
	* getGMTTimeStamp:
	* 
	* this function creates a timestamp formatted as per requirement in the
	* SecureXML documentation
	*
	* @return string The formatted timestamp
	*/
	private function getGMTTimeStamp()
	{
		/* Format: YYYYDDMMHHNNSSKKK000sOOO
			YYYY is a 4-digit year
			DD is a 2-digit zero-padded day of month
			MM is a 2-digit zero-padded month of year (January = 01)
			HH is a 2-digit zero-padded hour of day in 24-hour clock format (midnight =0)
			NN is a 2-digit zero-padded minute of hour
			SS is a 2-digit zero-padded second of minute
			KKK is a 3-digit zero-padded millisecond of second
			000 is a Static 0 characters, as SecurePay does not store nanoseconds
			sOOO is a Time zone offset, where s is “+” or “-“, and OOO = minutes, from GMT.
		*/
		

		$val = date("Z") / 60;
		if ($val >= 0)
		{
			$val = "+" . strval($val);
		}

		$stamp = date("YdmGis000000") . $val;

		return $stamp;
	}

	/**
	 * sendRequest: 
	 * uses cURL to open a Secure Socket connection to the gateway,
	 * sends the transaction request and then returns the response
	 * data
	 * 
	 * @param $postURL The URL of the remote gateway to which the request is sent
	 * @param $requestMessage
	 */
	private function sendRequest( $postURL, $requestMessage )
	{
		$ch = curl_init();

		// Set up curl parameters
		curl_setopt( $ch, CURLOPT_URL, $postURL );			// set remote address
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );		// Make CURL pass the response as a curl_exec return value instead of outputting to STDOUT
		curl_setopt( $ch, CURLOPT_POST, 1 );	 			// Activate the POST method
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );

		curl_setopt( $ch, CURLOPT_POSTFIELDS, $requestMessage );	// add the request message itself
		
		// execute the connexion
		$result = curl_exec( $ch );

		$debugoutput = curl_getinfo($ch);
		$curl_error_message = curl_error( $ch ); // must retrieve an error message (if any) before closing the curl object 

		curl_close($ch);


		if ( $result === false )
		{
			$this->errorString = self::GATEWAY_ERROR_CURL_ERROR.': '.$curl_error_message;
			return false;
		}

		// we do not need the header part of the response, trim it off the result
		$pos = strstr( $result, "\n" );
		$result = substr( $result, $pos );

		return $result;
	}
	
	/**
	 * processTransactionResponseMessageIntoResponseArray: 
	 * converts the response XML message into a nested array structure and then 
	 * pulls out the relevant data into a simplified result array 
	 * 
	 * @param string $responseMessage - An XML response from the gateway
	 * @return boolean True to indicate succesful decoding of response message AND succesful txn result, false to indicate an error or declined result
	 */
	private function processTransactionResponseMessageIntoResponseArray ( $responseMessage )
	{
		$xmlres = array();
		$xmlres = $this->convertXMLToNestedArray( $responseMessage );

		if ( $xmlres === false )
		{
			if ( strlen( $this->errorString ) == 0 )
			{
				$this->errorString = self::GATEWAY_ERROR_RESPONSE_XML_MESSAGE_ERROR;
			}
			return false;
		}

		$responseArray["raw-XML-response"] = htmlentities($responseMessage);

		$statusCode = trim( $xmlres['SecurePayMessage']['Status']['statusCode'] );
		$statusDescription = trim($xmlres['SecurePayMessage']['Status']['statusDescription']);
	
		$responseArray["statusCode"] = $statusCode;
		$responseArray["statusDescription"] = $statusDescription;

		// Three digit codes indicate a repsonse from the Securepay gateway (error detected by gateway) 
		if ( strcmp( $statusCode, '000' ) != 0 )
		{
			$this->errorString = self::GATEWAY_ERROR_SECUREPAY_STATUS.": ".$statusCode." ".$statusDescription;
			return false;
		}

		$responseArray["messageID"] = trim($xmlres['SecurePayMessage']['MessageInfo']['messageID']);
		$responseArray["messageTimestamp"] = trim($xmlres['SecurePayMessage']['MessageInfo']['messageTimestamp']);
		$responseArray["apiVersion"] = trim($xmlres['SecurePayMessage']['MessageInfo']['apiVersion']);
		$responseArray["RequestType"] =  trim($xmlres['SecurePayMessage']['RequestType']);
		$responseArray["merchantID"] = trim($xmlres['SecurePayMessage']['MerchantInfo']['merchantID']);
		$responseArray["txnType"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['txnType']);
		$responseArray["txnSource"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['txnSource']);
		$responseArray["amount"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['amount']);
		$responseArray["approved"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['approved']);
		$responseArray["responseCode"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['responseCode']);
		$responseArray["responseText"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['responseText']);
		$responseArray["banktxnID"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['txnID']);
		$responseArray["settlementDate"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['settlementDate']);
		
		if( $this->getTxnType()==SECUREPAY_TXN_PREAUTH && array_key_exists('preauthID',$xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']))
		{
			$responseArray["preauthID"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['preauthID']);
		}
		if($this->getRequestType() == SECUREPAY_REQ_PERIODIC)
		{
			if(	$this->getTxnType()==SECUREPAY_TXN_STANDARD	||
				$this->getTxnType()==SECUREPAY_TXN_PREAUTH )
			{
				$responseArray["creditCardPAN"] = trim($xmlres['SecurePayMessage']['Periodic']['PeriodicList']['PeriodicItem']['CreditCardInfo']['pan']);
				$responseArray["expiryDate"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['CreditCardInfo']['expiryDate']);
			}
		}
		else if (strtoupper($responseArray['approved']) == 'YES' &&
				($this->getTxnType()==SECUREPAY_TXN_DIRECTDEBIT ||
				 $this->getTxnType()==SECUREPAY_TXN_DIRECTCREDIT) )
		{
			$responseArray["bsbNumber"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['DirectEntryInfo']['bsbNumber']);
			$responseArray["accountNumber"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['DirectEntryInfo']['accountNumber']);
			$responseArray["accountName"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['DirectEntryInfo']['accountName']);
		}
		else if ($this->getRequestType() == SECUREPAY_REQ_PAYMENT)
		{
			$responseArray["creditCardPAN"] = trim($xmlres['SecurePayMessage']['Periodic']['PeriodicList']['PeriodicItem']['CreditCardInfo']['pan']);
			$responseArray["expiryDate"] = trim($xmlres['SecurePayMessage']['Payment']['TxnList']['Txn']['CreditCardInfo']['expiryDate']);
		}
		$this->responseArray = $responseArray;

		/* field "successful" = "Yes" means "triggered transaction successfully registered", anything else is failure */
		/* responseCodes:
			"00" indicates approved,
			"08" is Honor with ID (approved) and
			"77" is Approved (ANZ only).
			Any other 2 digit code is a decline or error from the bank. */
			
		if ((strcasecmp( $responseArray["approved"], "Yes" ) == 0) &&
			(strcmp( $responseArray["responseCode"], "00" ) === 0 ||
			 strcmp( $responseArray["responseCode"], "08" ) === 0 ||
			 strcmp( $responseArray["responseCode"], "77" ) === 0 ))
		{
			return true;
		}
		else
		{
			$this->errorString = self::GATEWAY_ERROR_TXN_DECLINED." (".$responseArray["responseCode"]."): ".$responseArray["responseText"];
			return false;
		}
	}


	/**
	 * convertXMLToNestedArray: 
	 * converts an XML document into a nested array structure 
	 * 
	 * @param string $XMLDocument An XML document
	 * @return boolean True to indicate succesful conversion of document, false to indicate an error 
	 */
	private function convertXMLToNestedArray ( $XMLDocument )
	{

		$output = array();

		$parser = xml_parser_create();

		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		$parse_result = xml_parse_into_struct($parser, $XMLDocument, $values);

		if ( $parse_result === 0)
		{
			$this->errorString = self::GATEWAY_ERROR_XML_PARSE_FAILED.": ".xml_get_error_code ( $parser )." ".xml_error_string (xml_get_error_code ( $parser ) );
			xml_parser_free($parser);

			return false;
		}
	
		xml_parser_free($parser);

		$hash_stack = array();

		foreach ($values as $val)
		{
			switch ($val['type'])
			{
				case 'open':
					array_push($hash_stack, $val['tag']);
					break;

				case 'close':
					array_pop($hash_stack);
					break;

				case 'complete':
					array_push($hash_stack, $val['tag']);
					if ( array_key_exists('value', $val) ) 
						eval("\$output['" . implode($hash_stack, "']['") . "'] = \"{$val['value']}\";");
					else // to handle empty self closing tags i.e. <paymentInterval/>
						eval("\$output['" . implode($hash_stack, "']['") . "'] = null;");
					array_pop($hash_stack);
					break;
			}
		}
		return $output;
	}	
}

?>
