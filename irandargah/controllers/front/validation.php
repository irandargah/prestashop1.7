<?php
/**
 * IranDargah - Payment Gateway Module for Prestashop 1.7
 *
 * Order Validation Controller
 *
 * @author ایران درگاه <contact@irandargah.com>
 */

class IrandargahValidationModuleFrontController extends ModuleFrontController
{

    /** @var array Controller errors */
    public $errors = [];

    /** @var array Controller warning notifications */
    public $warning = [];

    /** @var array Controller success notifications */
    public $success = [];

    /** @var array Controller info notifications */
    public $info = [];

    /**
     * set notifications on SESSION
     */
    public function notification()
    {

        $notifications = json_encode([
            'error' => $this->errors,
            'warning' => $this->warning,
            'success' => $this->success,
            'info' => $this->info,
        ]);

        if (session_status() == PHP_SESSION_ACTIVE) {
            $_SESSION['notifications'] = $notifications;
        } elseif (session_status() == PHP_SESSION_NONE) {
            session_start();
            $_SESSION['notifications'] = $notifications;
        } else {
            setcookie('notifications', $notifications);
        }

    }

    /**
     * register order and request to api
     */
    public function postProcess()
    {

        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;
        $authorized = false;

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'irandargah') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            $this->errors[] = 'This payment method is not available.';
            $this->notification();
            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int) $cart->id . '&id_module=' . (int) $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
        }

        /**
         * Check if this is a vlaid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        //call callBack function
        if (isset($_GET['do'])) {
            $this->callBack($customer);
        }

        $this->module->validateOrder(
            (int) $this->context->cart->id,
            13,
            (float) $this->context->cart->getOrderTotal(true, Cart::BOTH),
            "Irandargah",
            null,
            null,
            (int) $this->context->currency->id,
            false,
            $customer->secure_key
        );

        //get order id
        $sql = ' SELECT  `id_order`  FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = "' . $cart->id . '"';
        $order_id = Db::getInstance()->executeS($sql);
        $order_id = $order_id[0]['id_order'];

        $merchant_id = Configuration::get('irandargah_merchant_id');
        $connection_method = Configuration::get('irandargah_connection_method');
        $amount = $cart->getOrderTotal();
        if (Configuration::get('irandargah_currency') == "toman") {
            $amount *= 10;
        }

        // Customer information
        $details = $cart->getSummaryDetails();
        $delivery = $details['delivery'];
        $name = $delivery->firstname . ' ' . $delivery->lastname;
        $phone = $delivery->phone_mobile;

        if (empty($phone_mobile)) {
            $phone = $delivery->phone;
        }

        $description = 'پرداخت سفارش شماره: ' . $order_id . ' خریدار: ' . $name;
        $callbackURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? "https://" : "http://") . $_SERVER['SERVER_NAME'] . '/index.php?fc=module&module=irandargah&controller=validation&id_lang=2&do=callback&hash=' . md5($amount . $order_id . Configuration::get('irandargah_HASH_KEY'));

        if (empty($amount)) {
            $this->errors[] = $this->otherStatusMessages(404);
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');
        }

        $data = array(
            'merchantID' => $merchant_id,
            'amount' => $amount,
            'callbackURL' => $callbackURL,
            'orderId' => $order_id,
            'mobile' => $phone,
            'description' => $description,
        );

        $response = $this->send_request_to_irandargah(
            $connection_method,
            'payment',
            $data
        );

        if (is_null($response)) {
            $msg = 'خطا هنگام اتصال به درگاه';
            $this->errors[] = $msg;
            $this->notification();
            $this->saveOrder($msg, 8, $order_id, 'failed');
            Tools::redirect('index.php?controller=order-confirmation');
            exit;
        }

        $msg = [
            'irandargah_authority' => $response->authority,
            'msg' => "در انتظار پرداخت...",
        ];
        $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . 13 . '", `payment` = ' . "'" . $msg . "'" . ' WHERE `id_order` = "' . $order_id . '"';
        Db::getInstance()->Execute($sql);

        if ($response->status != 200) {
            $msg = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - پیام خطا: %s', $response->status, $response->message);
            $this->errors[] = $msg;
            $this->notification();
            $this->saveOrder($msg, 8, $order_id, 'failed');
            Tools::redirect('index.php?controller=order-confirmation');

        } else {
            $redirect_url = $connection_method == 'SANDBOX' ? 'https://dargaah.com/sandbox/ird/startpay/' : 'https://dargaah.com/ird/startpay/';
            Tools::redirect($redirect_url . $response->authority);
            exit;
        }

    }

    /**
     * @param $customer
     */
    public function callBack($customer)
    {
        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            $order_id = $_GET['orderId'];
            $order = new Order((int) $order_id);
            $status = $_GET['code'];
            $response_message = $_GET['message'];
            $authority = $_GET['authority'];
            $amount = (float) $order->total_paid_tax_incl;
        } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $order_id = $_POST['orderId'];
            $order = new Order((int) $order_id);
            $status = $_POST['code'];
            $response_message = $_POST['message'];
            $authority = $_POST['authority'];
            $amount = (float) $order->total_paid_tax_incl;
        }

        if (!empty($status) && !empty($response_message)) {

            if (Configuration::get('irandargah_currency') == "toman") {
                $amount *= 10;
            }

            if (md5($amount . $order->id . Configuration::get('irandargah_HASH_KEY')) == $_GET['hash']) {

                if ($status == 100) {

                    $merchant_id = Configuration::get('irandargah_merchant_id');
                    $data = array(
                        'merchantID' => $merchant_id,
                        'authority' => $authority,
                        'amount' => $amount,
                        'orderId' => $order_id,
                    );
                    $connection_method = Configuration::get('irandargah_connection_method');

                    $response = $this->send_request_to_irandargah(
                        $connection_method,
                        'verification',
                        $data
                    );

                    if ($response->status != 100) {
                        $message = sprintf('خطا هنگام ایجاد تراکنش.کد خطا: %s - پیام خطا: %s', $status, $response->message);
                        $this->errors[] = $message;
                        $this->notification();
                        $this->saveOrder($message, 8, $order_id, 'failed');
                        Tools::redirect('index.php?controller=order-confirmation');

                    } else {
                        $verify_status = $response->status;
                        $verify_message = $response->message;
                        $verify_ref_id = $response->refId;
                        $verify_order_id = $response->orderId;
                        $verify_pan = $response->pan;

                        if (empty($verify_status) || empty($verify_ref_id) || $verify_status != 100 || $verify_order_id !== $order_id) {

                            //generate msg and save to database as order
                            $message = "خطا در وریفای تراکنش";
                            $this->saveOrder($message, 8, $order_id, 'failed');
                            $msg = $this->irandargah_get_failed_message($verify_order_id, $verify_message);
                            $this->errors[] = $msg;
                            $this->notification();
                            Tools::redirect('index.php?controller=order-confirmation');

                        } else {

                            //check double spending
                            $sql = 'SELECT JSON_EXTRACT(payment, "$.irandargah_authority") FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order  = "' . $order_id . '" AND JSON_EXTRACT(payment, "$.irandargah_authority")   = "' . $authority . '"';
                            $exist = Db::getInstance()->executes($sql);
                            if ($verify_order_id !== $order_id or count($exist) == 0) {

                                $message = "تراکنش تکراری می‌باشد.";
                                $this->saveOrder($message, 8, $order_id, 'failed');
                                $msg = $this->irandargah_get_failed_message($verify_order_id, $response_message);
                                $this->errors[] = $msg;
                                $this->notification();
                                Tools::redirect('index.php?controller=order-confirmation');
                            }

                            if (Configuration::get('irandargah_currency') == "toman") {
                                $amount /= 10;
                            }

                            $message = $response_message . " کد پیگیری :  $verify_ref_id " . "شماره کارت :  $verify_pan ";
                            $this->saveOrder($message, Configuration::get('PS_OS_PAYMENT'), $order_id, 'completed');

                            $this->success[] = $this->irandargah_get_success_message($verify_ref_id, $verify_order_id, $verify_message);
                            $this->notification();
                            /**
                             * Redirect the customer to the order confirmation page
                             */
                            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int) $order->id_cart . '&id_module=' . (int) $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);

                        }
                    }
                } else {

                    $message = $response_message . " کد خطا :  $status " . "شماره سفارش :  $order_id  ";
                    $this->saveOrder($message, 8, $order_id, 'failed');

                    $this->errors[] = $this->irandargah_get_failed_message($order_id, $response_message);
                    $this->notification();
                    /**
                     * Redirect the customer to the order confirmation page
                     */
                    Tools::redirect('index.php?controller=order-confirmation');

                }

            } else {

                $this->errors[] = $response_message;
                $this->notification();
                $message = "سفارش یافت نشد";
                $this->saveOrder($message, 8, $order_id, 'failed');
                Tools::redirect('index.php?controller=order-confirmation');
            }

        } else {

            $this->errors[] = "خطای ناشناخته. مجددا تلاش کنید و درصورت بروز خطا با مدیر سایت تماس بگیرید.";
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');

        }

    }

    /**
     * @param $msgForSaveDataTDataBase
     * @param $paymentStatus
     * @param $order_id
     * 13 for waiting ,8 for payment error and Configuration::get('PS_OS_PAYMENT') for payment is OK
     */
    public function saveOrder($msgForSaveDataTDataBase, $paymentStatus, $order_id, $payment_status)
    {

        $sql = 'SELECT payment FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order  = "' . $order_id . '"';
        $payment = Db::getInstance()->executes($sql);

        $payment = json_decode($payment[0]['payment'], true);
        $payment['msg'] = $msgForSaveDataTDataBase;
        $data = $payment_status == 'completed' ? 'ایران درگاه' : json_encode($payment, JSON_UNESCAPED_UNICODE);
        $sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . $paymentStatus .
            '", `payment` = ' . "'" . $data . "'" .
            ' WHERE `id_order` = "' . $order_id . '"';

        Db::getInstance()->Execute($sql);
    }

    /**
     * Send Request to IranDargah Gateway
     *
     * @since 1.0.0
     *
     * @param string $method
     * @param mixed  $data
     * @return mixed
     */
    private function send_request_to_irandargah($option, $method, $data)
    {

        $data['merchantID'] = strpos($option, 'SANDBOX') !== false ? 'TEST' : $data['merchantID'];
        $data = strpos($option, 'GET') !== false ? array_merge(['action' => 'GET'], $data) : $data;

        $base_url = strpos($option, 'SANDBOX') !== false ? 'https://dargaah.com/sandbox' : 'https://dargaah.com';

        $response = null;

        $i = 0;
        do {
            $response = strpos($option, 'REST') !== false || $option == 'SANDBOX' ? $this->send_curl_request(
                $base_url . '/' . $method,
                $data
            ) : $this->send_soap_request(
                $method == 'payment' ? 'IRDPayment' : 'IRDVerification',
                $data
            );
            $i++;
        } while (is_null($response) && $i < $this->max_iteration);

        return $response;

    }

    /**
     * Send curl request
     *
     * @param string $url
     * @param mixed $data
     * @return mixed
     */
    private function send_curl_request($url, $data)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
        ));

        $response = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        if ($error) {
            return null;
        }

        $response = json_decode($response);

        return $response;
    }

    /**
     * Send SOAP request
     *
     * @param string $method
     * @param mixed $data
     * @return mixed
     */
    private function send_soap_request($method, $data)
    {
        $response = null;

        try {
            $client = new SoapClient('https://dargaah.com/wsdl', ['cache_wsdl' => WSDL_CACHE_NONE]);
            $response = $client->__soapCall($method, [$data]);
        } catch (\SoapFault $fault) {
            $response = null;
        }

        return $response;
    }

    /**
     * @param $ref_id
     * @param $order_id
     * @param null $msgNumber
     * @return string
     */
    public function irandargah_get_success_message($ref_id, $order_id, $message)
    {
        return str_replace(["{ref_id}", "{order_id}"], [$ref_id, $order_id], Configuration::get('irandargah_success_massage')) . "<br>" . $message;
    }

    /**
     * @param $order_id
     * @param null $msgNumber
     * @return mixed
     */
    public function irandargah_get_failed_message($order_id, $message)
    {
        return str_replace(["{order_id}"], [$order_id], Configuration::get('irandargah_failed_massage')) . "<br>" . $message;

    }
}
