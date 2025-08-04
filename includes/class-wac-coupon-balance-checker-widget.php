<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WAC_Coupon_Balance_Checker_Widget' ) ) {

    class WAC_Coupon_Balance_Checker_Widget extends WP_Widget {

        public function __construct() {
            $widget_ops = array(
                'classname'   => 'wac_coupon_balance_checker_widget',
                'description' => esc_html__( 'Permite a los usuarios consultar el saldo de un cupón con saldo reutilizable.', 'wac-advanced-coupons' ),
            );
            parent::__construct( 'wac_coupon_balance_checker_widget', esc_html__( 'Cupones Avanzados: Consultar Saldo', 'wac-advanced-coupons' ), $widget_ops );
        }

        public function widget( $args, $instance ) {
            echo $args['before_widget'];
            if ( ! empty( $instance['title'] ) ) {
                echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
            }
            include WAC_PLUGIN_DIR . 'templates/wac-coupon-balance-checker.php';
            echo $args['after_widget'];
        }

        public function form( $instance ) {
            $title = ! empty( $instance['title'] ) ? $instance['title'] : esc_html__( 'Consultar Saldo del Cupón', 'wac-advanced-coupons' );
            ?>
            <p>
                <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_attr_e( 'Título:', 'wac-advanced-coupons' ); ?></label>
                <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
            </p>
            <?php
        }

        public function update( $new_instance, $old_instance ) {
            $instance          = array();
            $instance['title'] = ( ! empty( $new_instance['title'] ) ) ? sanitize_text_field( $new_instance['title'] ) : '';
            return $instance;
        }
    }
}