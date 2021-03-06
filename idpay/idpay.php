<?php
/**
 * IDPay payment plugin
 *
 * @developer JMDMahdi
 * @publisher IDPay
 * @package VirtueMart
 * @subpackage payment
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
defined('_JEXEC') or die('Restricted access');

class plgHikashoppaymentIdpay extends hikashopPaymentPlugin
{
    public $accepted_currencies = ['IRR'];
    public $multiple = true;
    public $name = 'idpay';
    public $doc_form = 'idpay';

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
    }

    public function onBeforeOrderCreate(&$order, &$do)
    {
        if (parent::onBeforeOrderCreate($order, $do) === true) {
            return true;
        }

        if (empty($this->payment_params->api_key)) {
            $this->app->enqueueMessage('Please check your &quot;idpay&quot; plugin configuration');
            $do = false;
        }
    }

    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        $api_key = $this->payment_params->api_key;
        $sandbox = $this->payment_params->sandbox == 'no' ? 'false' : 'true';

        $amount = round($order->cart->full_total->prices[0]->price_value_with_tax, (int)$this->currency->currency_locale['int_frac_digits']);
        $desc = 'پرداخت سفارش شماره: ' . $order->order_id;
        $callback = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=' . $this->name . '&tmpl=component&lang=' . $this->locale . $this->url_itemid;

        if (empty($amount)) {
            echo "<p align=center>واحد پول انتخاب شده پشتیبانی نمی شود.</p>";
            exit;
        }

        // Customer information
        $billing = $order->cart->billing_address;
        $name = $billing->address_firstname . ' ' . $billing->address_lastname;
        $phone = $billing->address_telephone;

        $mail = $order->customer->user_email;

        $data = array(
            'order_id' => $order->order_id,
            'amount' => $amount,
            'name' => $name,
            'phone' => $phone,
            'mail' => $mail,
            'desc' => $desc,
            'callback' => $callback,
        );

        $ch = curl_init('https://api.idpay.ir/v1.1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {

            $msg = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
	        $app	= JFactory::getApplication();
	        $cancel_url = HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=order&task=cancel_order&order_id='.$order->order_id . $this->url_itemid;
	        $app->redirect($cancel_url, $msg, 'Error');
        }

        $this->payment_params->url = $result->link;
        return $this->showPage('redirect');
    }

    public function onPaymentNotification(&$statuses)
    {
        $filter = JFilterInput::getInstance();
	    $app	= JFactory::getApplication();

        $dbOrder = $this->getOrder($_POST['order_id']);
        $this->loadPaymentParams($dbOrder);
        if (empty($this->payment_params)) {
            return false;
        }
        $this->loadOrderData($dbOrder);
        if (empty($dbOrder)) {
            echo 'Could not load any order for your notification ' . $_POST['order_id'];

            return false;
        }
        $order_id = $dbOrder->order_id;

        $url = HIKASHOP_LIVE . 'administrator/index.php?option=com_hikashop&ctrl=order&task=edit&order_id=' . $order_id;
        $order_text = "\r\n" . JText::sprintf('NOTIFICATION_OF_ORDER_ON_WEBSITE', $dbOrder->order_number, HIKASHOP_LIVE);
        $order_text .= "\r\n" . str_replace('<br/>', "\r\n", JText::sprintf('ACCESS_ORDER_WITH_LINK', $url));

        $pid = $_POST['id'];
        $porder_id = $_POST['order_id'];
        if (!empty($pid) && !empty($porder_id) && $porder_id == $order_id)
        {
            if ($_POST['status'] == 10)
            {
                $api_key = $this->payment_params->api_key;
                $sandbox = $this->payment_params->sandbox == 'no' ? 'false' : 'true';
                $price   = $_POST['amount'];

                $data = array(
                    'id'       => $pid,
                    'order_id' => $order_id,
                );

                $history           = new stdClass();
                $history->notified = 0;
                $history->amount   = round( $dbOrder->order_full_price, (int) $this->currency->currency_locale['int_frac_digits'] );
                $history->data     = ob_get_clean();

                $ch = curl_init();
                curl_setopt( $ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify' );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
                curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'X-API-KEY:' . $api_key,
                    'X-SANDBOX:' . $sandbox,
                ) );

                $result      = curl_exec( $ch );
                $result      = json_decode( $result );
                $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                curl_close( $ch );

                if ( $http_status != 200 )
                {
                    $order_status   = $this->payment_params->invalid_status;
                    $email          = new stdClass();
                    $email->subject = JText::sprintf( 'NOTIFICATION_REFUSED_FOR_THE_ORDER', 'idpay' ) . 'invalid transaction';
                    $email->body    = JText::sprintf( "Hello,\r\n A idpay notification was refused because it could not be verified by the idpay server (or pay cenceled)" ) . "\r\n\r\n" . JText::sprintf( 'CHECK_DOCUMENTATION', HIKASHOP_HELPURL . 'payment-idpay-error#invalidtnx' );

                    $this->modifyOrder( $order_id, $order_status, NULL, $email );

                    $msg = sprintf( 'خطا هنگام بررسی تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message );
                    $app->redirect( HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order', $msg, 'Error' );

                }

                $verify_status   = empty( $result->status ) ? NULL : $result->status;
                $verify_track_id = empty( $result->track_id ) ? NULL : $result->track_id;
                $verify_order_id = empty( $result->order_id ) ? NULL : $result->order_id;
                $verify_amount   = empty( $result->amount ) ? NULL : $result->amount;

                $redirect_message_type = '';
                if ( empty( $verify_status ) || empty( $verify_track_id ) || empty( $verify_amount ) || $verify_amount != $price || $verify_status < 100 )
                {
                    $order_status          = $this->payment_params->pending_status;
                    $order_text            = JText::sprintf( 'CHECK_DOCUMENTATION', HIKASHOP_HELPURL . 'payment-idpay-error#verify' ) . "\r\n\r\n" . $order_text;
                    $msg                   = $this->idpay_get_failed_message( $verify_track_id, $verify_order_id );
                    $redirect_message_type = 'Error';
                }
                else
                {
                    $order_status = $this->payment_params->verified_status;
                    $msg          = $this->idpay_get_success_message( $verify_track_id, $verify_order_id );
                }

                $config = &hikashop_config();
                if ( $config->get( 'order_confirmed_status', 'confirmed' ) == $order_status )
                {
                    $history->notified = 1;
                }

                $email          = new stdClass();
                $email->subject = JText::sprintf( 'PAYMENT_NOTIFICATION_FOR_ORDER', 'idpay', $order_status, $dbOrder->order_number );
                $email->body    = str_replace( '<br/>', "\r\n", JText::sprintf( 'PAYMENT_NOTIFICATION_STATUS', 'idpay', $order_status ) ) . ' ' . JText::sprintf( 'ORDER_STATUS_CHANGED', $order_status ) . "\r\n\r\n" . $order_text;
                $this->modifyOrder( $order_id, $order_status, $history, $email );
                $app->redirect( HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order', $msg, $redirect_message_type );
            }
            else
            {
                $msg = $this->idpay_get_failed_message( $_POST['track_id'], $_POST['order_id'] );
                $order_status = $this->payment_params->invalid_status;
                $email = new stdClass();
                $email->subject = JText::sprintf('NOTIFICATION_REFUSED_FOR_THE_ORDER', 'idpay') . 'invalid transaction';
                $email->body = JText::sprintf("Hello,\r\n A Idpay notification was refused because it could not be verified by the idpay server (or pay cenceled)") . "\r\n\r\n" . JText::sprintf('CHECK_DOCUMENTATION', HIKASHOP_HELPURL . 'payment-idpay-error#invalidtnx');
                $action = false;
                $this->modifyOrder($order_id, $order_status, null, $email);
                $app->redirect(HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=order', $msg, 'Error');
            }
        } else {
            $msg = 'کاربر از انجام تراکنش منصرف شده است';
            $order_status = $this->payment_params->invalid_status;
            $email = new stdClass();
            $email->subject = JText::sprintf('NOTIFICATION_REFUSED_FOR_THE_ORDER', 'idpay') . 'invalid transaction';
            $email->body = JText::sprintf("Hello,\r\n A Idpay notification was refused because it could not be verified by the idpay server (or pay cenceled)") . "\r\n\r\n" . JText::sprintf('CHECK_DOCUMENTATION', HIKASHOP_HELPURL . 'payment-idpay-error#invalidtnx');
            $action = false;
            $this->modifyOrder($order_id, $order_status, null, $email);
	        $app->redirect(HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=order', $msg, 'Error');
        }

    }

    public function idpay_get_failed_message($track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->payment_params->failed_message);
    }

    public function idpay_get_success_message($track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->payment_params->success_message);
    }

    public function onPaymentConfiguration(&$element)
    {
        $subtask = JRequest::getCmd('subtask', '');
        parent::onPaymentConfiguration($element);
    }

    public function onPaymentConfigurationSave(&$element)
    {
        return true;
    }

    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name = 'درگاه پرداخت idpay';
        $element->payment_description = '';
        $element->payment_images = '';
        $element->payment_params->invalid_status = 'cancelled';
        $element->payment_params->pending_status = 'created';
        $element->payment_params->verified_status = 'confirmed';
    }
}
