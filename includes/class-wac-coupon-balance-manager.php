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
            add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'wac_apply_balance_coupon_discount' ), 10, 5 );
            add_action( 'woocommerce_thankyou', array( $this, 'wac_update_coupon_balance_after_order' ), 10, 1 );
            add_action( 'woocommerce_order_status_cancelled', array( $this, 'wac_restore_coupon_balance_on_cancellation' ), 10, 1 );
            add_action( 'woocommerce_order_status_refunded', array( $this, 'wac_restore_coupon_balance_on_refund' ), 10, 1 );
            add_action( 'woocommerce_checkout_update_order_review', array( $this, 'wac_validate_coupon_balance_on_checkout' ) ); // For AJAX updates
            add_action( 'woocommerce_applied_coupon', array( $this, 'wac_validate_coupon_balance_on_apply' ) );
        }

        /**
         * Apply discount for balance coupons.
         *
         * @param float      $discount
         * @param float      $discounting_amount
         * @param array      $cart_item
         * @param bool       $single
         * @param WC_Coupon  $coupon
         * @return float The calculated discount.
         */
        public function wac_apply_balance_coupon_discount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
            if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                $cart_total      = WC()->cart->get_total( false ); // Get actual cart total without shipping/taxes for comparison.

                // If the cart total is less than the coupon balance, discount the cart total.
                // Otherwise, discount up to the current balance.
                $discount = min( $cart_total, $current_balance );

                // Ensure the discount doesn't exceed the discounting amount for the current item/total.
                // This is important for "per product" or "fixed cart" coupons, but ours is more like a fixed cart.
                // For a "fixed cart" like our balance, this might be simplified to just the min of balance and cart total.
                $discount = min( $discount, $discounting_amount );

                return $discount;
            }
            return $discount;
        }

        /**
         * Update coupon balance and log usage after a successful order.
         *
         * @param int $order_id
         */
        public function wac_update_coupon_balance_after_order( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                return;
            }

            foreach ( $order->get_used_coupons() as $coupon_code ) {
                $coupon = new WC_Coupon( $coupon_code );

                if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                    $coupon_id       = $coupon->get_id();
                    $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                    $discount_amount = (float) $order->get_coupon_discount_amount( $coupon_code ); // Get actual discount applied by THIS coupon

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
                            $coupon->set_usage_limit( $coupon->get_usage_limit() + 1 ); // Increment usage limit by 1 to make it "used" as per Woo's system
                            $coupon->set_date_expires( strtotime( '-1 second', current_time( 'timestamp' ) ) ); // Expire it immediately if 0 balance
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
            // For full refunds, restore full amount. For partial refunds, it's more complex and requires checking refund items.
            // For simplicity here, we'll assume a full refund or revert the specific coupon discount amount.
            $this->wac_process_coupon_balance_restoration( $order );
        }

        /**
         * Helper function to process coupon balance restoration.
         *
         * @param WC_Order $order
         */
        private function wac_process_coupon_balance_restoration( $order ) {
            foreach ( $order->get_used_coupons() as $coupon_code ) {
                $coupon = new WC_Coupon( $coupon_code );

                if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                    $coupon_id       = $coupon->get_id();
                    $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                    $discount_amount = (float) $order->get_coupon_discount_amount( $coupon_code );

                    if ( $discount_amount > 0 ) {
                        $initial_balance = (float) $coupon->get_meta( 'wac_initial_balance' );
                        $new_balance     = $current_balance + $discount_amount;
                        // Ensure it doesn't exceed the initial balance if it was already used multiple times
                        $new_balance     = min( $initial_balance, $new_balance );

                        $coupon->update_meta_data( 'wac_current_balance', $new_balance );

                        // Remove the specific usage from history (if possible or mark as reverted)
                        $history = (array) $coupon->get_meta( 'wac_coupon_usage_history' );
                        foreach ( $history as $key => $record ) {
                            if ( $record['order_id'] === $order->get_id() && (float) $record['amount_used'] === $discount_amount ) {
                                unset( $history[ $key ] );
                                break; // Assuming one usage per order for simplicity here. More complex logic needed for multiple uses of same coupon in one order.
                            }
                        }
                        $coupon->update_meta_data( 'wac_coupon_usage_history', array_values( $history ) ); // Reindex array

                        // If the coupon was expired due to 0 balance, reactivate it
                        if ( $new_balance > 0 && $coupon->get_date_expires() < current_time( 'timestamp' ) ) {
                             // Revert the expiry, set to null or a far future date
                            $coupon->set_date_expires( null );
                            // Decrement usage limit if it was incremented
                            $coupon->set_usage_limit( max( 0, $coupon->get_usage_limit() - 1 ) );
                        }
                        $coupon->save();
                    }
                }
            }
        }


        /**
         * Validate coupon balance on checkout update (AJAX).
         * This prevents users from trying to apply a coupon that no longer has sufficient balance.
         *
         * @param array $posted_data
         */
        public function wac_validate_coupon_balance_on_checkout( $posted_data ) {
            // Check if coupons are applied in the cart
            if ( WC()->cart && WC()->cart->has_discount() ) {
                foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
                    $coupon = new WC_Coupon( $coupon_code );
                    if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                        $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                        $cart_total      = (float) WC()->cart->get_total( false );

                        if ( $current_balance <= 0 ) {
                            WC()->cart->remove_coupon( $coupon_code );
                            wc_add_notice( sprintf( esc_html__( 'El cupón "%s" ha agotado su saldo y ha sido eliminado de tu carrito.', 'wac-advanced-coupons' ), $coupon_code ), 'error' );
                        } elseif ( $cart_total > $current_balance && ! empty( $coupon->get_free_shipping() ) ) {
                            // If the coupon also offers free shipping and the cart total exceeds the balance,
                            // free shipping might still apply if total is covered by other means.
                            // However, for pure balance coupons, we should ensure the discount itself is respected.
                            // If the coupon is ONLY about balance, and it cannot cover the total, we might warn or remove.
                            // For simplicity, we just check if it's exhausted.
                        }
                    }
                }
            }
        }

        /**
         * Validate coupon balance immediately after application.
         *
         * @param string $coupon_code
         */
        public function wac_validate_coupon_balance_on_apply( $coupon_code ) {
            $coupon = new WC_Coupon( $coupon_code );
            if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                if ( $current_balance <= 0 ) {
                    // This scenario should be caught by 'woocommerce_coupon_is_valid' filter before applying,
                    // but as a fallback, if it somehow gets applied with 0 balance, remove it.
                    WC()->cart->remove_coupon( $coupon_code );
                    wc_add_notice( sprintf( esc_html__( 'El cupón "%s" ha agotado su saldo y no puede ser aplicado.', 'wac-advanced-coupons' ), $coupon_code ), 'error' );
                }
            }
        }
    }
}