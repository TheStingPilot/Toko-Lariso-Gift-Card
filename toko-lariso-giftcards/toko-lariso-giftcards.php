<?php
/**
 * Plugin Name: Toko Lariso Giftcards
 * Description: WooCommerce giftcards as multi-purpose voucher store credit with Cart and Checkout Blocks support.
 * Version: 0.2.00
 * Author: Toko Lariso
 * Text Domain: toko-lariso-giftcards
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * WC requires at least: 8.5
 * WC tested up to: 11.1
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

define( 'TOKO_LARISO_GIFTCARDS_VERSION', '0.2.00' );
define( 'TOKO_LARISO_GIFTCARDS_FILE', __FILE__ );
define( 'TOKO_LARISO_GIFTCARDS_PATH', plugin_dir_path( __FILE__ ) );
define( 'TOKO_LARISO_GIFTCARDS_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-activator.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-settings.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-debug.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-wpml.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-repository.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-email.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-product-type.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-shipping.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-sendcloud-compatibility.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-cart.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-store-api.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-order.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-pdf.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-my-account.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-admin.php';
require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-plugin.php';

register_activation_hook( __FILE__, array( 'Toko_Lariso_Giftcards_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Toko_Lariso_Giftcards_Activator', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Toko_Lariso_Giftcards_Activator', 'uninstall' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Toko Lariso Giftcards requires WooCommerce to be active.', 'toko-lariso-giftcards' );
					echo '</p></div>';
				}
			);
			return;
		}

		Toko_Lariso_Giftcards_Plugin::instance()->init();
	}
);
