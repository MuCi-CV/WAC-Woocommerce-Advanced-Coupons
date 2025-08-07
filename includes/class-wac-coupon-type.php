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
            // Register the custom coupon type in the dropdown.
            add_filter( 'woocommerce_coupon_discount_types', array( $this, 'wac_register_balance_coupon_type' ) );
            
            // Add custom fields for the new coupon type.
            add_action( 'woocommerce_coupon_options', array( $this, 'wac_add_coupon_options' ) );
            
            // Add a new tab for balance history.
            add_action( 'woocommerce_coupon_data_tabs', array( $this, 'wac_add_balance_tab' ) );
            
            // Add the content for the balance history tab.
            add_action( 'woocommerce_coupon_data_panels', array( $this, 'wac_add_balance_panel' ) );

            // Save the custom fields.
            add_action( 'woocommerce_coupon_options_save', array( $this, 'wac_save_coupon_options' ) );
        }

        /**
         * Add "Balance Coupon" to coupon types dropdown.
         *
         * @param array $discount_types Existing discount types.
         * @return array Modified discount types.
         */
        public function wac_register_balance_coupon_type( $discount_types ) {
            $discount_types['wac_balance_coupon'] = esc_html__( 'Cupón con Saldo Reutilizable', 'wac-advanced-coupons' );
            return $discount_types;
        }

        /**
         * Adds custom fields for "Re-usable Balance Coupon" to the coupon data panel.
         *
         * @return void
         */
        public function wac_add_coupon_options() {
            // Use CSS to control visibility based on the selected discount type.
            ?>
            <style>
                /* Initially hide the coupon_amount field for our custom type */
                .woocommerce-coupon-data #discount_type option[value="wac_balance_coupon"] ~ option.fixed_cart_option ~ p.coupon_amount_field {
                    display: none;
                }
            </style>
            <?php

            echo '<div class="options_group wac-balance-coupon-options show_if_wac_balance_coupon">';

            woocommerce_wp_text_input(
                array(
                    'id'                => 'wac_initial_balance',
                    'label'             => esc_html__( 'Saldo Inicial de la Giftcard', 'wac-advanced-coupons' ),
                    'placeholder'       => wc_format_localized_price( 0 ),
                    'description'       => esc_html__( 'Define el valor total de la giftcard.', 'wac-advanced-coupons' ),
                    'data_type'         => 'price',
                )
            );

            woocommerce_wp_text_input(
                array(
                    'id'                => 'wac_current_balance',
                    'label'             => esc_html__( 'Saldo Actual de la Giftcard', 'wac-advanced-coupons' ),
                    'placeholder'       => wc_format_localized_price( 0 ),
                    'description'       => esc_html__( 'El saldo restante de esta giftcard. Se actualiza automáticamente.', 'wac-advanced-coupons' ),
                    'data_type'         => 'price',
                    'custom_attributes' => array(
                        'readonly' => 'readonly',
                    ),
                )
            );
            
            echo '</div>';
        }

        /**
         * Save coupon options for 'Balance Coupon' type.
         *
         * @param int $post_id The coupon post ID.
         */
        public function wac_save_coupon_options( $post_id ) {
            $coupon_type = isset( $_POST['discount_type'] ) ? wc_clean( wp_unslash( $_POST['discount_type'] ) ) : '';

            if ( 'wac_balance_coupon' === $coupon_type ) {
                $initial_balance = isset( $_POST['wac_initial_balance'] ) ? wc_format_decimal( wp_unslash( $_POST['wac_initial_balance'] ) ) : 0;
                update_post_meta( $post_id, 'wac_initial_balance', $initial_balance );

                // If no current balance is set (new coupon), set it to initial.
                if ( '' === get_post_meta( $post_id, 'wac_current_balance', true ) ) {
                    update_post_meta( $post_id, 'wac_current_balance', $initial_balance );
                }
                
                // Ensure the coupon amount is also saved, matching the initial balance.
                update_post_meta( $post_id, 'coupon_amount', $initial_balance );

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
                'class'    => array( 'wac-balance-coupon-data-tab', 'hide_if_wac_balance_coupon' ), // Use `hide_if_{type}` to control visibility
            );
            return $tabs;
        }

        /**
         * Display the content for the "Balance History" tab.
         */
        public function wac_add_balance_panel() {
            global $thepostid;
            $coupon_id = $thepostid;
            $history   = get_post_meta( $coupon_id, 'wac_coupon_usage_history', true );
            $history   = is_array( $history ) ? $history : array();
            ?>
            <div id="wac_coupon_balance_history" class="panel woocommerce_options_panel wc-metaboxes_panel" style="display:none;">
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
            <?php
        }
    }
}