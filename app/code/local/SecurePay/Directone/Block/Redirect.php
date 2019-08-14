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

class SecurePay_Directone_Block_Redirect extends Mage_Core_Block_Abstract
{
    protected function _toHtml()
    {
        $model = Mage::getModel('directone/directone');

        $form = new Varien_Data_Form();
        $form->setAction($model->getUrl())
            ->setId('directone_checkout')
            ->setName('directone_checkout')
            ->setMethod('POST')
            ->setUseContainer(true);
        foreach ($model->getCheckoutFormFields() as $field=>$value) {
            $form->addField($field, 'hidden', array('name'=>$field, 'value'=>$value));
        }
        $html = '<html><body>';
        $html.= $this->__('You will be redirected to DirectOne in a few seconds.');
        $html.= $form->toHtml();
        $html.= '<script type="text/javascript">document.getElementById("directone_checkout").submit();</script>';
        $html.= '</body></html>';

        return $html;
    }
}
