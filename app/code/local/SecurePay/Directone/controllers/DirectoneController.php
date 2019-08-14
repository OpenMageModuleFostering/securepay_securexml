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
 * Directone Checkout Controller
 *
 * Handles all of the urls under index.php/directone/directone/
 */
class SecurePay_Directone_DirectoneController extends Mage_Core_Controller_Front_Action
{
	protected function _expireAjax()
	{
		if (!Mage::getSingleton('checkout/session')->getQuote()->hasItems())
		{
			$this->getResponse()->setHeader('HTTP/1.1','403 Session Expired');
			exit;
		}
	}

	/**
	 * When a customer chooses Directone on Checkout/Payment page
	 */
	public function redirectAction()
	{
		$session = Mage::getSingleton('checkout/session');
		$session->setDirectoneQuoteId($session->getQuoteId());
		$this->getResponse()->setBody($this->getLayout()->createBlock('directone/redirect')->toHtml());
		$session->unsQuoteId();
	}

	/**
	 * Notification serves as an additional level of payment confirmation. It only works on public-facing servers (DirectOne needs to issue a http request to your server).
	 *
	 * In order to trigger a notification, the order id and correct amount (in cents) must be delivered here via GET.
	 *
	 * Details of notifications against valid order_ids will be displayed on the order's summary page, in the Comments History, including the notifier's ip and [hostname].
	 *
	 * Merchants will need to check the hostname to make sure that the notification came from DirectOne. Notifications coming from elsewhere (e.g. the customer) are a good sign of attempted fraud. Generally, merchants should confirm payment before sending items.
	 */
	public function notifyAction()
	{
		$orderData = array();
		
		$g_order = (isset($_GET['Invoice'])?$_GET['Invoice']:0);
		$g_amount = (isset($_GET['response_amount'])?$_GET['response_amount']:0);
		
		preg_match('/(?<invoice>[0-9\-]+) (?<total>[0-9]+)/', $g_order.' '.$g_amount, $orderData);
		$invoice = $orderData['invoice'];
		$amount = ($orderData['total']/100);
		
		$ip = $_SERVER['REMOTE_ADDR'];
		$host = gethostbyaddr($ip);
		
		if($invoice && $amount)
		{
			$order = Mage::getModel('sales/order');
			$order->load($invoice);
			
			if($order->grand_total)
			{
				$notified_state = Mage::getSingleton('directone/directone')->getConfigData('notify_status');
				$original_state = $order->getState();
				
				$message = 'DirectOne callback received from '.$ip.' ['.$host.']';
				
				if($amount == $order->grand_total)
				{
					$message .= ' for the correct amount of $'.$amount.'.';
					$state = $notified_state;
				}
				else
				{
					$message .= ' for an incorrect amount of $'.$amount.' rather than $'.$order->grand_total.'.';
					$state = $original_state;
				}
				
				$order->addStatusToHistory($state,$message,false);
				$order->save();
			}
		}
	}

	/**
	 * Return page from Directone.
	 * Always shows success and triggers customer notification (email), but does not modify any of the payment records.
	 */
	public function successAction()
	{
		$session = Mage::getSingleton('checkout/session');
		$session->setQuoteId($session->getDirectoneQuoteId(true));
		
		//Set the quote as inactive after returning from Directone
		Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
		
		//Send a confirmation email to customer
		$order = Mage::getModel('sales/order');
		$order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
		if($order->getId())
		{
			$order->sendNewOrderEmail();
		}
		
		Mage::getSingleton('checkout/session')->unsQuoteId();
		
		$this->_redirect('checkout/onepage/success');
	}
}
