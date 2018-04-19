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
 * Script : NovalnetServiceProvider.php
 *
 */

namespace Novalnet\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Methods\NovalnetPaymentMethod;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;
use Plenty\Plugin\Templates\Twig;

/**
 * Class NovalnetServiceProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Register the route service provider
     */
    public function register()
    {
        $this->getApplication()->register(NovalnetRouteServiceProvider::class);
    }

    /**
     * Boot additional services for the payment method
     *
     * @param paymentHelper $paymentHelper
     * @param PaymentMethodContainer $payContainer
     * @param Dispatcher $eventDispatcher
     * @param PaymentService $paymentService
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentMethodRepositoryContract $paymentMethodService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param TransactionService $transactionLogData
     */
    public function boot( Dispatcher $eventDispatcher,
                          PaymentHelper $paymentHelper,
                          PaymentService $paymentService,
                          BasketRepositoryContract $basketRepository,
                          PaymentMethodContainer $payContainer,
                          PaymentMethodRepositoryContract $paymentMethodService,
                          FrontendSessionStorageFactoryContract $sessionStorage,
                          TransactionService $transactionLogData,
                          Twig $twig)
    {
        // Register the Novalnet payment method in the payment method container
        $payContainer->register('plenty_novalnet::NOVALNET', NovalnetPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);

        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(GetPaymentMethodContent::class,
                function(GetPaymentMethodContent $event) use($paymentHelper, $paymentService, $basketRepository, $paymentMethodService, $sessionStorage, $twig)
                {
                    if($event->getMop() == $paymentHelper->getPaymentMethod())
                    {
                        $serverRequestData = $paymentService->getRequestParameters($basketRepository->load());

                        $sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
                        $content = $twig->render('Novalnet::NovalnetPaymentRedirectForm', [
                                                                'formData'     => $serverRequestData['data'],
                                                                'nnPaymentUrl' => $serverRequestData['url']
                                   ]);

                        $contentType = 'htmlContent';
                        $event->setValue($content);
                        $event->setType($contentType);
                    }
                });

        // Listen for the event that executes the payment
        $eventDispatcher->listen(ExecutePayment::class,
            function (ExecutePayment $event) use ($paymentHelper, $paymentService, $sessionStorage, $transactionLogData)
            {
                if($event->getMop() == $paymentHelper->getPaymentMethod())
                {
                    $requestData = $sessionStorage->getPlugin()->getValue('nnPaymentData');
                    $sessionStorage->getPlugin()->setValue('nnPaymentData',null);
                    if(isset($requestData['status']) && in_array($requestData['status'], ['90', '100']))
                    {
                        $requestData['order_no'] = $event->getOrderId();
                        $paymentService->sendPostbackCall($requestData);

                        $paymentResult = $paymentService->executePayment($requestData);
                        
                        $isPrepayment = (bool)($requestData['payment_id'] == '27' && $requestData['invoice_type'] == 'PREPAYMENT');

                        $transactionData = [
                            'amount'           => $requestData['amount'] * 100,
                            'callback_amount'  => $requestData['amount'] * 100,
                            'tid'              => $requestData['tid'],
                            'ref_tid'          => $requestData['tid'],
                            'payment_name'     => $paymentHelper->getPaymentNameByResponse($requestData['payment_id'], $isPrepayment),
                            'payment_type'     => $requestData['payment_type'],
                            'order_no'         => $requestData['order_no'],
                        ];

                        if($requestData['payment_id'] == '27' || $requestData['payment_id'] == '59' || (in_array($requestData['tid_status'], ['85','86','90'])))
                            $transactionData['callback_amount'] = 0;

                        $transactionLogData->saveTransaction($transactionData);
                    } else {
                        $paymentResult['type'] = 'error';
                        $paymentResult['value'] = $paymentHelper->getTranslatedText('payment_not_success');
                    }
                    $event->setType($paymentResult['type']);
                    $event->setValue($paymentResult['value']);
                }
            }
        );
    }
}
