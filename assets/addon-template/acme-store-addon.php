<?php

/**
 * Plugin Name:          Acme Store Add-on
 * Description:          Store-specific modules for the Acme storefront, built on Headpress.
 * Version:              1.0.0
 * Requires at least:    6.8
 * Requires PHP:         8.1
 * Requires Plugins:     headpress, woocommerce
 * WC requires at least: 9.0
 * Author:               Acme
 * License:              GPLv2 or later
 * Text Domain:          acme-store-addon
 * Domain Path:          /languages
 *
 * Template: rename "Acme" / "acme" / "acme-store-addon" to the project's vendor, prefix and slug.
 *
 * @package Acme\Store_Addon
 */

defined( 'ABSPATH' ) || exit;

define( 'ACME_STORE_ADDON_VERSION', '1.0.0' );
define( 'ACME_STORE_ADDON_FILE', __FILE__ );
define( 'ACME_STORE_ADDON_DIR', plugin_dir_path( __FILE__ ) );

/**
 * PSR-4 autoloader for Acme\Store_Addon\ → src/. Deliberately not a Composer authoritative
 * classmap: a single file deployed without regenerating it would break every REST route.
 */
spl_autoload_register( function( string $class ): void {
	$prefix = 'Acme\\Store_Addon\\';

	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$file = ACME_STORE_ADDON_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

	if ( is_readable( $file ) ) {
		require $file;
	}
} );

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Boot after Headpress is loaded and before it collects modules on init priority 1.
add_action( 'plugins_loaded', function() {
	if ( ! function_exists( 'headpress_register_module' ) || ! class_exists( \MeuMouse\Headpress\Modules\Abstract_Module::class ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Acme Store Add-on needs Headpress to be active.', 'acme-store-addon' ) . '</p></div>';
		} );

		return;
	}

	( new \Acme\Store_Addon\Plugin() )->boot();
}, 11 );
