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
 * Script : PaymentService.php
 *
 */

namespace Novalnet\Services;

use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Frontend\Services\AccountService;
use Novalnet\Constants\NovalnetConstants;

/**
 * Class PaymentService
 *
 * @package Novalnet\Services
 */
class PaymentService
{

    use Loggable;

    /**
     * @var ConfigRepository
     */
    private $config;

    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $session;

    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;

    /**
     * @var CountryRepositoryContract
     */
    private $countryRepository;

    /**
     * @var WebstoreHelper
     */
    private $webstoreHelper;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param FrontendSessionStorageFactoryContract $session
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(ConfigRepository $config,
                                FrontendSessionStorageFactoryContract $session,
                                AddressRepositoryContract $addressRepository,
                                CountryRepositoryContract $countryRepository,
                                WebstoreHelper $webstoreHelper,
                                PaymentHelper $paymentHelper)
    {
        $this->config            = $config;
        $this->session           = $session;
        $this->addressRepository = $addressRepository;
        $this->countryRepository = $countryRepository;
        $this->webstoreHelper    = $webstoreHelper;
        $this->paymentHelper     = $paymentHelper;
    }

    /**
     * Creates the payment for the order generated in plentymarkets.
     *
     * @param array $requestData
     *
     * @return array
     */
    public function executePayment($requestData)
    {
        try {
            $requestData['amount'] = (float) $requestData['amount'];

            if((in_array($requestData['payment_id'], ['34','78']) && in_array($requestData['tid_status'], ['86','90','85'])))
            {
                if($requestData['payment_id'] == '78')
                {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.przelewy_payment_pending_status'));
                }
                else
                {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.paypal_payment_pending_status'));
                }
                $requestData['paid_amount'] = 0;
            }
            elseif($requestData['payment_id'] == '41')
            {
                $requestData['order_status'] = trim($this->config->get('Novalnet.invoice_callback_order_status'));
                $requestData['paid_amount'] = $requestData['amount'];
            }
            elseif(in_array($requestData['payment_id'], ['27','59']))
            {
                $requestData['order_status'] = trim($this->config->get('Novalnet.order_completion_status'));
                $requestData['paid_amount'] = 0;
            }
            else
            {
                $requestData['order_status'] = trim($this->config->get('Novalnet.order_completion_status'));
                $requestData['paid_amount'] = $requestData['amount'];
            }

            $transactionComments = $this->getTransactionComments($requestData);
            $this->paymentHelper->createPlentyPayment($requestData);
            $this->paymentHelper->updateOrderStatus((int)$requestData['order_no'], $requestData['order_status']);
            $this->paymentHelper->createOrderComments((int)$requestData['order_no'], $transactionComments);
            return [
                'type' => 'success',
                'value' => $this->paymentHelper->getNovalnetStatusText($requestData)
            ];
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('ExecutePayment failed.', $e);
            return [
                'type'  => 'error',
                'value' => $e->getMessage()
            ];
        }
    }

    /**
     * Build transaction comments for the order
     *
     * @param array $requestData
     * @return string
     */
    public function getTransactionComments($requestData)
    {
        $comments  = '</br>' . $this->paymentHelper->getDisplayPaymentMethodName($requestData);
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('nn_tid') . $requestData['tid'];

        if(!empty($requestData['test_mode']) || ($this->config->get('Novalnet.test_mode') == 'true'))
            $comments .= '</br>' . $this->paymentHelper->getTranslatedText('test_order');

        if(in_array($requestData['payment_id'], ['40','41']))
            $comments .= '</br>' . $this->paymentHelper->getTranslatedText('guarantee_text');

        if(in_array($requestData['payment_id'], ['27','41']))
        {
            $comments .= '</br>' . $this->getInvoicePrepaymentComments($requestData);
        }
        else if($requestData['payment_id'] == '59')
        {
            $comments .= '</br>' . $this->getCashPaymentComments($requestData);
        }

        return $comments;
    }

