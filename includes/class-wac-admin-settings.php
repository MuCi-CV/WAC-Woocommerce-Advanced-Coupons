<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WAC_Admin_Settings' ) ) {

    class WAC_Admin_Settings {

        protected static $_instance = null;

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function __construct() {
            add_action( 'admin_menu', array( $this, 'wac_add_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'wac_register_settings' ) );

            // Add custom columns to coupons list table
            add_filter( 'manage_edit-shop_coupon_columns', array( $this, 'wac_add_coupon_list_columns' ) );
            add_action( 'manage_shop_coupon_posts_custom_column', array( $this, 'wac_render_coupon_list_column_data' ), 10, 2 );
            add_filter( 'manage_edit-shop_coupon_sortable_columns', array( $this, 'wac_make_coupon_columns_sortable' ) );
            add_action( 'pre_get_posts', array( $this, 'wac_sort_coupon_list_by_balance' ) );
        }

        /**
         * Add admin menu page.
         */
        public function wac_add_admin_menu() {
            add_submenu_page(
                'woocommerce',
                esc_html__( 'Cupones Avanzados', 'wac-advanced-coupons' ),
                esc_html__( 'Cupones Avanzados', 'wac-advanced-coupons' ),
                'manage_woocommerce', // Capability to access
                'wac-advanced-coupons',
                array( $this, 'wac_settings_page_content' )
            );
        }

        /**
         * Register plugin settings.
         */
        public function wac_register_settings() {
            register_setting( 'wac_advanced_coupons_settings_group', 'wac_enable_balance_coupon_type', array(
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default'           => true,
            ) );
            register_setting( 'wac_advanced_coupons_settings_group', 'wac_enable_frontend_balance_display', array(
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default'           => true,
            ) );
            register_setting( 'wac_advanced_coupons_settings_group', 'wac_enable_balance_checker_widget', array(
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default'           => true,
            ) );

            add_settings_section(
                'wac_general_settings_section',
                esc_html__( 'Configuraciones Generales', 'wac-advanced-coupons' ),
                null,
                'wac-advanced-coupons'
            );

            add_settings_field(
                'wac_enable_balance_coupon_type_field',
                esc_html__( 'Activar tipo de cupón con saldo', 'wac-advanced-coupons' ),
                array( $this, 'wac_enable_balance_coupon_type_callback' ),
                'wac-advanced-coupons',
                'wac_general_settings_section'
            );

            add_settings_field(
                'wac_enable_frontend_balance_display_field',
                esc_html__( 'Mostrar saldo en el carrito/Mi Cuenta', 'wac-advanced-coupons' ),
                array( $this, 'wac_enable_frontend_balance_display_callback' ),
                'wac-advanced-coupons',
                'wac_general_settings_section'
            );

            add_settings_field(
                'wac_enable_balance_checker_widget_field',
                esc_html__( 'Activar Widget "Consultar Saldo"', 'wac-advanced-coupons' ),
                array( $this, 'wac_enable_balance_checker_widget_callback' ),
                'wac-advanced-coupons',
                'wac_general_settings_section'
            );
        }

        /**
         * Callback for enable balance coupon type checkbox.
         */
        public function wac_enable_balance_coupon_type_callback() {
            $option = get_option( 'wac_enable_balance_coupon_type', true );
            echo '<input type="checkbox" name="wac_enable_balance_coupon_type" value="1"' . checked( 1, $option, false ) . ' />';
            echo '<p class="description">' . esc_html__( 'Activa o desactiva la opción "Cupón con Saldo Reutilizable" en la creación de cupones.', 'wac-advanced-coupons' ) . '</p>';
        }

        /**
         * Callback for enable frontend balance display checkbox.
         */
        public function wac_enable_frontend_balance_display_callback() {
            $option = get_option( 'wac_enable_frontend_balance_display', true );
            echo '<input type="checkbox" name="wac_enable_frontend_balance_display" value="1"' . checked( 1, $option, false ) . ' />';
            echo '<p class="description">' . esc_html__( 'Controla la visualización del saldo restante del cupón en el carrito y en la sección "Mi Cuenta".', 'wac-advanced-coupons' ) . '</p>';
        }

        /**
         * Callback for enable balance checker widget checkbox.
         */
        public function wac_enable_balance_checker_widget_callback() {
            $option = get_option( 'wac_enable_balance_checker_widget', true );
            echo '<input type="checkbox" name="wac_enable_balance_checker_widget" value="1"' . checked( 1, $option, false ) . ' />';
            echo '<p class="description">' . esc_html__( 'Permite a los usuarios consultar el saldo de un cupón mediante un widget o shortcode.', 'wac-advanced-coupons' ) . '</p>';
        }

        /**
         * Settings page content.
         */
        public function wac_settings_page_content() {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Configuraciones de Cupones Avanzados', 'wac-advanced-coupons' ); ?></h1>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'wac_advanced_coupons_settings_group' );
                    do_settings_sections( 'wac-advanced-coupons' );
                    submit_button();
                    ?>
                </form>

                <h2><?php esc_html_e( 'Panel de Administración de Saldos de Cupones', 'wac-advanced-coupons' ); ?></h2>
                <p><?php esc_html_e( 'Aquí puedes ver un resumen y filtrar los cupones con saldo reutilizable.', 'wac-advanced-coupons' ); ?></p>

                <?php $this->wac_display_coupon_balance_dashboard(); ?>

            </div>
            <?php
        }

        /**
         * Displays the coupon balance dashboard.
         */
        private function wac_display_coupon_balance_dashboard() {
            $args = array(
                'post_type'      => 'shop_coupon',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => 'discount_type',
                        'value'   => 'wac_balance_coupon',
                        'compare' => '=',
                    ),
                ),
            );

            // Filters
            $filter_status = isset( $_GET['wac_coupon_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wac_coupon_status'] ) ) : '';
            $filter_search = isset( $_GET['wac_coupon_search'] ) ? sanitize_text_field( wp_unslash( $_GET['wac_coupon_search'] ) ) : '';

            if ( ! empty( $filter_search ) ) {
                $args['s'] = $filter_search;
            }

            $coupons = new WP_Query( $args );
            ?>
            <div class="wac-coupon-balance-dashboard">
                <form method="get" class="wac-coupon-filters">
                    <input type="hidden" name="page" value="wac-advanced-coupons" />
                    <label for="wac_coupon_status"><?php esc_html_e( 'Filtrar por estado:', 'wac-advanced-coupons' ); ?></label>
                    <select name="wac_coupon_status" id="wac_coupon_status">
                        <option value=""><?php esc_html_e( 'Todos', 'wac-advanced-coupons' ); ?></option>
                        <option value="active" <?php selected( 'active', $filter_status ); ?>><?php esc_html_e( 'Activos (con saldo)', 'wac-advanced-coupons' ); ?></option>
                        <option value="exhausted" <?php selected( 'exhausted', $filter_status ); ?>><?php esc_html_e( 'Agotados', 'wac-advanced-coupons' ); ?></option>
                        <option value="expired" <?php selected( 'expired', $filter_status ); ?>><?php esc_html_e( 'Vencidos', 'wac-advanced-coupons' ); ?></option>
                    </select>
                    <label for="wac_coupon_search"><?php esc_html_e( 'Buscar por código:', 'wac-advanced-coupons' ); ?></label>
                    <input type="text" name="wac_coupon_search" id="wac_coupon_search" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php esc_attr_e( 'Buscar código de cupón...', 'wac-advanced-coupons' ); ?>" />
                    <?php submit_button( esc_html__( 'Aplicar Filtros', 'wac-advanced-coupons' ), 'secondary', '', false ); ?>
                </form>

                <?php if ( $coupons->have_posts() ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Código del Cupón', 'wac-advanced-coupons' ); ?></th>
                                <th><?php esc_html_e( 'Saldo Inicial', 'wac-advanced-coupons' ); ?></th>
                                <th><?php esc_html_e( 'Saldo Actual', 'wac-advanced-coupons' ); ?></th>
                                <th><?php esc_html_e( 'Estado', 'wac-advanced-coupons' ); ?></th>
                                <th><?php esc_html_e( 'Expiración', 'wac-advanced-coupons' ); ?></th>
                                <th><?php esc_html_e( 'Acciones', 'wac-advanced-coupons' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            while ( $coupons->have_posts() ) : $coupons->the_post();
                                $coupon          = new WC_Coupon( get_the_ID() );
                                $initial_balance = (float) $coupon->get_meta( 'wac_initial_balance' );
                                $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                                $date_expires    = $coupon->get_date_expires();
                                $coupon_status   = '';

                                if ( $current_balance <= 0 ) {
                                    $coupon_status = esc_html__( 'Agotado', 'wac-advanced-coupons' );
                                    $status_class  = 'wac-status-exhausted';
                                } elseif ( $date_expires && $date_expires->getTimestamp() < current_time( 'timestamp' ) ) {
                                    $coupon_status = esc_html__( 'Vencido', 'wac-advanced-coupons' );
                                    $status_class  = 'wac-status-expired';
                                } else {
                                    $coupon_status = esc_html__( 'Activo', 'wac-advanced-coupons' );
                                    $status_class  = 'wac-status-active';
                                }

                                // Apply filters here
                                $display_row = true;
                                if ( 'active' === $filter_status && ( $current_balance <= 0 || ( $date_expires && $date_expires->getTimestamp() < current_time( 'timestamp' ) ) ) ) {
                                    $display_row = false;
                                } elseif ( 'exhausted' === $filter_status && $current_balance > 0 ) {
                                    $display_row = false;
                                } elseif ( 'expired' === $filter_status && ( ! $date_expires || $date_expires->getTimestamp() >= current_time( 'timestamp' ) ) ) {
                                    $display_row = false;
                                }

                                if ( $display_row ) :
                                ?>
                                <tr>
                                    <td><strong><a href="<?php echo esc_url( get_edit_post_link( $coupon->get_id() ) ); ?>"><?php echo esc_html( $coupon->get_code() ); ?></a></strong></td>
                                    <td><?php echo wc_price( $initial_balance ); ?></td>
                                    <td><?php echo wc_price( $current_balance ); ?></td>
                                    <td><span class="wac-coupon-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $coupon_status ); ?></span></td>
                                    <td>
                                        <?php
                                        if ( $date_expires ) {
                                            echo esc_html( date_i18n( wc_date_format() . ' ' . wc_time_format(), $date_expires->getTimestamp() ) );
                                        } else {
                                            esc_html_e( 'Nunca', 'wac-advanced-coupons' );
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url( get_edit_post_link( $coupon->get_id() ) ); ?>" class="button button-small"><?php esc_html_e( 'Editar', 'wac-advanced-coupons' ); ?></a>
                                    </td>
                                </tr>
                                <?php
                                endif;
                            endwhile;
                            wp_reset_postdata();
                            ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e( 'No se encontraron cupones con saldo reutilizable.', 'wac-advanced-coupons' ); ?></p>
                <?php endif; ?>
            </div>
            <style>
                .wac-coupon-balance-dashboard {
                    margin-top: 20px;
                    background: #fff;
                    padding: 20px;
                    border: 1px solid #c3c4c7;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
                .wac-coupon-filters {
                    display: flex;
                    gap: 15px;
                    align-items: center;
                    margin-bottom: 20px;
                }
                .wac-coupon-status {
                    font-weight: bold;
                }
                .wac-status-active { color: #008000; } /* Green */
                .wac-status-exhausted { color: #dc3232; } /* Red */
                .wac-status-expired { color: #ffba00; } /* Orange */
            </style>
            <?php
        }

        /**
         * Add custom columns to the coupons list table.
         *
         * @param array $columns
         * @return array
         */
        public function wac_add_coupon_list_columns( $columns ) {
            $new_columns = array();
            foreach ( $columns as $key => $value ) {
                $new_columns[ $key ] = $value;
                if ( 'type' === $key ) {
                    $new_columns['wac_current_balance'] = esc_html__( 'Saldo Actual', 'wac-advanced-coupons' );
                }
            }
            return $new_columns;
        }

        /**
         * Render data for custom columns in the coupons list table.
         *
         * @param string $column
         * @param int    $post_id
         */
        public function wac_render_coupon_list_column_data( $column, $post_id ) {
            if ( 'wac_current_balance' === $column ) {
                $coupon = new WC_Coupon( $post_id );
                if ( 'wac_balance_coupon' === $coupon->get_discount_type() ) {
                    $current_balance = (float) $coupon->get_meta( 'wac_current_balance' );
                    echo wc_price( $current_balance );
                } else {
                    echo '-';
                }
            }
        }

        /**
         * Make custom columns sortable.
         *
         * @param array $columns
         * @return array
         */
        public function wac_make_coupon_columns_sortable( $columns ) {
            $columns['wac_current_balance'] = 'wac_current_balance';
            return $columns;
        }

        /**
         * Sort coupons by current balance in admin list.
         *
         * @param WP_Query $query
         */
        public function wac_sort_coupon_list_by_balance( $query ) {
            if ( ! is_admin() || ! $query->is_main_query() || 'shop_coupon' !== $query->get( 'post_type' ) ) {
                return;
            }

            if ( 'wac_current_balance' === $query->get( 'orderby' ) ) {
                $query->set( 'meta_key', 'wac_current_balance' );
                $query->set( 'orderby', 'meta_value_num' );
            }
        }
    }
}