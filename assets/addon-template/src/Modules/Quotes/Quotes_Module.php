<?php

namespace Acme\Store_Addon\Modules\Quotes;

use MeuMouse\Headpress\Core\Container;
use MeuMouse\Headpress\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Quote requests: shoppers ask for a quote for a product from the storefront.
 *
 * Contract surface (never rename): module id "acme_quotes" → modules.acme_quotes in
 * GET /headpress/v1/store/config and extensions.headpress.acme_quotes in the Store API.
 *
 * @since 1.0.0
 * @version 1.0.0
 * @package Acme\Store_Addon\Modules\Quotes
 */
class Quotes_Module extends Abstract_Module {

	/**
	 * Module id.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const ID = 'acme_quotes';

	/**
	 * Post type holding the requests (private, not public content).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const POST_TYPE = 'acme_quote';

	/**
	 * Service container.
	 *
	 * @since 1.0.0
	 * @var Container|null
	 */
	private ?Container $container = null;

	/** @return string */
	public function id(): string {
		return self::ID;
	}

	/** @return string */
	public function label(): string {
		return __( 'Quote requests', 'acme-store-addon' );
	}

	/** @return string */
	public function description(): string {
		return __( 'Lets shoppers request a quote for products marked as quotable.', 'acme-store-addon' );
	}

	/** @return string */
	public function icon(): string {
		return 'message-square-detail';
	}

	/** @return string */
	public function category(): string {
		return 'commerce';
	}

	/** @return array */
	public function requirements(): array {
		return array( 'woocommerce' );
	}

	/**
	 * Settings, declared once: Headpress builds the panel form, validation and public config.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @return array
	 */
	public function settings(): array {
		return array(
			array(
				'key'         => 'min_quantity',
				'type'        => 'number',
				'integer'     => true,
				'min'         => 1,
				'label'       => __( 'Minimum quantity', 'acme-store-addon' ),
				'description' => __( 'Smallest quantity a shopper may ask a quote for.', 'acme-store-addon' ),
				'default'     => 1,
				'visibility'  => 'public',
			),
			array(
				'key'         => 'notify_email',
				'type'        => 'email',
				'label'       => __( 'Notification email', 'acme-store-addon' ),
				'description' => __( 'Who receives new quote requests.', 'acme-store-addon' ),
				'default'     => '',
				'visibility'  => 'private',
			),
		);
	}

	/**
	 * Services and health checks (runs for every registered module, enabled or not).
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param Container $container Headpress container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$this->container = $container;

		add_filter( 'Headpress/Health/Checks', function( array $checks ): array {
			$active = $this->container->get( 'modules' )->is_active( self::ID );
			$email  = (string) $this->container->get( 'settings' )->get( self::ID, 'notify_email', '' );

			if ( $active && '' === $email ) {
				$checks[] = array(
					'id'      => self::ID . '_email',
					'label'   => __( 'Quote requests', 'acme-store-addon' ),
					'status'  => 'warning',
					'message' => __( 'No notification email is set: quote requests are stored but nobody is told.', 'acme-store-addon' ),
				);
			}

			return $checks;
		} );
	}

	/**
	 * Hooks — only while the module is available and enabled.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @return void
	 */
	public function boot(): void {
		register_post_type( self::POST_TYPE, array(
			'label'           => __( 'Quote requests', 'acme-store-addon' ),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'woocommerce',
			'show_in_rest'    => false,
			'supports'        => array( 'title', 'custom-fields' ),
			'capability_type' => 'shop_order',
			'map_meta_cap'    => true,
		) );

		// New event name, so subscribers (emails, webhooks) can use it. Payload carries ids only.
		add_filter( 'Headpress/Events/Catalog', function( array $catalog ): array {
			$catalog['acme.quote_created'] = 'A shopper requested a quote. Payload: id, product_id.';
			return $catalog;
		} );

		// The route requires a captcha token when the captcha module is on.
		add_filter( 'Headpress/Captcha/Routes', function( array $routes ): array {
			$routes[ '/' . HEADPRESS_REST_NAMESPACE . '/store/acme/quotes' ] = 'acme_quote';
			return $routes;
		} );

		// Notify by email in a background job, never inside the shopper's request.
		add_action( 'Headpress/Events/acme.quote_created', function( array $payload ): void {
			$this->container->get( 'jobs' )->schedule_once( 'acme_quote_notify', 0, array( (int) $payload['id'] ) );
		} );
		add_action( 'acme_quote_notify', array( $this, 'notify' ) );
	}

	/**
	 * Send the notification email for a stored request.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param int $quote_id Quote post id.
	 * @return void
	 */
	public function notify( int $quote_id ): void {
		$to   = (string) $this->container->get( 'settings' )->get( self::ID, 'notify_email', '' );
		$post = get_post( $quote_id );

		if ( '' === $to || ! $post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		wp_mail( $to, $post->post_title, admin_url( 'post.php?post=' . $quote_id . '&action=edit' ) );
	}

	/** @return string[] */
	public function routes(): array {
		return array( Rest\Quote_Post::class );
	}

	/**
	 * Store API: extensions.headpress.acme_quotes on products — cheap, runs per product in lists.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @return array
	 */
	public function store_api_extensions(): array {
		return array(
			array(
				'endpoint'    => 'product',
				'description' => 'Whether the product is sold by quote request.',
				'data'        => fn( $product ) => array( 'quotable' => $product instanceof \WC_Product && 'yes' === $product->get_meta( '_acme_quotable' ) ),
				'schema'      => fn() => array( 'quotable' => array( 'type' => 'boolean' ) ),
			),
		);
	}

	/**
	 * Computed public values, next to the public settings in modules.acme_quotes.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param array $settings Module settings.
	 * @return array
	 */
	public function public_config( array $settings ): array {
		return array( 'endpoint' => rest_url( HEADPRESS_REST_NAMESPACE . '/store/acme/quotes' ) );
	}

	/** @return array */
	public function public_config_schema(): array {
		return array( 'endpoint' => array( 'type' => 'string', 'format' => 'uri' ) );
	}
}
