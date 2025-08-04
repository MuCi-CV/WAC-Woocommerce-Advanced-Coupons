<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WAC_Coupon_Type' ) ) {

    class WAC_Coupon_Type {

        protected static $_instance = null;

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function __construct() {
            add_filter( 'woocommerce_coupon_discount_types', array( $this, 'wac_add_coupon_type' ) );
            add_action( 'woocommerce_coupon_options', array( $this, 'wac_add_coupon_options' ) );
            add_action( 'woocommerce_coupon_options_save', array( $this, 'wac_save_coupon_options' ) );
            add_action( 'woocommerce_coupon_data_tabs', array( $this, 'wac_add_balance_tab' ) );
            add_action( 'woocommerce_coupon_data_panels', array( $this, 'wac_add_balance_panel' ) );
        }

        /**
         * Add "Balance Coupon" to coupon types dropdown.
         *
         * @param array $discount_types Existing discount types.
         * @return array Modified discount types.
         */
        public function wac_add_coupon_type( $discount_types ) {
            $discount_types['wac_balance_coupon'] = esc_html__( 'Cupón con Saldo Reutilizable', 'wac-advanced-coupons' );
            return $discount_types;
        }

        /**
         * Display coupon options for 'Balance Coupon' type.
         */
        public function wac_add_coupon_options() {
            global $thepostid;

            // Show a specific field only for our custom coupon type.
            woocommerce_wp_text_input(
                array(
                    'id'            => 'wac_initial_balance',
                    'label'         => esc_html__( 'Saldo Inicial del Cupón', 'wac-advanced-coupons' ),
                    'placeholder'   => wc_format_localized_price( 0 ),
                    'description'   => esc_html__( 'Define el valor total inicial de este cupón.', 'wac-advanced-coupons' ),
                    'data_type'     => 'price',
                    'wrapper_class' => 'wac-balance-coupon-field options_group', // Use this class to show/hide with JS
                )
            );
            // Hidden field for current balance, updated dynamically or on save.
            woocommerce_wp_text_input(
                array(
                    'id'            => 'wac_current_balance',
                    'label'         => esc_html__( 'Saldo Actual del Cupón', 'wac-advanced-coupons' ),
                    'placeholder'   => wc_format_localized_price( 0 ),
                    'description'   => esc_html__( 'El saldo restante de este cupón. Se actualiza automáticamente.', 'wac-advanced-coupons' ),
                    'data_type'     => 'price',
                    'custom_attributes' => array(
                        'readonly' => 'readonly', // Make it read-only in the admin
                    ),
                    'wrapper_class' => 'wac-balance-coupon-field options_group',
                )
            );

            // Add some JS to hide/show fields based on coupon type selection
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    function wacToggleBalanceFields() {
                        var selected_type = $('#discount_type').val();
                        if (selected_type === 'wac_balance_coupon') {
                            $('.wac-balance-coupon-field').show();
                        } else {
                            $('.wac-balance-coupon-field').hide();
                        }
                    }
                    wacToggleBalanceFields(); // Run on load
                    $('#discount_type').on('change', wacToggleBalanceFields); // Run on change
                });
            </script>
            <?php
        }

        /**
         * Save coupon options for 'Balance Coupon' type.
         *
         * @param int $post_id The coupon post ID.
         */
        public function wac_save_coupon_options( $post_id ) {
            $coupon_type = get_post_meta( $post_id, 'discount_type', true );

            if ( 'wac_balance_coupon' === $coupon_type ) {
                $initial_balance = isset( $_POST['wac_initial_balance'] ) ? wc_format_decimal( wp_unslash( $_POST['wac_initial_balance'] ) ) : 0;
                update_post_meta( $post_id, 'wac_initial_balance', $initial_balance );

                // If no current balance is set (new coupon), set it to initial.
                if ( '' === get_post_meta( $post_id, 'wac_current_balance', true ) ) {
                    update_post_meta( $post_id, 'wac_current_balance', $initial_balance );
                }
            } else {
                // If coupon type changed from 'wac_balance_coupon', remove custom meta.
                delete_post_meta( $post_id, 'wac_initial_balance' );
                delete_post_meta( $post_id, 'wac_current_balance' );
            }
        }

        /**
         * Add a new tab for "Balance History" in coupon data.
         *
         * @param array $tabs
         * @return array
         */
        public function wac_add_balance_tab( $tabs ) {
            $tabs['wac_balance_history'] = array(
                'label'    => esc_html__( 'Historial de Saldo', 'wac-advanced-coupons' ),
                'target'   => 'wac_coupon_balance_history',
                'class'    => array( 'wac-balance-coupon-data-tab', 'hide_if_regular_coupon' ),
            );
            return $tabs;
        }

        /**
         * Display the content for the "Balance History" tab.
         */
        public function wac_add_balance_panel() {
            global $thepostid;
            $coupon_id = $thepostid;
            $coupon    = new WC_Coupon( $coupon_id );
            $history   = get_post_meta( $coupon_id, 'wac_coupon_usage_history', true );
            $history   = is_array( $history ) ? $history : array();

            // Only show the history panel if it's a balance coupon.
            $coupon_type = get_post_meta( $coupon_id, 'discount_type', true );
            if ( 'wac_balance_coupon' !== $coupon_type ) {
                echo '<div id="wac_coupon_balance_history" class="panel woocommerce_options_panel wac-balance-coupon-data-tab" style="display:none;">';
                echo '<p>' . esc_html__( 'Este cupón no es un "Cupón con Saldo Reutilizable", por lo tanto no tiene historial de saldo.', 'wac-advanced-coupons' ) . '</p>';
                echo '</div>';
                return;
            }
            ?>
            <div id="wac_coupon_balance_history" class="panel woocommerce_options_panel wac-balance-coupon-data-tab">
                <div class="options_group">
                    <?php if ( ! empty( $history ) ) : ?>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Fecha', 'wac-advanced-coupons' ); ?></th>
                                    <th><?php esc_html_e( 'ID de Pedido', 'wac-advanced-coupons' ); ?></th>
                                    <th><?php esc_html_e( 'Monto Usado', 'wac-advanced-coupons' ); ?></th>
                                    <th><?php esc_html_e( 'Saldo Restante', 'wac-advanced-coupons' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( array_reverse( $history ) as $record ) : // Show most recent first ?>
                                    <tr>
                                        <td><?php echo esc_html( date_i18n( wc_date_format() . ' ' . wc_time_format(), $record['timestamp'] ) ); ?></td>
                                        <td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $record['order_id'] . '&action=edit' ) ); ?>"><?php echo esc_html( $record['order_id'] ); ?></a></td>
                                        <td><?php echo wc_price( $record['amount_used'] ); ?></td>
                                        <td><?php echo wc_price( $record['remaining_balance'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e( 'Este cupón aún no ha sido utilizado.', 'wac-advanced-coupons' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    function wacToggleBalanceHistoryTab() {
                        var selected_type = $('#discount_type').val();
                        if (selected_type === 'wac_balance_coupon') {
                            $('.wac-balance-coupon-data-tab').show();
                            $('#wac_coupon_balance_history').show(); // Ensure content panel is shown
                        } else {
                            $('.wac-balance-coupon-data-tab').hide();
                            $('#wac_coupon_balance_history').hide(); // Ensure content panel is hidden
                        }
                    }
                    wacToggleBalanceHistoryTab();
                    $('#discount_type').on('change', wacToggleBalanceHistoryTab);
                });
            </script>
            <?php
        }
    }
}