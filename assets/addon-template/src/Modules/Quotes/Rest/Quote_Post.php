<?php

namespace Acme\Store_Addon\Modules\Quotes\Rest;

use Acme\Store_Addon\Modules\Quotes\Quotes_Module;
use MeuMouse\Headpress\Rest\Abstract_Route;
use MeuMouse\Headpress\Security\Rate_Limiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /headpress/v1/store/acme/quotes — store a quote request.
 *
 * @since 1.0.0
 * @version 1.0.0
 * @package Acme\Store_Addon\Modules\Quotes\Rest
 */
class Quote_Post extends Abstract_Route {

	/** @var string */
	protected string $route = '/store/acme/quotes';

	/** @var string */
	protected string $methods = 'POST';

	/** @var string Public storefront route. The default scope is "admin": always set it. */
	protected string $scope = 'store';

	/**
	 * Request parameters, validated and sanitized by WordPress before handle().
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @return array
	 */
	public function args(): array {
		return array(
			'name'       => array( 'type' => 'string', 'required' => true, 'minLength' => 2, 'maxLength' => 120, 'sanitize_callback' => 'sanitize_text_field' ),
			'email'      => array( 'type' => 'string', 'required' => true, 'format' => 'email' ),
			'product_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
			'quantity'   => array( 'type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 10000 ),
			'message'    => array( 'type' => 'string', 'default' => '', 'maxLength' => 2000, 'sanitize_callback' => 'sanitize_textarea_field' ),
		);
	}

	/**
	 * Response schema (mandatory for store routes; exported to the SDK contract).
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @return array
	 */
	public function schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-07/schema#',
			'title'      => 'AcmeQuote',
			'type'       => 'object',
			'properties' => array(
				'id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * Store the request.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$email = strtolower( (string) $request['email'] );

		if ( ! Rate_Limiter::hit( 'acme_quote', 5, HOUR_IN_SECONDS ) || ! Rate_Limiter::hit_subject( 'acme_quote_email', $email, 3, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'acme_rate_limited', __( 'Too many requests. Try again later.', 'acme-store-addon' ), array( 'status' => 429, 'retry_after' => Rate_Limiter::retry_after() ) );
		}

		$product = wc_get_product( (int) $request['product_id'] );

		if ( ! $product || 'publish' !== $product->get_status() || 'yes' !== $product->get_meta( '_acme_quotable' ) ) {
			return new WP_Error( 'acme_quote_unavailable', __( 'This product does not take quote requests.', 'acme-store-addon' ), array( 'status' => 404 ) );
		}

		$min = (int) $this->container->get( 'settings' )->get( Quotes_Module::ID, 'min_quantity', 1 );

		if ( (int) $request['quantity'] < $min ) {
			return new WP_Error( 'acme_validation_failed', __( 'Check the highlighted fields.', 'acme-store-addon' ), array(
				'status' => 422,
				/* translators: %d: minimum quantity. */
				'fields' => array( 'quantity' => sprintf( __( 'The minimum is %d.', 'acme-store-addon' ), $min ) ),
			) );
		}

		$id = wp_insert_post( array(
			'post_type'   => Quotes_Module::POST_TYPE,
			'post_status' => 'private',
			/* translators: 1: product name, 2: shopper name. */
			'post_title'  => sprintf( __( 'Quote: %1$s — %2$s', 'acme-store-addon' ), $product->get_name(), $request['name'] ),
			'meta_input'  => array(
				'_acme_name'       => $request['name'],
				'_acme_email'      => $email,
				'_acme_product_id' => $product->get_id(),
				'_acme_quantity'   => (int) $request['quantity'],
				'_acme_message'    => $request['message'],
			),
		), true );

		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'acme_quote_failed', __( 'The request could not be saved.', 'acme-store-addon' ), array( 'status' => 500 ) );
		}

		headpress_emit_event( 'acme.quote_created', array( 'id' => $id, 'product_id' => $product->get_id() ) );

		$response = new WP_REST_Response( array( 'id' => $id ), 201 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
