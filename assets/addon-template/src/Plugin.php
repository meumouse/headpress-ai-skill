<?php

namespace Acme\Store_Addon;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the add-on's Headpress modules.
 *
 * @since 1.0.0
 * @version 1.0.0
 * @package Acme\Store_Addon
 */
class Plugin {

	/**
	 * Module classes. Each one is toggled on its own in Headpress → Modules.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const MODULES = array(
		Modules\Quotes\Quotes_Module::class,
	);

	/**
	 * Hook into Headpress.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @return void
	 */
	public function boot(): void {
		add_action( 'Headpress/Modules/Register', array( $this, 'register_modules' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Add every module to the registry. One failing module never stops the others.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param \MeuMouse\Headpress\Modules\Module_Registry $registry Headpress module registry.
	 * @return void
	 */
	public function register_modules( $registry ): void {
		foreach ( self::MODULES as $class ) {
			try {
				$registry->add( new $class() );
			} catch ( Throwable $e ) {
				headpress()->container()->get( 'logger' )->error( 'acme-store-addon: ' . $class . ' failed to register: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Load translations.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'acme-store-addon', false, dirname( plugin_basename( ACME_STORE_ADDON_FILE ) ) . '/languages' );
	}
}
