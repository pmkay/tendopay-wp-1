<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 02.01.18
 * Time: 06:10
 */

namespace TendoPay;

use TendoPay\API\Authorization_Endpoint;
use TendoPay\API\Description_Endpoint;
use TendoPay\API\Hash_Calculator;
use TendoPay\API\Tendopay_API;
use TendoPay\Exceptions\TendoPay_Integration_Exception;
use \WC_Payment_Gateway;
use \WC_Order;

/**
 * This class implements the woocommerce gateway mechanism.
 *
 * @package TendoPay
 */
class Gateway extends WC_Payment_Gateway {
	/**
	 * Unique ID of the gateway.
	 */
	const GATEWAY_ID = 'tendopay';

	/**
	 * Prepares the gateway configuration.
	 */
	function __construct() {
		$this->id         = self::GATEWAY_ID;
		$this->has_fields = false;

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'method_title' );
		$this->method_title = $this->get_option( 'method_title' );
		$this->description  = $this->get_option( 'method_description' );

		$this->view_transaction_url = Tendopay_API::get_view_uri_pattern();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );
	}

	/**
	 * Prepares settings forms for plugin's settings page.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'               => array(
				'title'   => __( 'Enable/Disable', 'tendopay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable TendoPay Integration', 'tendopay' ),
				'default' => 'yes'
			),
			'method_title'          => array(
				'title'       => __( 'Payment gateway title', 'tendopay' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'tendopay' ),
				'default'     => __( 'Pay with TendoPay', 'tendopay' ),
				'desc_tip'    => true,
			),
			'method_description'    => array(
				'title'       => __( 'Payment method description', 'tendopay' ),
				'description' => __( 'Additional information displayed to the customer after selecting TendoPay method', 'tendopay' ),
				'type'        => 'textarea',
				'default'     => '',
				'desc_tip'    => true,
			),
			'tendo_sandbox_enabled' => array(
				'title'       => __( 'Enable SANDBOX', 'tendopay' ),
				'description' => __( 'Enable SANDBOX if you want to test integration with TendoPay without real transactions.', 'tendopay' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'tendo_pay_merchant_id' => array(
				'title'   => __( 'Tendo Pay Merchant ID', 'tendopay' ),
				'type'    => 'text',
				'default' => ''
			),
			'tendo_secret'          => array(
				'title'   => __( 'Secret', 'tendopay' ),
				'type'    => 'password',
				'default' => ''
			),
			'tendo_client_id'       => array(
				'title'   => __( 'API Client ID', 'tendopay' ),
				'type'    => 'text',
				'default' => ''
			),
			'tendo_client_secret'   => array(
				'title'   => __( 'API Client Secret', 'tendopay' ),
				'type'    => 'password',
				'default' => ''
			),
		);
	}

	/**
	 * Processes the payment. This method is called right after customer clicks the `Place order` button.
	 *
	 * @param int $order_id ID of the order that customer wants to pay.
	 *
	 * @return array status of the payment and redirect url. The status is always `success` because if there was
	 *         any problem, this method would rethrow an exception.
	 *
	 * @throws TendoPay_Integration_Exception rethrown either from {@link Authorization_Endpoint}
	 *         or {@link Description_Endpoint}
	 * @throws \GuzzleHttp\Exception\GuzzleException  when there was a problem in communication with the API (originally
	 *         thrown by guzzle http client)
	 */
	public function process_payment( $order_id ) {
		$order = new WC_Order( (int) $order_id );

		$auth_token = null;

		try {
			$auth_token = Authorization_Endpoint::request_token( $order );
			Description_Endpoint::set_description( $auth_token, $order );
		} catch ( \Exception $exception ) {
			error_log( $exception );
			throw new TendoPay_Integration_Exception(
				__( 'Could not communicate with TendoPay', 'tendopay' ), $exception );
		}

		$redirect_args = [
			'amount'                => (int) $order->get_total(),
			'authorisation_token'   => $auth_token,
			'customer_reference_1'  => (string) $order->get_id(),
			'customer_reference_2'  => (string) $order->get_order_key(),
			'redirect_url'          => get_site_url( get_current_blog_id(), 'tendopay-result' ),
			'tendo_pay_merchant_id' => (string) $this->get_option( 'tendo_pay_merchant_id' ),
			'vendor'                => get_bloginfo( 'blogname' )
		];

		$redirect_args = apply_filters( 'tendopay_process_payment_redirect_args', $redirect_args, $order, $this,
			$auth_token );

		$hash_calc             = new Hash_Calculator( $this->get_option( 'tendo_secret' ) );
		$redirect_args_hash    = $hash_calc->calculate( $redirect_args );
		$redirect_args['hash'] = $redirect_args_hash;

		$redirect_args = apply_filters( 'tendopay_process_payment_redirect_args_after_hash', $redirect_args, $order,
			$this, $auth_token );

		global $woocommerce;

		$redirect_args = urlencode_deep( $redirect_args );

		$redirect_url = add_query_arg( $redirect_args, Tendopay_API::get_redirect_uri() );
		$redirect_url = apply_filters( 'tendopay_process_payment_redirect_url', $redirect_url, $redirect_args,
			$order, $this, $auth_token );

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url
		);
	}
}