    /**
     * Build Invoice and Prepayment transaction comments
     *
     * @param array $requestData
     * @return string
     */
    public function getInvoicePrepaymentComments($requestData)
    {
        $comments = $this->paymentHelper->getTranslatedText('transfer_amount_text');
        if(!empty($requestData['due_date']))
        {
            $comments .= '</br>' . $this->paymentHelper->getTranslatedText('due_date') . date('Y/m/d', (int)strtotime($requestData['due_date']));
        }

        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('account_holder_novalnet') . $requestData['invoice_account_holder'];
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('iban') . $requestData['invoice_iban'];
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('bic') . $requestData['invoice_bic'];
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('bank') . $this->paymentHelper->checkUtf8Character($requestData['invoice_bankname']) . ' ' . $this->paymentHelper->checkUtf8Character($requestData['invoice_bankplace']);
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('amount') . $requestData['amount'] . ' ' . $requestData['currency'];

        if ($requestData['payment_id'] == 41 || $requestData['invoice_type'] == 'INVOICE')
        {
            $paymentType = 'invoice';
        }
        else
        {
            $paymentType = 'prepayment';
        }
        $paymentReferenceType = 'Novalnet.' . $paymentType . '_payment_reference';
        $paymentReference = $this->config->get($paymentReferenceType);

        $productId = $requestData['product'];
        if(!preg_match('/^[0-9]/', (string) $requestData['product']))
        {
            $productId = $this->paymentHelper->decodeData($requestData['product'], $requestData['uniqid']);
        }

        if(isset($paymentReference))
        {
            $invoiceComments = '';
            $references[1] = (int) preg_match('/ref/', $paymentReference);
            $references[2] = (int) preg_match('/tid/', $paymentReference);
            $references[3] = (int) preg_match('/order_no/', $paymentReference);
            $i = 1;
            $countReference  = $references[1] + $references[1] + $references[3];
            $invoiceComments .= '</br></br>'.(($countReference > 1) ? $this->paymentHelper->getTranslatedText('any_one_reference_text') : $this->paymentHelper->getTranslatedText('single_ref_text'));
            foreach ($references as $key => $value)
            {
                if ($references[$key] == 1)
                {
                    $invoiceComments .= '</br>'.(($countReference == 1) ? $this->paymentHelper->getTranslatedText('single_ref') : sprintf($this->paymentHelper->getTranslatedText('multi_ref'), $i++));
                    $invoiceComments .= ($key == 1) ? ('BNR-' . $productId . '-' . $requestData['order_no']) : ($key == 2 ? 'TID '. $requestData['tid'] : $this->paymentHelper->getTranslatedText('order_no') . $requestData['order_no']);
                }
            }
            $comments .= $invoiceComments;
        }
        $comments .= '</br>';

        return $comments;
    }

    /**
      * Build cash payment transaction comments
      *
      * @param array $requestData
      * @return string
      */
    public function getCashPaymentComments($requestData)
    {
        $comments = $this->paymentHelper->getTranslatedText('cashpayment_expire_date') . $requestData['cashpayment_due_date'] . '</br>';
        $comments .= '</br><b>' . $this->paymentHelper->getTranslatedText('cashpayment_near_you') . '</b></br></br>';

        $strNos = 0;
        foreach($requestData as $key => $val)
        {
            if(strpos($key, 'nearest_store_title') !== false)
            {
                $strnos++;
            }
        }

        for($i = 1; $i <= $strnos; $i++)
        {
            $countryName = !empty($requestData['nearest_store_country_' . $i]) ? $requestData['nearest_store_country_' . $i] : '';

            $comments .= $requestData['nearest_store_title_' . $i] . '</br>';
            $comments .= $countryName . '</br>';
            $comments .= $this->paymentHelper->checkUtf8Character($requestData['nearest_store_street_' . $i]) . '</br>';
            $comments .= $requestData['nearest_store_city_' . $i] . '</br>';
            $comments .= $requestData['nearest_store_zipcode_' . $i] . '</br></br>';
        }

        return $comments;
    }

