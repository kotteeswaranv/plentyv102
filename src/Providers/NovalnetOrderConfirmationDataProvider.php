<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the GNU General Public License
 *
 * @author Novalnet <technic@novalnet.de>
 * @copyright Novalnet
 * @license GNU General Public License
 *
 * Script : NovalnetOrderConfirmationDataProvider.php
 *
 */

namespace Novalnet\Providers;

use Plenty\Plugin\Templates\Twig;

use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Comment\Contracts\CommentRepositoryContract;
use \Plenty\Modules\Authorization\Services\AuthHelper;

/**
 * Class NovalnetOrderConfirmationDataProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetOrderConfirmationDataProvider
{
    /**
     * Setup the Novalnet transaction comments for the requested order
     *
     * @param Twig $twig
     * @param Arguments $arg
     * @return string
     */
    public function call(Twig $twig, $args)
    {
        $paymentHelper = pluginApp(PaymentHelper::class);
        $paymentMethodId = $paymentHelper->getPaymentMethod();
        $order = $args[0];
        $paymentHelper->testLogTest('CHECK',$order);
        $paymentHelper->testLogTest('CHECK2',$order->properties);
        $paymentHelper->testLogTest('CHECK3',$order['properties']);
       // if(isset($order->order))
        //    $order = $order->order;
        
        //$properties = !empty($order->properties) ? $order->properties : $order['properties'];
        $properties = $order->properties;//!empty($order->properties) ? $order->properties : $order['properties'];
        $paymentHelper->testLogTest('CHECK4FINAL',$properties);

        foreach($properties as $property)
        {
            $property = (object)$property;
            $paymentHelper->testLogTest('CHECKKKK','test'); 
            $paymentHelper->testLogTest('CHECKOBJ',is_string($property));                 
            $paymentHelper->testLogTest('CHECKOBJVAL',$property->value);                
            $paymentHelper->testLogTest('CHECKOBJTYPE',$property->typeId);
            //if($property->typeId == '3' && $property->value == $paymentMethodId)
            if($property->typeId == 3)
            {
                $paymentHelper->testLogTest('CHECK5VAL',$property->value);                
                //$orderId = (int) $order->id;
                $orderId = (int) $property->orderId;

                $authHelper = pluginApp(AuthHelper::class);
                $orderComments = $authHelper->processUnguarded(
                        function () use ($orderId) {
                            $commentsObj = pluginApp(CommentRepositoryContract::class);
                            $commentsObj->setFilters(['referenceType' => 'order', 'referenceValue' => $orderId]);
                            return $commentsObj->listComments();
                        }
                );
                $paymentHelper->testLogTest('CHECK7CMD',$orderId);
                $paymentHelper->testLogTest('CHECK6CMD',$orderComments);
            $paymentHelper->testLogTest('CHECK8CMD',$order->id);
                $comment = '';
                foreach($orderComments as $data)
                {
                    $comment .= (string)$data->text;
                    $comment .= '</br>';
                }

                return $twig->render('Novalnet::NovalnetOrderHistory', ['comments' => html_entity_decode($comment)]);
            }
        }
    }
}
