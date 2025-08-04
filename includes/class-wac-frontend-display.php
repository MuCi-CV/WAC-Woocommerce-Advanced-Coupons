<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WAC_Frontend_Display' ) ) {

    class WAC_Frontend_Display {

        protected static $_instance = null;

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function __construct() {
            // Check if frontend display is enabled in settings
            if ( get_option( 'wac_enable_frontend_balance_display', true ) ) {
                add_action( 'woocommerce_cart_totals_after_coupon', array( $this, 'wac_display_balance_in_cart' ) );
                add_action( 'woocommerce_before_my_account_navigation', array( $this, 'wac_display_balance_in_my_account' ) );
            }

            // Check if balance checker widget is enabled
            if ( get_option( 'wac_enable_balance_checker_widget', true ) ) {
                add_action( 'widgets_init', array( $this, 'wac_register_balance_checker_widget' ) );
                add_shortcode( 'wac_coupon_balance_checker', array( $this, 'wac_coupon_balance_checker_shortcode' ) );
            }
        }

        /**
         * Display coupon balance in the cart totals.
         */
        public function wac_display_balance_in_cart() {
            if ( WC()->cart->has_discount() ) {
                foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
                    $coupon = new WC_Coupon( $coupon_code );
                    if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                        $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                        if ( $current_balance > 0 ) {
                            ?>
                            <tr class="coupon-balance-row">
                                <th><?php esc_html_e( 'Saldo del Cupón', 'wac-advanced-coupons' ); ?>: <span class="wac-coupon-code"><?php echo esc_html( $coupon_code ); ?></span></th>
                                <td data-title="<?php esc_attr_e( 'Saldo del Cupón', 'wac-advanced-coupons' ); ?>"><?php echo wc_price( $current_balance ); ?></td>
                            </tr>
                            <?php
                        } else {
                            ?>
                            <tr class="coupon-balance-row exhausted">
                                <th><?php esc_html_e( 'Saldo del Cupón', 'wac-advanced-coupons' ); ?>: <span class="wac-coupon-code"><?php echo esc_html( $coupon_code ); ?></span></th>
                                <td data-title="<?php esc_attr_e( 'Saldo del Cupón', 'wac-advanced-coupons' ); ?>"><strong class="wac-exhausted-balance"><?php esc_html_e( 'Agotado', 'wac-advanced-coupons' ); ?></strong></td>
                            </tr>
                            <?php
                        }
                    }
                }
            }
        }

        /**
         * Display user's balance coupons in My Account.
         */
        public function wac_display_balance_in_my_account() {
            if ( ! is_user_logged_in() ) {
                return;
            }

            $customer_id = get_current_user_id();
            $args        = array(
                'post_type'      => 'shop_coupon',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'     => 'discount_type',
                        'value'   => 'wac_balance_coupon',
                        'compare' => '=',
                    ),
                    array(
                        'key'     => 'customer_email', // Assuming coupons are tied to email for restricted usage
                        'value'   => wp_get_current_user()->user_email,
                        'compare' => 'LIKE', // Or use 'IN' if multiple emails are possible
                    ),
                    array(
                        'key'     => 'wac_current_balance',
                        'value'   => 0,
                        'compare' => '>',
                        'type'    => 'NUMERIC',
                    ),
                ),
            );

            // Filter for coupons used by the customer in any order
            $customer_orders = wc_get_orders( array(
                'customer_id' => $customer_id,
                'limit'       => -1,
                'return'      => 'ids',
            ) );

            $used_coupon_codes = array();
            if ( $customer_orders ) {
                foreach ( $customer_orders as $order_id ) {
                    $order = wc_get_order( $order_id );
                    if ( $order ) {
                        $used_coupon_codes = array_merge( $used_coupon_codes, $order->get_used_coupons() );
                    }
                }
                $used_coupon_codes = array_unique( $used_coupon_codes );
            }

            $user_coupons_query = new WP_Query( $args );

            // Further filter to include only coupons that the current user has access to or has used
            $active_user_balance_coupons = array();
            if ( $user_coupons_query->have_posts() ) {
                while ( $user_coupons_query->have_posts() ) {
                    $user_coupons_query->the_post();
                    $coupon = new WC_Coupon( get_the_ID() );

                    // Check if this coupon is valid for the current user
                    // WooCommerce coupon usage restrictions are complex. We'll simplify here:
                    // If the coupon has email restrictions, check if the current user's email is among them.
                    // If not, consider if the coupon has been used by this user (from $used_coupon_codes).
                    // Or, if it's a general balance coupon not restricted by email, but meant for a specific group of users or purchase.
                    // For this example, let's just check if it's assigned to customer_email or generally available if no restriction.
                    $email_restrictions = $coupon->get_email_restrictions();
                    $user_email         = wp_get_current_user()->user_email;

                    if ( empty( $email_restrictions ) || in_array( $user_email, $email_restrictions, true ) || in_array( $coupon->get_code(), $used_coupon_codes, true ) ) {
                        $active_user_balance_coupons[] = $coupon;
                    }
                }
                wp_reset_postdata();
            }

            if ( ! empty( $active_user_balance_coupons ) ) {
                ?>
                <div class="woocommerce-MyAccount-navigation-link woocommerce-MyAccount-navigation-link--wac-balance-coupons">
                    <a href="#wac-my-account-balance-coupons" onclick="jQuery('.woocommerce-MyAccount-content').hide(); jQuery('#wac-my-account-balance-coupons').show(); return false;"><?php esc_html_e( 'Mis Cupones con Saldo', 'wac-advanced-coupons' ); ?></a>
                </div>
                <div id="wac-my-account-balance-coupons" class="woocommerce-MyAccount-content" style="display:none;">
                    <h2><?php esc_html_e( 'Mis Cupones con Saldo Reutilizable', 'wac-advanced-coupons' ); ?></h2>
                    <table class="woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
                        <thead>
                            <tr>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-coupon-code"><span class="nobr"><?php esc_html_e( 'Código', 'wac-advanced-coupons' ); ?></span></th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-initial-balance"><span class="nobr"><?php esc_html_e( 'Saldo Inicial', 'wac-advanced-coupons' ); ?></span></th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-current-balance"><span class="nobr"><?php esc_html_e( 'Saldo Actual', 'wac-advanced-coupons' ); ?></span></th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-expires"><span class="nobr"><?php esc_html_e( 'Expira', 'wac-advanced-coupons' ); ?></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $active_user_balance_coupons as $coupon ) :
                                $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                                $initial_balance = (float) $coupon->get_meta( 'wac_initial_balance' );
                                $date_expires    = $coupon->get_date_expires();
                                ?>
                                <tr class="woocommerce-orders-table__row">
                                    <td data-title="<?php esc_attr_e( 'Código', 'wac-advanced-coupons' ); ?>"><?php echo esc_html( $coupon->get_code() ); ?></td>
                                    <td data-title="<?php esc_attr_e( 'Saldo Inicial', 'wac-advanced-coupons' ); ?>"><?php echo wc_price( $initial_balance ); ?></td>
                                    <td data-title="<?php esc_attr_e( 'Saldo Actual', 'wac-advanced-coupons' ); ?>"><?php echo wc_price( $current_balance ); ?></td>
                                    <td data-title="<?php esc_attr_e( 'Expira', 'wac-advanced-coupons' ); ?>">
                                        <?php
                                        if ( $date_expires ) {
                                            echo esc_html( date_i18n( wc_date_format(), $date_expires->getTimestamp() ) );
                                        } else {
                                            esc_html_e( 'Nunca', 'wac-advanced-coupons' );
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <script type="text/javascript">
                    // Basic JS to show/hide the content when navigation link is clicked.
                    // This can be improved with proper routing if needed for My Account.
                    jQuery(document).ready(function($) {
                        $('.woocommerce-MyAccount-navigation-link--wac-balance-coupons a').on('click', function(e) {
                            e.preventDefault();
                            $('.woocommerce-MyAccount-content').hide();
                            $('#wac-my-account-balance-coupons').show();
                        });
                        // If hash is present in URL, show the corresponding tab.
                        if (window.location.hash === '#wac-my-account-balance-coupons') {
                            $('.woocommerce-MyAccount-content').hide();
                            $('#wac-my-account-balance-coupons').show();
                            $('.woocommerce-MyAccount-navigation-link.is-active').removeClass('is-active');
                            $('.woocommerce-MyAccount-navigation-link--wac-balance-coupons').addClass('is-active');
                        }
                    });
                </script>
                <?php
            }
        }

        /**
         * Register the "Gift Card Balance Checker" widget.
         */
        public function wac_register_balance_checker_widget() {
            require_once WAC_PLUGIN_DIR . 'includes/class-wac-coupon-balance-checker-widget.php';
            register_widget( 'WAC_Coupon_Balance_Checker_Widget' );
        }

        /**
         * Shortcode for the balance checker.
         *
         * @return string
         */
        public function wac_coupon_balance_checker_shortcode() {
            ob_start();
            include WAC_PLUGIN_DIR . 'templates/wac-coupon-balance-checker.php';
            return ob_get_clean();
        }
    }
}