    /**
     * Build Novalnet server request parameters
     *
     * @param Basket $basket
     *
     * @return array
     */
    public function getRequestParameters(Basket $basket)
    {
        $billingAddressId = $basket->customerInvoiceAddressId;
        $address = $this->addressRepository->findAddressById($billingAddressId);
        $account = pluginApp(AccountService::class);
        $customerId = $account->getAccountContactId();

        $paymentRequestData = [
                'vendor'             => $this->paymentHelper->getNovalnetConfig('vendor_id'),
                'auth_code'          => $this->paymentHelper->getNovalnetConfig('auth_code'),
                'product'            => $this->paymentHelper->getNovalnetConfig('product_id'),
                'tariff'             => $this->paymentHelper->getNovalnetConfig('tariff_id'),
                'test_mode'          => (int)($this->config->get('Novalnet.test_mode') == 'true'),
                'first_name'         => $address->firstName,
                'last_name'          => $address->lastName,
                'email'              => $address->email,
                'gender'             => 'u',
                'city'               => $address->town,
                'street'             => $address->street,
                'country_code'       => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
                'zip'                => $address->postalCode,
                'customer_no'        => ($customerId) ? $customerId : 'guest',
                'lang'               => strtoupper($this->session->getLocaleSettings()->language),
                'amount'             => (sprintf('%0.2f', $basket->basketAmount) * 100),
                'currency'           => $basket->currency,
                'remote_ip'          => $this->paymentHelper->getRemoteAddress(),
                'return_url'         => $this->getReturnPageUrl(),
                'return_method'      => 'POST',
                'error_return_url'   => $this->getReturnPageUrl(),
                'error_return_method'=> 'POST',
                'implementation'     => 'ENC',
                'uniqid'             => $this->paymentHelper->getUniqueId(),
                'system_ip'          => (filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '127.0.0.1' : $_SERVER['SERVER_ADDR']),
                'system_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl,
                'system_name'        => 'PlentyMarket',
                'system_version'     => NovalnetConstants::PLUGIN_VERSION,
                'notify_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/callback'
        ];

        if(!empty($address->houseNumber))
        {
            $paymentRequestData['house_no'] = $address->houseNumber;
        }
        else
        {
            $paymentRequestData['search_in_street'] = '1';
        }

        if(!empty($address->companyName))
            $paymentRequestData['company'] = $address->companyName;

        if(!empty($address->phone))
            $paymentRequestData['mobile'] = $address->phone;

        if($this->config->get('Novalnet.cc_3d') == 'true')
            $paymentRequestData['cc_3d'] = '1';

        $paymentRequestData['sepa_due_date'] = $this->getSepaDueDate();

        $invoiceDueDate = $this->paymentHelper->getNovalnetConfig('invoice_due_date');
        if(is_numeric($invoiceDueDate))
            $paymentRequestData['invoice_due_date'] = date( 'Y-m-d', strtotime( date( 'y-m-d' ) . '+ ' . $invoiceDueDate . ' days' ) );

        $cashpaymentDueDate = $this->paymentHelper->getNovalnetConfig('cashpayment_due_date');
        if(is_numeric($cashpaymentDueDate))
            $paymentRequestData['cashpayment_due_date'] = date( 'Y-m-d', strtotime( date( 'y-m-d' ) . '+ ' . $cashpaymentDueDate . ' days' ) );

        $onHoldLimit = $this->paymentHelper->getNovalnetConfig('on_hold');
        if(is_numeric($onHoldLimit) && $onHoldLimit <= $paymentRequestData['amount'])
            $paymentRequestData['on_hold'] = '1';

        $referrerId = $this->paymentHelper->getNovalnetConfig('referrer_id');
        if(is_numeric($referrerId))
            $paymentRequestData['referrer_id'] = $referrerId;

        $txnReference1 = strip_tags($this->paymentHelper->getNovalnetConfig('reference1'));
        if(!empty($txnReference1))
        {
            $paymentRequestData['input1'] = 'reference1';
            $paymentRequestData['inputval1'] = $txnReference1;
        }

        $txnReference2 = strip_tags($this->paymentHelper->getNovalnetConfig('reference2'));
        if(!empty($txnReference2))
        {
            $paymentRequestData['input2'] = 'reference2';
            $paymentRequestData['inputval2'] = $txnReference2;
        }

        $this->encodePaymentData($paymentRequestData);

        return [
            'data' => $paymentRequestData,
            'url'  => NovalnetConstants::PAYGATE_URI
        ];
    }

    /**
     * Send postback call to server for updating the order number for the transaction
     *
     * @param array $requestData
     */
    public function sendPostbackCall($requestData)
    {
        $postbackData = [
            'vendor'         => $requestData['vendor'],
            'product'        => $requestData['product'],
            'tariff'         => $requestData['tariff'],
            'auth_code'      => $requestData['auth_code'],
            'key'            => $requestData['payment_id'],
            'status'         => 100,
            'tid'            => $requestData['tid'],
            'order_no'       => $requestData['order_no'],
            'remote_ip'      => $this->paymentHelper->getRemoteAddress(),
            'implementation' => 'ENC',
            'uniqid'         => $requestData['uniqid']
        ];

        if(in_array($requestData['payment_id'], ['27', '41']))
        {
            $productId = $requestData['product'];
            if(!preg_match('/^[0-9]/', (string) $requestData['product']))
            {
                $productId = $this->paymentHelper->decodeData($requestData['product'], $requestData['uniqid']);
            }

            $postbackData['invoice_ref'] = 'BNR-' . $productId . '-' . $requestData['order_no'];
        }
        $response = $this->paymentHelper->executeCurl($postbackData, NovalnetConstants::PAYPORT_URI);
    }

    /**
     * Encode the server request parameters
     *
     * @param array $encodePaymentData
     */
    public function encodePaymentData(&$paymentRequestData)
    {
        foreach (['auth_code', 'product', 'tariff', 'amount', 'test_mode'] as $key) {
            // Encoding payment data
            $paymentRequestData[$key] = $this->paymentHelper->encodeData($paymentRequestData[$key], $paymentRequestData['uniqid']);
         }

         // Generate hash value
         $paymentRequestData['hash'] = $this->paymentHelper->generateHash($paymentRequestData);
    }

    /**
     * Calculate SEPA due date based on the SEPA payment configuration
     *
     * @return string
     */
    public function getSepaDueDate()
    {
        $dueDate = $this->paymentHelper->getNovalnetConfig('sepa_due_date');

        return (preg_match('/^[0-9]/', $dueDate) && $dueDate > 6) ? date('Y-m-d', strtotime('+' . $dueDate . 'days')) : date( 'Y-m-d', strtotime('+7 days'));
    }

    /**
     * Get the payment response controller URL to be handled
     *
     * @return string
     */
    private function getReturnPageUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/paymentResponse';
    }
}
