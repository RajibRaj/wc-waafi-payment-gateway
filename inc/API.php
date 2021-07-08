<?php

namespace WCWPG;

class API {
	/**
	 * Schema version for the API
	 *
	 * @var string
	 */
	private $schema_version = '1.0';

	/**
	 * Gateway object
	 *
	 * @var WC_Waafi_Payment_Gateway
	 */
	private $gateway;

	/**
	 * API constructor
	 *
	 * @param WC_Waafi_Payment_Gateway $gateway
	 * @param int $order_id
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
		
		$this->api_url = $this->gateway->testmode
			? 'https://stagingsandbox.safarifoneict.com/asm'
			: 'https://api.waafi.com/asm';
	}

	public function initiate_payment( $order_id ) {
		$order        = wc_get_order( $order_id );
		$request_args = $this->prepare_data_to_initiate_api_call( $order );
		//return $request_args;

		if ( empty( $request_args ) ) {
			return (object) [ 'status' => 'error', 'message' => __( 'There is some problem in the server. Sorry for the inconvenience!', 'wc-waafi-payment-gateway' ) ];
		}

		// add some data to the order meta
		update_post_meta( $order_id, '_wcwpg_request_id', $request_args['requestId'] );
		update_post_meta( $order_id, '_wcwpg_reference_id', $request_args['serviceParams']['transactionInfo']['referenceId'] );

		// Step 1
		// authenticate the API with the merchant
		$auth_request = wp_remote_post(
			$this->api_url,
			[
				'headers'      => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'         => wp_json_encode( $request_args ),
				'method'       => 'POST',
				'timeout'      => 60,
				'redirection'  => 5,
				'blocking'     => true,
			],
		);

		$code           = wp_remote_retrieve_response_code( $auth_request );
		$auth_response  = json_decode( wp_remote_retrieve_body( $auth_request ) );

		if ( 200 !== $code || $auth_response->params == new \stdClass() ) {
			return (object) [ 'status' => 'error', 'message' => __( 'There is some problem in the server. Sorry for the inconvenience!', 'wc-waafi-payment-gateway' ) ];
		}

		// Step 2
		// populate the waafi pay payment form
		$hppurl       = $auth_response->params->hppUrl;
		$hpprequestid = $auth_response->params->hppRequestId;
		$referenceid  = $auth_response->params->referenceId;
		
		$form_generate_request = wp_remote_post(
			$hppurl,
			[
				'method'        => 'POST',
				'timeout'       => 60,
				'redirection'   => 5,
				'blocking'      => true,
				'body'        => [
					'hppRequestId' => $hpprequestid,
					'referenceId'  => $referenceid
				],
			],
		);

		$code     = wp_remote_retrieve_response_code( $form_generate_request );
		$response = wp_remote_retrieve_body( $form_generate_request );

		if ( 200 !== $code ) {
			return (object) [ 'status' => 'error', 'message' => __( 'There is some problem in the server. Sorry for the inconvenience!', 'wc-waafi-payment-gateway' ) ];
		}

		return (object) [ 'status' => 'success', 'data' => $response ];
	}

	/**
	 * Prepare the data to send the api call
	 *
	 * @param int $order_id
	 * @return string JSON object
	 */
	public function prepare_data_to_initiate_api_call( $order ) {
		$gateway  = $this->gateway;

		if ( ! is_a( $order, 'WC_Order' ) ) {
			return [];
		}
		$order_id = $order->get_id();

		$data = [
			'schemaVersion'          => $this->schema_version,
			'requestId'              => 'wcwpg' . $order_id . time(),
			'timestamp'              => strtotime( $order->get_date_created() ),
			'channelName'            => 'WEB',
			'serviceName'            => 'HPP_PURCHASE',
			'serviceParams' => [
				'storeId'               => $gateway->store,
				'hppKey'                => $gateway->hpp,
				'merchantUid'           => $gateway->merchant,
				'hppSuccessCallbackUrl' => $this->callback_url( $order_id ),
				'hppFailureCallbackUrl' => $this->callback_url( $order_id ),
				'hppRespDataFormat'     => 2,
				'paymentMethod'         => $gateway->payment_method, // todo: add form field for customer
				'payerInfo'             => [],
				'transactionInfo' => [
					'referenceId'          => $gateway->invoice_prefix . $order_id . time(), //$gateway->invoice_prefix . $order_id
					'invoiceId'            => $gateway->ref_prefix . $order_id . time(), //$gateway->ref_prefix . $order_id
					'amount'               => $order->get_total(),
					'currency'             => 'USD', // currently it is supporting only usd
					'description'          => sprintf( __( 'The payment is for the order id: %1$s from %2$s.', 'wc-waafi-payment-gateway' ), $order_id, get_bloginfo( 'name' ) ),
				],
			],
		];

		return $data;
	}

	/**
	 * Callback url to return after payment
	 * returning a special url to trigger an action to process the data
	 *
	 * @param int $order_id
	 * @return void
	 */
	private function callback_url( $order_id ) {
		return home_url( '/wc-api/' . $this->gateway->api_callback );
		/* return add_query_arg( array(
			'wc-api' => $this->gateway->api_callback,
			'order'  => $order_id,
		), home_url( '/' ) ); */
	}
}
