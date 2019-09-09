<?php
/**
 * REST API PaymentIntent controller.
 *
 * @since 3.6.0
 */

namespace SimplePay\Core\REST_API\v2;

use SimplePay\Core\REST_API\Controller;
use SimplePay\Core\Forms\Default_Form;
use SimplePay\Core\Payments;
use SimplePay\Core\Legacy;

use function SimplePay\Core\SimplePay;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaymentIntent_Controller.
 */
class PaymentIntent_Controller extends Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wpsp/v2';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'paymentintent';

	/**
	 * Registers the routes for PaymentIntents.
	 *
	 * @since 3.6.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/create',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE )
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/confirm',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'confirm_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE )
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Allows POST requests to this endpoint with a valid nonce.
	 *
	 * @since 3.6.0
	 *
	 * @param \WP_REST_Request Request data.
	 * @return bool True with a valid nonce.
	 */
	public function create_item_permissions_check( $request ) {
		$form_values = $request['form_values'];

		if ( ! isset( $form_values['_wpnonce'] ) || ! wp_verify_nonce( $form_values['_wpnonce'], 'simpay_payment_form' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Handles an incoming request to create a PaymentIntent.
	 *
	 * @since 3.6.0
	 *
	 * @param \WP_REST_Request $request {
	 *   Incoming REQUEST data.
	 *
	 *   @type int   $customer_id Customer ID previously generated with Payment Source.
	 *   @type int   $form_id Form ID used to generate PaymentIntent data.
	 *   @type array $form_data Client-generated formData information.
	 *   @type array $form_values Values of named fields in the payment form.
	 * }
	 * @return \WP_REST_Response
	 */
	public function create_item( $request ) {
		try {
			// Payment method is required (though the Stripe docs do not say this).
			$payment_method_id = isset( $request['payment_method_id'] ) ? $request['payment_method_id'] : false;

			if ( ! $payment_method_id ) {
				throw new \Exception( __( 'A payment method is required.', 'simple-pay' ) );
			}

			// Gather customer information.
			$customer_id = isset( $request['customer_id'] ) ? $request['customer_id'] : false;

			if ( ! $customer_id ) {
				throw new \Exception( __( 'A customer must be provided.', 'simple-pay' ) );
			}

			// Locate form.
			if ( ! isset( $request['form_id'] ) ) {
				throw new \Exception( __( 'Unable to locate payment form.', 'simple-pay' ) );
			}

			// Gather <form> information.
			$form_id     = $request['form_id'];
			$form_data   = $request['form_data'];
			$form_values = $request['form_values'];

			/** This filter is documented in includes/core/shortcodes.php */
			$form = apply_filters( 'simpay_form_view', '', $form_id );

			if ( empty( $form ) ) {
				$form = new Default_Form( $form_id );
			}

			// Handle legacy form processing.
			Legacy\Hooks\simpay_process_form( $form, $form_data, $form_values, $customer_id );

			// Generate arguments based on form data.
			$paymentintent_args = array_merge(
				Payments\PaymentIntent\get_args_from_payment_form_request( $form, $form_data, $form_values, $customer_id ),
				array(
					'customer'            => $customer_id,
					'payment_method'      => $payment_method_id,
					'confirmation_method' => 'manual',
					'confirm'             => true,
					'save_payment_method' => true,
					'expand'   => array(
						'customer',
					)
				)
			);

			/**
			 * Allows processing before a PaymentIntent is created from a payment form request.
			 *
			 * @since 3.6.0
			 *
			 * @param array                         $paymentintent_args Arguments used to create a PaymentIntent.
			 * @param SimplePay\Core\Abstracts\Form $form Form instance.
			 * @param array                         $form_data Form data generated by the client.
			 * @param array                         $form_values Values of named fields in the payment form.
			 * @param int                           $customer_id Stripe Customer ID.
			 */
			do_action(
				'simpay_before_paymentintent_from_payment_form_request',
				$paymentintent_args, $form, $form_data, $form_values, $customer_id
			);

			// Generate a PaymentIntent.
			$paymentintent = Payments\PaymentIntent\create( $paymentintent_args );

			/**
			 * Allows further processing after a PaymentIntent is created from a payment form request.
			 *
			 * @since 3.6.0
			 *
			 * @param \Stripe\PaymentIntent         $paymentintent Stripe PaymentIntent.
			 * @param SimplePay\Core\Abstracts\Form $form Form instance.
			 * @param array                         $form_data Form data generated by the client.
			 * @param array                         $form_values Values of named fields in the payment form.
			 * @param int                           $customer_id Stripe Customer ID.
			 */
			do_action(
				'simpay_after_paymentintent_from_payment_form_request',
				$paymentintent, $form, $form_data, $form_values, $customer_id
			);

			return $this->generate_payment_response( $paymentintent, $form, $form_data, $form_values, $customer_id );
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array(
					'message' => $e->getMessage(),
				),
				400
			);
		}
	}

	/**
	 * Confirms a PaymentIntent.
	 *
	 * @since 3.6.0
	 *
	 * @param \WP_REST_Request $request {
	 *   Incoming REQUEST data.
	 *
	 *   @type string $payment_intent_id
	 * }
	 * @return \WP_REST_Response
	 */
	public function confirm_item( $request ) {
		try {
			// Gather PaymentIntent information.
			$paymentintent_id = isset( $request['payment_intent_id'] ) ? $request['payment_intent_id'] : false;

			if ( ! $paymentintent_id ) {
				throw new \Exception( __( 'Unable to locate PaymentIntent', 'simple-pay' ) );
			}

			// Gather customer information.
			$customer_id = isset( $request['customer_id'] ) ? $request['customer_id'] : false;

			if ( ! $customer_id ) {
				throw new \Exception( __( 'A customer must be provided.', 'simple-pay' ) );
			}

			// Gather <form> information.
			$form_id     = $request['form_id'];
			$form_data   = $request['form_data'];
			$form_values = $request['form_values'];

			/** This filter is documented in includes/core/shortcodes.php */
			$form = apply_filters( 'simpay_form_view', '', $form_id );

			if ( empty( $form ) ) {
				$form = new Default_Form( $form_id );
			}

			$paymentintent = Payments\PaymentIntent\confirm( $paymentintent_id );

			return $this->generate_payment_response( $paymentintent, $form, $form_data, $form_values, $customer_id );
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array(
					'message' => $e->getMessage(),
				),
				400
			);
		}
	}

	/**
	 * Generates a payment response based on the PaymentIntent status.
	 *
	 * @since 3.6.0
	 *
	 * @param \Stripe\PaymentIntent         $paymentintent Stripe PaymentIntent.
	 * @param SimplePay\Core\Abstracts\Form $form Form instance.
	 * @param array                         $form_data Form data generated by the client.
	 * @param array                         $form_values Values of named fields in the payment form.
	 * @param int                           $customer_id Stripe Customer ID.
	 * @return \WP_REST_Response
	 */
	private function generate_payment_response( $paymentintent, $form, $form_data, $form_values, $customer_id ) {
		if ( $paymentintent->status == 'requires_action' && $paymentintent->next_action->type == 'use_stripe_sdk' ) {
			$response = new \WP_REST_Response(
				array(
					'requires_action'              => true,
					'payment_intent_client_secret' => $paymentintent->client_secret
				)
			);
		} else if ( $paymentintent->status == 'succeeded' ) {
			$response = new \WP_REST_Response(
				array(
					'success' => true,
				)
			);
		} else {
			$response = new \WP_REST_Response(
				array(
					'error' => __( 'Invalid PaymentIntent status', 'simple-pay' ),
				),
				500
			);
		}

		/**
		 * Allows further processing based on a PaymentIntent's status change.
		 *
		 * @since 3.6.0
		 *
		 * @param \Stripe\PaymentIntent         $paymentintent Stripe PaymentIntent.
		 * @param SimplePay\Core\Abstracts\Form $form Form instance.
		 * @param array                         $form_data Form data generated by the client.
		 * @param array                         $form_values Values of named fields in the payment form.
		 * @param int                           $customer_id Stripe Customer ID.
		 */
		do_action(
			'simpay_after_paymentintent_response_from_payment_form_request',
			$paymentintent, $form, $form_data, $form_values, $customer_id
		);

		return $response;
	}
}
