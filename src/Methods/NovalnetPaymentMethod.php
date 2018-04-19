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
 * Script : NovalnetPaymentMethod.php
 *
 */

namespace Novalnet\Methods;

use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodService;
use Plenty\Plugin\Application;
use Novalnet\Helper\PaymentHelper;

/**
 * Class NovalnetPaymentMethod
 *
 * @package Novalnet\Methods
 */
class NovalnetPaymentMethod extends PaymentMethodService
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * NovalnetPaymentMethod constructor.
     *
     * @param ConfigRepository $configRepository
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(ConfigRepository $configRepository,
                                PaymentHelper $paymentHelper)
    {
        $this->configRepository = $configRepository;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Check the configuration if the payment method is active
     * Return true only if the payment method is active
     *
     * @return bool
     */
    public function isActive():bool
    {
        return (bool)(($this->configRepository->get('Novalnet.payment_active') == 'true') && is_numeric($this->paymentHelper->getNovalnetConfig('vendor_id')) && !empty($this->paymentHelper->getNovalnetConfig('auth_code')) && is_numeric($this->paymentHelper->getNovalnetConfig('product_id')) && is_numeric($this->paymentHelper->getNovalnetConfig('tariff_id')) && !empty($this->paymentHelper->getNovalnetConfig('access_key')));
    }

    /**
     * Get the name of the payment method. The name can be entered in the config.json.
     *
     * @param ConfigRepository $configRepository
     * @return string
     */
    public function getName():string
    {        
        $name = trim($this->configRepository->get('Novalnet.payment_name'));
        if(empty($name))
        {
            $name = $this->paymentHelper->getTranslatedText('novalnet_frontend_name');
        }
        return $name;
    }

    /**
     * Retrieves the icon of the Novalnet payments. The URL can be entered in the config.json.
     *
     * @return string
     */
    public function getIcon():string
    {
        /** @var Application $app */
        $app = pluginApp(Application::class);
        return $app->getUrlPath('novalnet') .'/images/icon.png';
    }

    /**
     * Retrieves the description of the Novalnet payments. The description can be entered in the config.json.
     *
     * @return string
     */
    public function getDescription():string
    {
        $description = trim($this->configRepository->get('Novalnet.description'));
        if(empty($description))
        {
            $description = $this->paymentHelper->getTranslatedText('payment_description');
        }
        return $description;
    }

    /**
     * Check if it is allowed to switch to this payment method
     *
     * @return bool
     */
    public function isSwitchableTo(): bool
    {
        return false;
    }

    /**
     * Check if it is allowed to switch from this payment method
     *
     * @return bool
     */
    public function isSwitchableFrom(): bool
    {
        return false;
    }
}
