<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$coupon_code = isset( $_POST['wac_coupon_code_check'] ) ? sanitize_text_field( wp_unslash( $_POST['wac_coupon_code_check'] ) ) : '';
$balance     = null;
$message     = '';

if ( ! empty( $coupon_code ) ) {
    $coupon = new WC_Coupon( $coupon_code );

    if ( $coupon->get_id() && 'wac_balance_coupon' === $coupon->get_discount_type() ) {
        $balance = (float) $coupon->get_meta( 'wac_current_balance' );
        if ( $balance > 0 ) {
            $message = sprintf( esc_html__( 'El cupón "%s" tiene un saldo restante de %s.', 'wac-advanced-coupons' ), esc_html( $coupon_code ), wc_price( $balance ) );
            if ( $coupon->get_date_expires() ) {
                $message .= ' ' . sprintf( esc_html__( 'Expira el %s.', 'wac-advanced-coupons' ), esc_html( date_i18n( wc_date_format(), $coupon->get_date_expires()->getTimestamp() ) ) );
            }
        } else {
            $message = sprintf( esc_html__( 'El cupón "%s" ha agotado su saldo o ya no es válido.', 'wac-advanced-coupons' ), esc_html( $coupon_code ) );
        }
    } else {
        $message = esc_html__( 'El cupón no es válido o no es un cupón con saldo reutilizable.', 'wac-advanced-coupons' );
    }
}
?>
<div class="wac-coupon-balance-checker">
    <form method="post" action="">
        <p>
            <label for="wac_coupon_code_check"><?php esc_html_e( 'Introduce el código de tu cupón:', 'wac-advanced-coupons' ); ?></label>
            <input type="text" id="wac_coupon_code_check" name="wac_coupon_code_check" value="<?php echo esc_attr( $coupon_code ); ?>" placeholder="<?php esc_attr_e( 'Código del cupón', 'wac-advanced-coupons' ); ?>" required />
        </p>
        <p>
            <button type="submit" name="wac_check_coupon_balance" value="1"><?php esc_html_e( 'Consultar Saldo', 'wac-advanced-coupons' ); ?></button>
        </p>
    </form>

    <?php if ( ! empty( $message ) ) : ?>
        <div class="wac-balance-result <?php echo ( $balance > 0 ) ? 'success' : 'error'; ?>">
            <p><?php echo wp_kses_post( $message ); ?></p>
        </div>
    <?php endif; ?>
</div>
<style>
    .wac-coupon-balance-checker input[type="text"] {
        width: 100%;
        padding: 8px;
        box-sizing: border-box;
        margin-bottom: 10px;
    }
    .wac-coupon-balance-checker button {
        padding: 10px 15px;
        background-color: #007cba;
        color: #fff;
        border: none;
        cursor: pointer;
    }
    .wac-coupon-balance-checker button:hover {
        background-color: #005a87;
    }
    .wac-balance-result {
        margin-top: 15px;
        padding: 10px;
        border-radius: 4px;
    }
    .wac-balance-result.success {
        background-color: #d4edda;
        border-color: #c3e6cb;
        color: #155724;
    }
    .wac-balance-result.error {
        background-color: #f8d7da;
        border-color: #f5c6cb;
        color: #721c24;
    }
</style>