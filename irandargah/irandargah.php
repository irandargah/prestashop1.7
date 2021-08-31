<?php
/**
 * IranDargah - Payment Gateway Module for Prestashop 1.7
 *
 *
 * @author ایران درگاه <contact@irandargah.com>
 * @version 1.0.0
 *
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Irandargah extends PaymentModule
{

    private $_html = '';
    private $_postErrors = array();

    public $address;

    /**
     * Irandargah constructor.
     *
     * Set the information about this module
     */
    public function __construct()
    {
        $this->name = 'irandargah';
        $this->tab = 'payments_gateways';
        $this->version = '1.0';
        $this->author = 'ایران درگاه';
        $this->controllers = array('payment', 'validation');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->displayName = 'ایران درگاه';
        $this->description = 'پرداخت اینترنتی توسط کلیه کارت‌های عضو شبکه شتاب در ایران درگاه';
        $this->confirmUninstall = 'Are you sure you want to uninstall this module?';
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install()
    {
        return parent::install()
        && $this->registerHook('paymentOptions')
        && $this->registerHook('paymentReturn');
    }

    /**
     * Uninstall this module and remove it from all hooks
     *
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     * Returns a string containing the HTML necessary to
     * generate a configuration screen on the admin
     *
     * @return string
     */
    public function getContent()
    {

        if (Tools::isSubmit('irandargah_submit')) {
            Configuration::updateValue('irandargah_merchant_id', $_POST['irandargah_merchant_id']);
            Configuration::updateValue('irandargah_connection_method', $_POST['irandargah_connection_method']);
            Configuration::updateValue('irandargah_currency', $_POST['irandargah_currency']);
            Configuration::updateValue('irandargah_success_massage', $_POST['irandargah_success_massage']);
            Configuration::updateValue('irandargah_failed_massage', $_POST['irandargah_failed_massage']);
            $this->_html .= '<div class="conf confirm">' . $this->l('Settings updated') . '</div>';
        }

        $this->_generateForm();
        return $this->_html;

    }

    /**
     * generate setting form for admin
     */
    private function _generateForm()
    {
        $this->_html .= '<div><form action="' . $_SERVER['REQUEST_URI'] . '" method="post">';
        $this->_html .= $this->l('مرجنت کد :') . '<br><br>';
        $this->_html .= '<input style="max-width: 350px;" type="text" name="irandargah_merchant_id" value="' . Configuration::get('irandargah_merchant_id') . '" ><br><br>';
        $this->_html .= '<div style="text-align: justify;">جهت استفاده از محیط سندباکس مقدار TEST را وارد کنید.</div><br><br>';
        $this->_html .= $this->l('روش اتصال :') . '<br><br>';
        $this->_html .= '<select style="max-width: 350px;" name="irandargah_connection_method">';
        $this->_html .= '<option value=""' . (Configuration::get('irandargah_connection_method') == "" ? 'selected="selected"' : "") . '>' . $this->l('انتخاب کنید') . '</option>';
        $this->_html .= '<option value="REST-POST"' . (Configuration::get('irandargah_connection_method') == "REST-POST" ? 'selected="selected"' : "") . '>' . $this->l('REST (POST)') . '</option>';
        $this->_html .= '<option value="REST-GET"' . (Configuration::get('irandargah_connection_method') == "REST-GET" ? 'selected="selected"' : "") . '>' . $this->l('REST (GET)') . '</option>';
        $this->_html .= '<option value="SOAP"' . (Configuration::get('irandargah_connection_method') == "SOAP" ? 'selected="selected"' : "") . '>' . $this->l('SOAP') . '</option>';
        $this->_html .= '<option value="SANDBOX"' . (Configuration::get('irandargah_connection_method') == "SANDBOX" ? 'selected="selected"' : "") . '>' . $this->l('SANDBOX') . '</option>';
        $this->_html .= '</select><br><br>';
        $this->_html .= $this->l('ارز :') . '<br><br>';
        $this->_html .= '<select style="max-width: 350px;" name="irandargah_currency"><option value="rial"' . (Configuration::get('irandargah_currency') == "rial" ? 'selected="selected"' : "") . '>' . $this->l('ریال') . '</option><option value="toman"' . (Configuration::get('irandargah_currency') == "toman" ? 'selected="selected"' : "") . '>' . $this->l('تومان') . '</option></select><br><br>';
        $this->_html .= $this->l('پیام موفقیت آمیز :') . '<br><br>';
        $this->_html .= '<textarea style="max-width: 350px;" dir="auto" name="irandargah_success_massage" style="margin: 0px; width: 351px; height: 57px;">' . (!empty(Configuration::get('irandargah_success_massage')) ? Configuration::get('irandargah_success_massage') : "پرداخت شما با موفقیت انجام شد. کد رهگیری: {ref_id}") . '</textarea><br><br>';
        $this->_html .= '<div style="text-align: justify;">متن پیامی که می خواهید بعد از پرداخت به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت کدهای {order_id} برای نمایش شماره سفارش و {ref_id} برای نمایش کد رهگیری ایران درگاه استفاده نمایید.</div><br><br>';
        $this->_html .= $this->l('پیام خطا :') . '<br><br>';
        $this->_html .= '<textarea style="max-width: 350px;" dir="auto" name="irandargah_failed_massage" style="margin: 0px; width: 351px; height: 57px;">' . (!empty(Configuration::get('irandargah_failed_massage')) ? Configuration::get('irandargah_failed_massage') : "پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.") . '</textarea><br><br>';
        $this->_html .= '<div style="text-align: justify;">متن پیامی که می خواهید بعد از پرداخت به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت کدهای {order_id} برای نمایش شماره سفارش ایران درگاه استفاده نمایید.</div><br><br>';
        $this->_html .= '<input type="submit" name="irandargah_submit" value="' . $this->l('Save it!') . '" class="button">';
        $this->_html .= '</form><br></div>';
    }

    /**
     * Display this module as a payment option during the checkout
     *
     * @param array $params
     * @return array|void
     */
    public function hookPaymentOptions($params)
    {
        /*
         * Verify if this module is active
         */
        if (!$this->active) {
            return;
        }

        /**
         * Form action URL. The form data will be sent to the
         * validation controller when the user finishes
         * the order process.
         */
        $formAction = $this->context->link->getModuleLink($this->name, 'validation', array(), true);

        /**
         * Assign the url form action to the template var $action
         */
        $this->smarty->assign(['action' => $formAction]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        $paymentForm = $this->fetch('module:irandargah/views/templates/hook/payment_options.tpl');

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */
        $displayName = ' پرداخت با ایران درگاه';
        $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
        $newOption->setModuleName($this->displayName)
            ->setCallToActionText($displayName)
            ->setAction($formAction)
            ->setForm($paymentForm);

        $payment_options = array(
            $newOption,
        );

        return $payment_options;
    }

    /**
     * Display a message in the paymentReturn hook
     *
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params)
    {
        /**
         * Verify if this module is enabled
         */
        if (!$this->active) {
            return;
        }

        return $this->fetch('module:irandargah/views/templates/hook/payment_return.tpl');
    }

    public function hash_key()
    {
        $en = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z');
        $one = rand(1, 26);
        $two = rand(1, 26);
        $three = rand(1, 26);
        return $hash = $en[$one] . rand(0, 9) . rand(0, 9) . $en[$two] . $en[$three] . rand(0, 9) . rand(10, 99);
    }

}
