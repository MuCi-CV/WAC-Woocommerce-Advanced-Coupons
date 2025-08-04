<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WAC_Integrations' ) ) {

    class WAC_Integrations {

        protected static $_instance = null;

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function __construct() {
            // Add hooks for integration specific functionalities.
            // For FooEvents, you'd hook into their order processing or ticket generation.
            add_action( 'fooevents_ticket_created', array( $this, 'wac_fooevents_process_ticket_with_coupon' ), 10, 3 );

            // For POS, you might need to hook into the POS system's discount application.
            // This is highly dependent on the specific POS plugin (e.g., WooCommerce POS, Hike POS, etc.).
            add_action( 'wac_pos_apply_coupon_check_balance', array( $this, 'wac_pos_check_balance' ), 10, 1 ); // Custom hook for POS system
        }

        /**
         * Integration with FooEvents.
         * Example: Deduct from coupon balance when a FooEvents ticket is generated/marked paid.
         *
         * @param int    $ticket_id
         * @param int    $event_id
         * @param int    $order_id
         */
        public function wac_fooevents_process_ticket_with_coupon( $ticket_id, $event_id, $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }

            // Check if any balance coupon was used in this order.
            foreach ( $order->get_used_coupons() as $coupon_code ) {
                $coupon = new WC_Coupon( $coupon_code );
                if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                    // Assuming FooEvents generates tickets for a certain amount.
                    // This part would need to be very specific to how FooEvents manages
                    // ticket pricing and how it relates to a coupon usage.
                    // For instance, if a "ticket package" coupon is used, and a ticket is issued:
                    // You might need to deduct the cost of one ticket from the coupon balance.
                    // This is a placeholder for the actual logic.
                    // For now, the general coupon balance update after order is already handled in WAC_Coupon_Balance_Manager.
                    // This specific hook could be used if you want to track ticket usage vs. coupon balance more granularly.
                    // For example:
                    // $ticket_price = get_post_meta( $ticket_id, 'fooevents_ticket_price', true ); // Hypothetical
                    // if ( $ticket_price && $ticket_price > 0 ) {
                    //     // Logic to deduct this specific ticket price from coupon balance.
                    //     // You would need to make sure you're not double-deducting if the main coupon logic already did.
                    // }
                    // Or, if the coupon itself represents X number of tickets, and you need to track "tickets remaining" on the coupon.
                    // This requires a more complex meta data on the coupon for "tickets_redeemed" vs "value_redeemed".
                }
            }
        }

        /**
         * Placeholder for POS system integration.
         * A POS system would typically send the coupon code to the backend.
         * This function would then return the balance.
         *
         * @param string $coupon_code
         * @return array Contains balance and status.
         */
        public function wac_pos_check_balance( $coupon_code ) {
            $coupon = new WC_Coupon( $coupon_code );
            $response = array(
                'status'  => 'error',
                'message' => esc_html__( 'Cupón no encontrado o no es un cupón con saldo.', 'wac-advanced-coupons' ),
                'balance' => 0,
            );

            if ( $coupon->get_id() && 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                if ( $current_balance > 0 ) {
                    $response['status']  = 'success';
                    $response['message'] = sprintf( esc_html__( 'Saldo disponible para "%s": %s', 'wac-advanced-coupons' ), esc_html( $coupon_code ), wc_price( $current_balance ) );
                    $response['balance'] = $current_balance;
                } else {
                    $response['status']  = 'exhausted';
                    $response['message'] = sprintf( esc_html__( 'El cupón "%s" ha agotado su saldo.', 'wac-advanced-coupons' ), esc_html( $coupon_code ) );
                }
            }
            return $response;
        }

        /**
         * For QR code scanning:
         * This would typically involve a separate mobile app or a dedicated scanner
         * that can read the QR code. The QR code content should be the coupon code.
         * The app would then make an API call to your WordPress site.
         * You could create a custom REST API endpoint for this.
         *
         * Example: /wp-json/wac/v1/coupon-balance/?code=YOUR_QR_CODE_VALUE
         *
         * add_action( 'rest_api_init', function () {
         * register_rest_route( 'wac/v1', '/coupon-balance', array(
         * 'methods'             => 'GET',
         * 'callback'            => array( $this, 'wac_rest_get_coupon_balance' ),
         * 'permission_callback' => function () {
         * // Only allow if user has permission to view coupons or a specific API key
         * return current_user_can( 'manage_woocommerce' );
         * },
         * 'args'                => array(
         * 'code' => array(
         * 'required'    => true,
         * 'type'        => 'string',
         * 'description' => 'The coupon code.',
         * ),
         * ),
         * ) );
         * } );
         *
         * public function wac_rest_get_coupon_balance( WP_REST_Request $request ) {
         * $coupon_code = $request->get_param( 'code' );
         * $result = $this->wac_pos_check_balance( $coupon_code ); // Reuse the check balance logic
         *
         * if ( 'success' === $result['status'] ) {
         * return new WP_REST_Response( array(
         * 'code'    => $coupon_code,
         * 'balance' => $result['balance'],
         * 'message' => $result['message'],
         * ), 200 );
         * } else {
         * return new WP_REST_Response( array(
         * 'code'    => $coupon_code,
         * 'message' => $result['message'],
         * ), 404 );
         * }
         * }
         */
    }
}