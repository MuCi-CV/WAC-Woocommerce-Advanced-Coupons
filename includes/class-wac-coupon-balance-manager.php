<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WAC_Coupon_Balance_Manager' ) ) {

    class WAC_Coupon_Balance_Manager {

        protected static $_instance = null;

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function __construct() {
            //add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'wac_apply_balance_coupon_discount' ), 10, 5 );
            add_filter( 'woocommerce_cart_coupon_discount_amount', array( $this, 'wac_cart_coupon_discount_amount' ), 10, 2 );
            add_action( 'woocommerce_order_status_completed', array( $this, 'wac_update_coupon_balance_after_order' ), 10, 1 ); // Use 'completed' instead of 'thankyou' for more reliable processing
            add_action( 'woocommerce_order_status_cancelled', array( $this, 'wac_restore_coupon_balance_on_cancellation' ), 10, 1 );
            add_action( 'woocommerce_order_status_refunded', array( $this, 'wac_restore_coupon_balance_on_refund' ), 10, 1 );
            add_action( 'woocommerce_checkout_update_order_review', array( $this, 'wac_validate_coupon_balance_on_checkout' ) );
            add_action( 'woocommerce_applied_coupon', array( $this, 'wac_validate_coupon_balance_on_apply' ) );
            add_filter( 'woocommerce_coupon_is_valid_for_cart', array( $this, 'wac_validate_balance_on_add_to_cart' ), 10, 2 );
        }

        /**
         * Aplica el descuento para cupones con saldo.
         *
         * @param float     $discount          El valor del descuento inicial.
         * @param float     $discounting_amount El monto total del carrito o del ítem que se está descontando.
         * @param array     $cart_item         El ítem del carrito (si es por producto).
         * @param bool      $single            Indica si el descuento es para un solo ítem.
         * @param WC_Coupon $coupon            El objeto WC_Coupon.
         * @return float El descuento calculado.
         */
        public function wac_apply_balance_coupon_discount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
            if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                // Obtenemos el saldo actual del cupón.
                $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                
                // Obtenemos el subtotal del carrito.
                $cart_subtotal = (float) WC()->cart->get_cart_contents_total();

                // El descuento a aplicar es el menor entre el subtotal del carrito y el saldo del cupón.
                $discount_to_apply = min( $cart_subtotal, $current_balance );

                // Para cupones de carrito fijo, es importante devolver el monto total del descuento.
                // El filtro se ejecuta por cada artículo, por lo que usamos una variable estática
                // para asegurarnos de que el descuento se aplique solo una vez y en su totalidad.
                static $wac_total_discount_applied = 0;
                
                if ( $wac_total_discount_applied === 0 ) {
                    $wac_total_discount_applied = $discount_to_apply;
                }

                // Devolvemos la parte del descuento que corresponde al artículo o al carrito.
                // La lógica del core de WooCommerce se encarga de prorratear el descuento
                // si es un cupón de "carrito fijo" y hay múltiples artículos.
                // Solo necesitamos devolver el descuento total en el hook de "woocommerce_coupon_get_discount_amount".
                
                // Devuelve el descuento total si el cupón se aplica al total del carrito.
                if ( ! $single ) {
                    return $discount_to_apply;
                }
                
                // Si el cupón se aplica a un solo ítem, prorratea el descuento.
                if ( $cart_subtotal > 0 ) {
                    return $discount_to_apply * ( $discounting_amount / $cart_subtotal );
                }
            }

            return $discount;
        }

        /**
         * Modifica la cantidad del descuento del cupón en el carrito.
         *
         * @param float     $discount
         * @param WC_Coupon $coupon
         * @return float
         */
        public function wac_cart_coupon_discount_amount( $discount, $coupon ) {
            if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                // Obtener el saldo actual del cupón.
                $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                
                // Obtener el subtotal del carrito.
                $cart_subtotal = (float) WC()->cart->get_cart_contents_total();

                // El descuento a aplicar es el menor entre el subtotal del carrito y el saldo del cupón.
                $discount = min( $cart_subtotal, $current_balance );
            }

            return $discount;
        }

        /**
         * Update coupon balance and log usage after a successful order.
         * We use 'woocommerce_order_status_completed' hook for a more stable state.
         *
         * @param int $order_id
         */
        public function wac_update_coupon_balance_after_order( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                return;
            }

            foreach ( $order->get_coupon_codes() as $coupon_code ) {
                $coupon = new WC_Coupon( $coupon_code );

                if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                    $coupon_id       = $coupon->get_id();
                    $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                    
                    // Get the discount amount for this specific coupon from the order
                    $discount_amount = 0;
                    foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
                        if ( $coupon_item->get_code() === $coupon_code ) {
                            $discount_amount = $coupon_item->get_discount();
                            break;
                        }
                    }

                    if ( $discount_amount > 0 ) {
                        $new_balance = $current_balance - $discount_amount;
                        $new_balance = max( 0, $new_balance ); // Ensure balance doesn't go negative

                        $coupon->update_meta_data( 'wac_current_balance', $new_balance );

                        // Log usage history
                        $history = (array) $coupon->get_meta( 'wac_coupon_usage_history' );
                        $history[] = array(
                            'order_id'          => $order_id,
                            'amount_used'       => $discount_amount,
                            'remaining_balance' => $new_balance,
                            'timestamp'         => current_time( 'timestamp' ),
                        );
                        $coupon->update_meta_data( 'wac_coupon_usage_history', $history );

                        // Set coupon status to used if balance is zero or less
                        if ( $new_balance <= 0 ) {
                            $coupon->set_date_expires( strtotime( '-1 second', current_time( 'timestamp' ) ) );
                        }

                        $coupon->save();
                    }
                }
            }
        }

        /**
         * Restore coupon balance on order cancellation.
         *
         * @param int $order_id
         */
        public function wac_restore_coupon_balance_on_cancellation( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            $this->wac_process_coupon_balance_restoration( $order );
        }

        /**
         * Restore coupon balance on order refund.
         *
         * @param int $order_id
         */
        public function wac_restore_coupon_balance_on_refund( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            $this->wac_process_coupon_balance_restoration( $order );
        }

        /**
         * Helper function to process coupon balance restoration.
         *
         * @param WC_Order $order
         */
        private function wac_process_coupon_balance_restoration( $order ) {
            // Ensure we have a valid WC_Order object, not a sub-class from the admin panel.
            // This is a safety check, but the main fix is in the loop.
            if ( ! is_a( $order, 'WC_Order' ) ) {
                 // Try to get a clean WC_Order object if needed, although the hook should provide one.
                 // This part is more for debugging or extremely specific scenarios.
                 $order = wc_get_order( $order->get_id() );
                 if ( ! $order ) {
                     return;
                 }
            }
            
            foreach ( $order->get_coupon_codes() as $coupon_code ) {
                $coupon = new WC_Coupon( $coupon_code );

                if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                    $coupon_id       = $coupon->get_id();
                    $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                    
                    // The fix: Get discount amount from the order items, which is more reliable.
                    $discount_amount = 0;
                    foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
                        if ( $coupon_item->get_code() === $coupon_code ) {
                            $discount_amount = $coupon_item->get_discount();
                            break;
                        }
                    }

                    if ( $discount_amount > 0 ) {
                        $initial_balance = (float) $coupon->get_meta( 'wac_initial_balance' );
                        $new_balance     = $current_balance + $discount_amount;
                        $new_balance     = min( $initial_balance, $new_balance );

                        $coupon->update_meta_data( 'wac_current_balance', $new_balance );

                        // Remove the specific usage from history
                        $history = (array) $coupon->get_meta( 'wac_coupon_usage_history' );
                        foreach ( $history as $key => $record ) {
                            if ( $record['order_id'] === $order->get_id() && (float) $record['amount_used'] === $discount_amount ) {
                                unset( $history[ $key ] );
                                break;
                            }
                        }
                        $coupon->update_meta_data( 'wac_coupon_usage_history', array_values( $history ) );

                        // If the coupon was expired due to 0 balance, reactivate it
                        if ( $new_balance > 0 && $coupon->get_date_expires() ) {
                            // Clear the expiry date.
                            $coupon->set_date_expires( null );
                        }
                        
                        $coupon->save();
                    }
                }
            }
        }

        /**
         * Validate coupon balance on checkout update (AJAX).
         *
         * @param array $posted_data
         */
        public function wac_validate_coupon_balance_on_checkout( $posted_data ) {
            if ( WC()->cart && WC()->cart->has_discount() ) {
                foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
                    $coupon = new WC_Coupon( $coupon_code );
                    if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                        $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                        
                        // Check if the cart total exceeds the balance.
                        // We do not remove the coupon, just display an error or adjust the discount.
                        // This hook runs on cart updates, so we should check if the coupon is still valid.
                        if ( $current_balance <= 0 ) {
                            WC()->cart->remove_coupon( $coupon_code );
                            wc_add_notice( sprintf( esc_html__( 'El cupón "%s" ha agotado su saldo y ha sido eliminado de tu carrito.', 'wac-advanced-coupons' ), $coupon_code ), 'error' );
                        }
                    }
                }
            }
        }
        
        /**
         * A new filter to check coupon validity on application
         *
         * @param bool $is_valid
         * @param WC_Coupon $coupon
         * @return bool
         */
        public function wac_validate_balance_on_add_to_cart( $is_valid, $coupon ) {
            if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                if ( $current_balance <= 0 ) {
                    $is_valid = false;
                    wc_add_notice( sprintf( esc_html__( 'El cupón "%s" ha agotado su saldo y no puede ser aplicado.', 'wac-advanced-coupons' ), $coupon->get_code() ), 'error' );
                }
            }
            return $is_valid;
        }

        /**
         * Validate coupon balance immediately after application.
         *
         * @param string $coupon_code
         */
        public function wac_validate_coupon_balance_on_apply( $coupon_code ) {
            // This hook is called after the coupon has been successfully applied,
            // so we don't need to do much here if `woocommerce_coupon_is_valid_for_cart` filter is used.
            // We can leave this as a fallback.
        }
    }
}