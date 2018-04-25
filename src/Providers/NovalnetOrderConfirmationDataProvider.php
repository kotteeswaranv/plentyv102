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
use Plenty\Modules\Order\Models\Order;

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
        $paymentHelper->testLogTest($paymentMethodId);
        //if(isset($order->order))
         //   $order = $order->order;
if($order) {
    $paymentHelper->testLogTest($order);
        foreach($order->properties as $property)
        {
            $paymentHelper->testLogTest($property);
            if($property->typeId == '3' && $property->value == $paymentMethodId){
                $paymentHelper->testLogTest($paymentMethodId);
          //  if($property->typeId == '3')
        //    {
                $orderId = (int) $order->id;

                $authHelper = pluginApp(AuthHelper::class);
                $orderComments = $authHelper->processUnguarded(
                        function () use ($orderId) {
                            $commentsObj = pluginApp(CommentRepositoryContract::class);
                            $commentsObj->setFilters(['referenceType' => 'order', 'referenceValue' => $orderId]);
                            return $commentsObj->listComments();
                        }
                );
$paymentHelper->testLogTest($orderComments);
                $comment = '';
                foreach($orderComments as $data)
                {
                    $comment .= (string)$data->text;
                    $comment .= '</br>';
                }
                    $comment = 'testcoMMMMM';
                return $twig->render('Novalnet::NovalnetOrderHistory', ['comments' => html_entity_decode($comment)]);

            }
          //  } else {
          //   return $twig->render('Novalnet::NovalnetOrderHistory', ['comments' => 'test72']);   
         //   }
        }
    return $twig->render('Novalnet::NovalnetOrderHistory', ['comments' => 'testtest80']);
                } else {
                return $twig->render('Novalnet::NovalnetOrderHistory', ['comments' => 'test76']);      
}
    }
}
