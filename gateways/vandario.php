<?php

/**
 * Vandar.io Gateway for Easy Digital Downloads
 *
 * @author 				Vandar.io
 * @package 			EVI
 * @subpackage 			Gateways
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('EDD_Vandario_Gateway')) :

	/**
	 * Payline Gateway for Easy Digital Downloads
	 *
	 * @author 				Vandar.io
	 * @package 			EVI
	 * @subpackage 			Gateways
	 */
	class EDD_Vandario_Gateway
	{
		/**
		 * Gateway keyname
		 *
		 * @var 				string
		 */
		public $keyname;

		/**
		 * Initialize gateway and hook
		 *
		 * @return 				void
		 */
		public function __construct()
    {
        $this->keyname = 'vandario';

        add_filter('edd_payment_gateways', array($this, 'edd_add'));
        add_action($this->edd_format('edd_{key}_cc_form'), array($this, 'edd_cc_form'));
        add_action($this->edd_format('edd_gateway_{key}'), array($this, 'edd_process'));
        add_action($this->edd_format('edd_verify_{key}'), array($this, 'edd_verify'));
        add_filter('edd_settings_gateways', array($this, 'edd_settings'));

        add_action('edd_payment_receipt_after', array($this, 'edd_receipt'));

        add_action('init', array($this, 'edd_listen'));
    }

		/**
		 * Add gateway to list
		 *
		 * @param 				array $gateways Gateways array
		 * @return 				array
		 */
		public function edd_add($gateways)
		{
			global $edd_options;

			$gateways[$this->keyname] = array(
				'checkout_label' 		=>	isset($edd_options['vandario_label']) ? $edd_options['vandario_label'] : 'پرداخت آنلاین وندار',
				'admin_label' 			=>	'وندار'
			);

			return $gateways;
		}

		/**
		 * CC Form
		 * We don't need it anyway.
		 *
		 * @return 				bool
		 */
		public function edd_cc_form()
		{
			return;
		}

		/**
		 * Process the payment
		 *
		 * @param 				array $purchase_data
		 * @return 				void
		 */

		public function edd_curl_post($action, $params)
		{
			$result = wp_remote_post($action, array(
				'method' => 'POST',
				'headers'  => array(
					'Content-type: application/x-www-form-urlencoded',
					'Accept: application/json'
				),
				'timeout' => 30,
				'body' => $params
			));

			if (is_wp_error($result)) {
				echo $result->get_error_message();
			} else {
				return json_decode(wp_remote_retrieve_body($result), true);
			}
		}


		public function edd_send($api, $amount, $redirect, $mobile = null, $factorNumber = null, $description = null)
		{

			return $this->edd_curl_post('https://ipg.vandar.io/api/v3/send', [
				'api_key'          => $api,
				'amount'       => $amount * 10,
				'callback_url'     => $redirect,
				'mobile_number'       => $mobile,
				'factorNumber' => $factorNumber,
				'description'  => $description,
			]);
		}


		public function edd_process($purchase_data)
		{

			global $edd_options;
			@session_start();
			$payment = $this->edd_insert_payment($purchase_data);

			if ($payment) {

				$redirectt = 'https://ipg.vandar.io/v3/%s';
				$merchant = (isset($edd_options[$this->keyname . '_merchant']) ? $edd_options[$this->keyname . '_merchant'] : '');
				$desc = 'پرداخت شماره #' . $payment . ' | ' . $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'];
				$callback = add_query_arg('verify_' . $this->keyname, '1', get_permalink($edd_options['success_page']));

				$amount = intval($purchase_data['price']) / 10;
				if (edd_get_currency() == 'IRT')
					$amount = $amount * 10; // Return back to original one.


				$result = $this->edd_send($merchant, $amount, $callback, $mobile = null, $factorNumber = null, $desc);

				if ($result['status'] == 1) {
					edd_insert_payment_note($payment, 'کد تراکنش وندار: ' . $result['token']);
					edd_update_payment_meta($payment, 'vandario_authority', $result['token']);
					$_SESSION['vandar_payment'] = $payment;

					wp_redirect(sprintf($redirectt, $result['token']));
				} else {
					edd_insert_payment_note($payment, 'کدخطا: ' . $result['Status']);
					edd_insert_payment_note($payment, 'علت خطا: ' . $result['errorMessage']);
					edd_update_payment_status($payment, 'failed');

					edd_set_error('vandario_connect_error', 'در اتصال به درگاه مشکلی پیش آمد. علت: ' . $result['errorMessage']);
					edd_send_back_to_checkout();
				}
			} else {
				edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
			}
		}

		/**
		 * Verify the payment
		 *
		 * @return 				void
		 */

		public function edd_verifyvandar($api, $token)
		{
			return $this->edd_curl_post('https://ipg.vandar.io/api/v3/verify', [
				'api_key' 	=> $api,
				'token' => $token,
			]);
		}



		public function edd_verify()
		{
		    global $edd_options;

            if (isset($_GET['token'])) {
                $authority = sanitize_text_field($_GET['token']);
                @session_start();
                $payment = edd_get_payment($_SESSION['vandar_payment']);
                unset($_SESSION['vandar_payment']);
                if (!$payment) {
                    wp_die('رکورد پرداخت موردنظر وجود ندارد!');
                }
                if ($payment->status == 'complete') return false;

                $amount = intval(edd_get_payment_amount($payment->ID)) / 10;
                if (edd_get_currency() == 'IRT')
                    $amount = $amount * 10; // Return back to original one.

                $merchant = (isset($edd_options[$this->keyname . '_merchant']) ? $edd_options[$this->keyname . '_merchant'] : '');

                $result = $this->edd_verifyvandar($merchant, $authority);

                if (version_compare(EDD_VERSION, '2.1', '>='))
                    edd_set_payment_transaction_id($payment->ID, $authority);

                if ($result['status'] == 1) {
                    edd_empty_cart();
                    edd_insert_payment_note($payment->ID, 'شماره تراکنش بانکی: ' . $result['transId']);
                    edd_update_payment_meta($payment->ID, 'vandario_refid', $result['transId']);
                    edd_update_payment_status($payment->ID, 'publish');
                    edd_send_to_success_page();
                } else {
                    edd_update_payment_status($payment->ID, 'failed');
                    wp_redirect(get_permalink($edd_options['failure_page']));
                }

			}
		}

		/**
		 * Receipt field for payment
		 *
		 * @param 				object $payment
		 * @return 				void
		 */
		public function edd_receipt($payment)
		{
			$refid = edd_get_payment_meta($payment->ID, 'vandario_refid');
			if ($refid) {
				echo '<tr class="vandario-ref-id-row evi-field "><td><strong>شماره تراکنش بانکی:</strong></td><td>' . $refid . '</td></tr>';
			}
		}

		/**
		 * Gateway settings
		 *
		 * @param 				array $settings
		 * @return 				array
		 */
		public function edd_settings($settings)
		{
			return array_merge($settings, array(
				$this->keyname . '_header' 		=>	array(
					'id' 			=>	$this->keyname . '_header',
					'type' 			=>	'header',
					'name' 			=>	'<strong>درگاه وندار</strong> توسط <a href="https://vandar.io">vandar.io</a>'
				),
				$this->keyname . '_merchant' 		=>	array(
					'id' 			=>	$this->keyname . '_merchant',
					'name' 			=>	'کلید API وندار',
					'type' 			=>	'text',
					'size' 			=>	'regular'
				),
			));
		}

		/**
		 * Format a string, replaces {key} with $keyname
		 *
		 * @param 			string $string To format
		 * @return 			string Formatted
		 */
		private function edd_format($string)
		{
			return str_replace('{key}', $this->keyname, $string);
		}

		/**
		 * Inserts a payment into database
		 *
		 * @param 			array $purchase_data
		 * @return 			int $payment_id
		 */
		private function edd_insert_payment($purchase_data)
		{
			global $edd_options;

			$payment_data = array(
				'price' => $purchase_data['price'],
				'date' => $purchase_data['date'],
				'user_email' => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency' => $edd_options['currency'],
				'downloads' => $purchase_data['downloads'],
				'user_info' => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'status' => 'pending'
			);

			// record the pending payment
			$payment = edd_insert_payment($payment_data);

			return $payment;
		}

		/**
		 * Listen to incoming queries
		 *
		 * @return 			void
		 */
		public function edd_listen()
		{
			if (isset($_GET['verify_' . $this->keyname]) && $_GET['verify_' . $this->keyname]) {
				do_action('edd_verify_' . $this->keyname);
			}
		}
	}

endif;

new EDD_Vandario_Gateway;