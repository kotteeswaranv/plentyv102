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
 * Script : CallbackController.php
 *
 */

namespace Novalnet\Controllers;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;

use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Templates\Twig;
use Novalnet\Services\TransactionService;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use \stdClass;

/**
 * Class CallbackController
 *
 * @package Novalnet\Controllers
 */
class CallbackController extends Controller
{
    use Loggable;

    /**
     * @var config
     */
    private $config;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var twig
     */
    private $twig;

    /**
     * @var transaction
     */
    private $transaction;

    /*
     * @var aryPayments
     * @Array Type of payment available - Level : 0
     */
    protected $aryPayments = ['CREDITCARD', 'INVOICE_START', 'DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'PAYPAL', 'ONLINE_TRANSFER', 'IDEAL', 'GIROPAY', 'PRZELEWY24', 'EPS', 'CASHPAYMENT'];

    /**
     * @var aryChargebacks
     * @Array Type of Chargebacks available - Level : 1
     */
    protected $aryChargebacks = ['PRZELEWY24_REFUND', 'RETURN_DEBIT_SEPA', 'REVERSAL', 'CREDITCARD_BOOKBACK', 'CREDITCARD_CHARGEBACK', 'PAYPAL_BOOKBACK', 'REFUND_BY_BANK_TRANSFER_EU', 'CASHPAYMENT_REFUND'];

    /**
     * @var aryCollection
     * @Array Type of CreditEntry payment and Collections available - Level : 2
     */
    protected $aryCollection = ['INVOICE_CREDIT', 'CREDIT_ENTRY_CREDITCARD', 'CREDIT_ENTRY_SEPA', 'DEBT_COLLECTION_SEPA', 'DEBT_COLLECTION_CREDITCARD', 'CASHPAYMENT_CREDIT'];

    /**
     * @var arySubscription
     */
    protected $arySubscription = ['SUBSCRIPTION_STOP'];

    /**
     * @var aryPaymentGroups
     */
    protected $aryPaymentGroups = [
            'novalnet_cc'   => [
                            'CREDITCARD',
                            'CREDITCARD_BOOKBACK',
                            'CREDITCARD_CHARGEBACK',
                            'CREDIT_ENTRY_CREDITCARD',
                            'DEBT_COLLECTION_CREDITCARD',
                            'SUBSCRIPTION_STOP',
                        ],
            'novalnet_sepa'  => [
                            'DIRECT_DEBIT_SEPA',
                            'RETURN_DEBIT_SEPA',
                            'CREDIT_ENTRY_SEPA',
                            'DEBT_COLLECTION_SEPA',
                            'GUARANTEED_DIRECT_DEBIT_SEPA',
                            'REFUND_BY_BANK_TRANSFER_EU',
                            'SUBSCRIPTION_STOP',
                        ],
            'novalnet_invoice' => [
                            'INVOICE_START',
                            'GUARANTEED_INVOICE',
                            'INVOICE_CREDIT',
                            'SUBSCRIPTION_STOP'
                        ],
            'novalnet_prepayment'   => [
                            'INVOICE_START',
                            'INVOICE_CREDIT',
                            'SUBSCRIPTION_STOP'
                        ],
            'novalnet_cashpayment'  => [
                            'CASHPAYMENT',
                            'CASHPAYMENT_CREDIT',
                            'CASHPAYMENT_REFUND',
                        ],
            'novalnet_banktransfer' => [
                            'ONLINE_TRANSFER',
                            'REVERSAL',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_paypal'=> [
                            'PAYPAL',
                            'SUBSCRIPTION_STOP',
                            'PAYPAL_BOOKBACK',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_ideal' => [
                            'IDEAL',
                            'REVERSAL',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_eps'   => [
                            'EPS',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_giropay'    => [
                            'GIROPAY',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_przelewy24' => [
                            'PRZELEWY24',
                            'PRZELEWY24_REFUND'
                        ],
            ];

    /**
     * @var aryCaptureParams
     * @Array Callback Capture parameters
     */
    protected $aryCaptureParams = [];

    /**
     * @var paramsRequired
     */
    protected $paramsRequired = [];

    /**
     * @var ipAllowed
     * @IP-ADDRESS Novalnet IP, is a fixed value, DO NOT CHANGE!!!!!
     */
    protected $ipAllowed = ['195.143.189.210', '195.143.189.214'];

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param Twig $twig
     * @param TransactionService $tranactionService
     */
    public function __construct(  Request $request,
                                  ConfigRepository $config,
                                  PaymentHelper $paymentHelper,
                                  Twig $twig,
                                  TransactionService $tranactionService
                                )
    {
        $this->config           = $config;
        $this->paymentHelper    = $paymentHelper;
        $this->twig             = $twig;
        $this->transaction      = $tranactionService;
        $this->aryCaptureParams = $request->all();
        $this->paramsRequired = ['vendor_id', 'tid', 'payment_type', 'status', 'tid_status'];

        if(!empty($this->aryCaptureParams['subs_billing']))
        {
            $this->paramsRequired[] = 'signup_tid';
        }
        elseif(isset($this->aryCaptureParams['payment_type']) && in_array($this->aryCaptureParams['payment_type'], array_merge($this->aryChargebacks, $this->aryCollection)))
        {
            $this->paramsRequired[] = 'tid_payment';
        }
    }

    /**
     * Execute callback process for the payment levels
     *
     */
    public function processCallback()
    {
        $displayTemplate = $this->validateIpAddress();

        if ($displayTemplate)
        {
            return $this->renderTemplate($displayTemplate);
        }

        $displayTemplate = $this->validateCaptureParams($this->aryCaptureParams);

        if ($displayTemplate)
        {
            return $this->renderTemplate($displayTemplate);
        }

        if(!empty($this->aryCaptureParams['signup_tid']))
        {   // Subscription
            $this->aryCaptureParams['shop_tid'] = $this->aryCaptureParams['signup_tid'];
        }
        else if(in_array($this->aryCaptureParams['payment_type'], array_merge($this->aryChargebacks, $this->aryCollection)))
        {
            $this->aryCaptureParams['shop_tid'] = $this->aryCaptureParams['tid_payment'];
        }
        else if(!empty($this->aryCaptureParams['tid']))
        {
            $this->aryCaptureParams['shop_tid'] = $this->aryCaptureParams['tid'];
        }

        if(empty($this->aryCaptureParams['vendor_activation']))
        {
            $nnTransactionHistory = $this->getOrderDetails();

            if(is_string($nnTransactionHistory))
            {
                return $this->renderTemplate($nnTransactionHistory);
            }

            if($this->getPaymentTypeLevel() == 2 && $this->aryCaptureParams['tid_status'] == '100')
            {
                // Credit entry for the payment types Invoice, Prepayment and Cashpayment.
                if(in_array($this->aryCaptureParams['payment_type'], ['INVOICE_CREDIT', 'CASHPAYMENT_CREDIT']) && $this->aryCaptureParams['tid_status'] == 100)
                {
                    if($this->aryCaptureParams['subs_billing'] != 1)
                    {
                        if ($nnTransactionHistory->order_paid_amount < $nnTransactionHistory->order_total_amount)
                        {
                            $callbackComments  = '</br>';
                            $callbackComments .= sprintf('Novalnet Callback Script executed successfully for the TID: %s with amount %s %s on %s. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: %s', $this->aryCaptureParams['shop_tid'], ($this->aryCaptureParams['amount'] / 100), $this->aryCaptureParams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureParams['tid'] ).'</br>';

                            if($nnTransactionHistory->order_total_amount <= ($nnTransactionHistory->order_paid_amount + $this->aryCaptureParams['amount']))
                            {
                                $paymentConfigName = substr($nnTransactionHistory->paymentName, 9);
                                $orderStatus = $this->config->get('Novalnet.' . $paymentConfigName . '_callback_order_status');
                                $this->paymentHelper->updateOrderStatus($nnTransactionHistory->orderNo, (float)$orderStatus);
                            }

                            $this->saveTransactionLog($nnTransactionHistory);

                            $paymentData['currency']    = $this->aryCaptureParams['currency'];
                            $paymentData['paid_amount'] = (float) ($this->aryCaptureParams['amount'] / 100);
                            $paymentData['tid']         = $this->aryCaptureParams['tid'];
                            $paymentData['order_no']    = $nnTransactionHistory->orderNo;

                            $this->paymentHelper->createPlentyPayment($paymentData);
                            $this->paymentHelper->createOrderComments($nnTransactionHistory->orderNo, $callbackComments);
                            $this->sendCallbackMail($callbackComments);
                            return $this->renderTemplate($callbackComments);
                        }
                        else
                        {
                            return $this->renderTemplate('Novalnet callback received. Callback Script executed already. Refer Order :'.$nnTransactionHistory->orderNo);
                        }
                    }
                }
                else
                {
                    return $this->renderTemplate('Novalnet Callbackscript received. Payment type ( '.$this->aryCaptureParams['payment_type'].' ) is not applicable for this process!');
                }
            }
            else if($this->getPaymentTypeLevel() == 1 && $this->aryCaptureParams['tid_status'] == 100)
            {
                $callbackComments = '</br>';
                $callbackComments .= (in_array($this->aryCaptureParams['payment_type'], ['CREDITCARD_BOOKBACK', 'PAYPAL_BOOKBACK', 'REFUND_BY_BANK_TRANSFER_EU', 'PRZELEWY24_REFUND', 'CASHPAYMENT_REFUND'])) ? sprintf(' Novalnet callback received. Refund/Bookback executed successfully for the TID: %s amount: %s %s on %s. The subsequent TID: %s.', $nnTransactionHistory->tid, sprintf('%0.2f', ($this->aryCaptureParams['amount']/100)) , $this->aryCaptureParams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureParams['tid'] ) . '</br>' : sprintf( ' Novalnet callback received. Chargeback executed successfully for the TID: %s amount: %s %s on %s. The subsequent TID: %s.', $nnTransactionHistory->tid, sprintf( '%0.2f',( $this->aryCaptureParams['amount']/100) ), $this->aryCaptureParams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureParams['tid'] ) . '</br>';

                $this->saveTransactionLog($nnTransactionHistory);

                $paymentData['currency']    = $this->aryCaptureParams['currency'];
                $paymentData['paid_amount'] = (float) ($this->aryCaptureParams['amount']/100);
                $paymentData['tid']         = $this->aryCaptureParams['tid'];
                $paymentData['type']        = 'debit';
                $paymentData['order_no']    = $nnTransactionHistory->orderNo;

                $this->paymentHelper->createPlentyPayment($paymentData);
                $this->paymentHelper->createOrderComments($nnTransactionHistory->orderNo, $callbackComments);
                $this->sendCallbackMail($callbackComments);
                return $this->renderTemplate($callbackComments);
            }
            elseif($this->getPaymentTypeLevel() == 0 )
            {
                if(in_array($this->aryCaptureParams['payment_type'], ['PAYPAL','PRZELEWY24']) && $this->aryCaptureParams['status'] == '100' && $this->aryCaptureParams['tid_status'] == '100')
                {
                    if ($nnTransactionHistory->order_paid_amount < $nnTransactionHistory->order_total_amount)
                    {
                        $callbackComments  = '</br>';
                        $callbackComments .= sprintf('Novalnet Callback Script executed successfully for the TID: %s with amount %s %s on %s. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: %s', $this->aryCaptureParams['shop_tid'], ($this->aryCaptureParams['amount']/100), $this->aryCaptureParams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureParams['tid'] ).'</br>';

                        $this->saveTransactionLog($nnTransactionHistory, true);

                        $paymentData['currency']    = $this->aryCaptureParams['currency'];
                        $paymentData['paid_amount'] = (float) ($this->aryCaptureParams['amount']/100);
                        $paymentData['tid']         = $this->aryCaptureParams['tid'];
                        $paymentData['order_no']    = $nnTransactionHistory->orderNo;
                        $orderStatus = (float) $this->config->get('Novalnet.order_completion_status');

                        $this->paymentHelper->createPlentyPayment($paymentData);
                        $this->paymentHelper->updateOrderStatus($nnTransactionHistory->orderNo, $orderStatus);
                        $this->paymentHelper->createOrderComments($nnTransactionHistory->orderNo, $callbackComments);
                        $this->sendCallbackMail($callbackComments);

                        return $this->renderTemplate($callbackComments);
                    }
                    else
                    {
                        return $this->renderTemplate('Novalnet Callbackscript received. Order already Paid');
                    }
                }
                elseif('PRZELEWY24' == $this->aryCaptureParams['payment_type'] && (!in_array($this->aryCaptureParams['tid_status'], ['100','86']) || '100' != $this->aryCaptureParams['status']))
                {
                    // Przelewy24 cancel.
                    $callbackComments = '</br>' . sprintf('The transaction has been canceled due to: %s',$this->paymentHelper->getNovalnetStatusText($this->aryCaptureParams) ) . '</br>';
                    $orderStatus = (float) $this->config->get('Novalnet.order_cancel_status');
                    $this->paymentHelper->updateOrderStatus($nnTransactionHistory->orderNo, $orderStatus);
                    $this->paymentHelper->createOrderComments($nnTransactionHistory->orderNo, $callbackComments);
                    $this->sendCallbackMail($callbackComments);
                    return $this->renderTemplate($callbackComments);
                }
                else
                {
                    $error = 'Novalnet Callbackscript received. Payment type ( '.$this->aryCaptureParams['payment_type'].' ) is not applicable for this process!';
                    return $this->renderTemplate($error);
                }
            }
            else
            {
                return $this->renderTemplate('Novalnet callback received. TID Status ('.$this->aryCaptureParams['tid_status'].') is not valid: Only 100 is allowed');
            }
        }

        return $this->renderTemplate('Novalnet callback received. Callback Script executed already.');
    }

    /**
     * Validate the IP control check
     *
     * @return bool|string
     */
    public function validateIpAddress()
    {
        $client_ip = $this->paymentHelper->getRemoteAddress();
        if(!in_array($client_ip, $this->ipAllowed) && $this->config->get('Novalnet.callback_test_mode') != 'true')
        {
            return 'Novalnet callback received. Unauthorised access from the IP '. $client_ip;
        }
        return '';
    }

    /**
     * Validate request param
     *
     * @param array $data
     * @return array|string
     */
    public function validateCaptureParams($aryCaptureParams)
    {
        if(!isset($aryCaptureParams['vendor_activation']))
        {
            foreach ($this->paramsRequired as $param)
            {
                if (empty($aryCaptureParams[$param]))
                {
                    return 'Required param ( ' . $param . '  ) missing!';
                }

                if (in_array($param, ['tid', 'tid_payment', 'signup_tid']) && !preg_match('/^\d{17}$/', $aryCaptureParams[$param]))
                {
                    return 'Novalnet callback received. Invalid TID ['. $aryCaptureParams[$param] . '] for Order.';
                }
            }
        }

        return '';
    }

    /**
     * Find and retrieves the shop order ID for the Novalnet transaction
     *
     * @return object|string
     */
    public function getOrderDetails()
    {
        $order = $this->transaction->getTransactionData('tid', $this->aryCaptureParams['shop_tid']);

        if(!empty($order))
        {
            $orderDetails = $order[0]; // Setting up the order details fetched
            $orderObj                     = pluginApp(stdClass::class);
            $orderObj->tid                = $this->aryCaptureParams['shop_tid'];
            $orderObj->order_total_amount = $orderDetails->amount;
            // Collect paid amount information from the novalnet_callback_history
            $orderObj->order_paid_amount  = 0;
            $orderObj->orderNo            = $orderDetails->orderNo;
            $orderObj->paymentName        = $orderDetails->paymentName;

            $paymentTypeLevel = $this->getPaymentTypeLevel();

            if ($paymentTypeLevel != 1)
            {
                $orderAmountTotal = $this->transaction->getTransactionData('orderNo', $orderDetails->orderNo);
                if(!empty($orderAmountTotal))
                {
                    $amount = 0;
                    foreach($orderAmountTotal as $data)
                    {
                        $amount += $data->callbackAmount;
                    }
                    $orderObj->order_paid_amount = $amount;
                }
            }

            if (!isset($orderDetails->paymentName) || !in_array($this->aryCaptureParams['payment_type'], $this->aryPaymentGroups[$orderDetails->paymentName]))
            {
                return 'Novalnet callback received. Payment Type [' . $this->aryCaptureParams['payment_type'] . '] is not valid.';
            }

            if (!empty($this->aryCaptureParams['order_no']) && $this->aryCaptureParams['order_no'] != $orderDetails->orderNo)
            {
                return 'Novalnet callback received. Order Number is not valid.';
            }
        }
        else
        {
            return 'Transaction mapping failed';
        }

        return $orderObj;
    }

    /**
     * Get the callback payment level based on the payment type
     *
     * @return int
     */
    public function getPaymentTypeLevel()
    {
        if(in_array($this->aryCaptureParams['payment_type'], $this->aryPayments))
        {
            return 0;
        }
        else if(in_array($this->aryCaptureParams['payment_type'], $this->aryChargebacks))
        {
            return 1;
        }
        else if(in_array($this->aryCaptureParams['payment_type'], $this->aryCollection))
        {
            return 2;
        }
    }

    /**
     * Setup the transction log for the callback executed
     *
     * @param $txnHistory
     * @param $initialLevel
     */
    public function saveTransactionLog($txnHistory, $initialLevel = false)
    {
        $insertTransactionLog['callback_amount'] = ($initialLevel) ? $txnHistory->order_total_amount : $this->aryCaptureParams['amount'];
        $insertTransactionLog['amount']          = $txnHistory->order_total_amount;
        $insertTransactionLog['tid']             = $this->aryCaptureParams['shop_tid'];
        $insertTransactionLog['ref_tid']         = $this->aryCaptureParams['tid'];
        $insertTransactionLog['payment_name']    = $txnHistory->paymentName;
        $insertTransactionLog['order_no']        = $txnHistory->orderNo;

        $this->transaction->saveTransaction($insertTransactionLog);
    }

    /**
     * Send the vendor script email for the execution
     *
     * @param $mailContent
     * @return bool
     */
    public function sendCallbackMail($mailContent)
    {
        try
        {
            $enableTestMail = ($this->config->get('Novalnet.enable_email') == 'true');

            if($enableTestMail)
            {
                $toAddress  = $this->config->get('Novalnet.email_to');
                $bccAddress = $this->config->get('Novalnet.email_bcc');
                $subject    = 'Novalnet Callback Script Access Report';

                if(!empty($bccAddress))
                {
                    $bccMail = explode(',', $bccAddress);
                }
                else
                {
                    $bccMail = [];
                }

                $ccAddress = []; # Setting it empty as we handle only to and bcc addresses.

                $mailer = pluginApp(MailerContract::class);
                $mailer->sendHtml($mailContent, $toAddress, $subject, $ccAddress, $bccMail);
            }
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::CallbackMailNotSend', $e);
            return false;
        }
    }

    /**
     * Render twig template for callback message
     *
     * @param $templateData
     * @return string
     */
    public function renderTemplate($templateData)
    {
        return $this->twig->render('Novalnet::callback.callback', ['comments' => $templateData]);
    }
}
