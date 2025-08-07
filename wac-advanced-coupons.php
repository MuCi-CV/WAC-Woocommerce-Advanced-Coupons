<?php
/**
 * Plugin Name: Cupones Avanzados para WooCommerce
 * Plugin URI:  https://github.com/MuCi-CV/WAC-Woocommerce-Advanced-Coupons
 * Description: Extiende las funcionalidades de los cupones de WooCommerce con un sistema de saldo reutilizable.
 * Version:     1.1.4
 * Author:      Carlos Vallory
 * Author URI:  https://github.com/MuCi-CV
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wac-advanced-coupons
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'WAC_Advanced_Coupons' ) ) {

    final class WAC_Advanced_Coupons {

        protected static $_instance = null;

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function __construct() {
            $this->define_constants();
            $this->includes();
            $this->init_hooks();
        }

        private function define_constants() {
            define( 'WAC_PLUGIN_FILE', __FILE__ );
            define( 'WAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
            define( 'WAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
        }

        private function includes() {
            require_once WAC_PLUGIN_DIR . 'includes/class-wac-coupon-type.php';
            require_once WAC_PLUGIN_DIR . 'includes/class-wac-coupon-balance-manager.php';
            require_once WAC_PLUGIN_DIR . 'includes/class-wac-admin-settings.php';
            require_once WAC_PLUGIN_DIR . 'includes/class-wac-frontend-display.php';
            require_once WAC_PLUGIN_DIR . 'includes/class-wac-integrations.php';
        }

        private function init_hooks() {
            add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
        }

        public function on_plugins_loaded() {
            // Check if WooCommerce is active
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', array( $this, 'wac_woocommerce_missing_notice' ) );
                return;
            }

            // Initialize classes
            WAC_Coupon_Type::instance();
            WAC_Coupon_Balance_Manager::instance();
            WAC_Admin_Settings::instance();
            WAC_Frontend_Display::instance();
            WAC_Integrations::instance();
        }

        public function wac_woocommerce_missing_notice() {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'Cupones Avanzados para WooCommerce requiere que WooCommerce esté instalado y activo.', 'wac-advanced-coupons' ); ?></p>
            </div>
            <?php
        }
    }
}

function WAC() {
    return WAC_Advanced_Coupons::instance();
}

// Global for backwards compatibility.
$GLOBALS['wac_advanced_coupons'] = WAC();
?>