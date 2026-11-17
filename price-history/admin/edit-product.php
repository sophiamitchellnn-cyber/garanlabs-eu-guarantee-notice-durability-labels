<?php

namespace EGGL\PriceHistory\Admin;

use EGGL\PriceHistory\Includes\Helper;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class Edit_Product {
    public function __construct() {
        add_filter(
            'woocommerce_product_data_tabs',
            [$this, 'product_data_tab'],
            99,
            1
        );
        add_action( 'woocommerce_product_data_panels', [$this, 'product_data_panels'] );
        add_action(
            'woocommerce_product_after_variable_attributes',
            [$this, 'variation_panel'],
            10,
            3
        );
        add_action(
            'woocommerce_before_product_object_save',
            [$this, 'update_product'],
            10,
            2
        );
        add_action(
            'woocommerce_new_product',
            [$this, 'record_initial_product'],
            10,
            2
        );
        add_action(
            'woocommerce_new_product_variation',
            [$this, 'record_initial_product'],
            10,
            2
        );
        add_action(
            'after_delete_post',
            [$this, 'remove_deleted_products_from_eggl_ph_table'],
            99,
            2
        );
        add_action( 'wp_ajax_update_eggl_ph_db_row', [$this, 'update_eggl_ph_db_row'] );
    }

    /**
     * Add custom tab to product tabs.
     *  
     * @since     1.0.0
     */
    public function product_data_tab( $tabs ) {
        $tabs['eggl_ph_price_history'] = [
            'label'    => esc_html__( 'Product Price History', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'target'   => 'eggl_ph_price_history',
            'class'    => [],
            'priority' => 100,
        ];
        return $tabs;
    }

    /**
     * Add options to pph tab/panel.
     *  
     * @since     1.0.0
     */
    public function product_data_panels() {
        $id = get_the_ID();
        ?>

        <style>
            li.eggl_ph_price_history_tab a:before {
                content: "\f238" !important;
            }
        </style>

        <div id="eggl_ph_price_history" class="panel woocommerce_options_panel hidden">

            <div class="eggl-ph-section-head">
                <h4><?php esc_html_e( 'Price history', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></h4>
                <p><?php esc_html_e( 'Every price change of this product is recorded automatically. The recorded prices back the "lowest price in the last 30 days" shown on sale items and the price history chart. Tick Hide to leave an entry out — for example a pricing mistake that was corrected within minutes.', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></p>
            </div>

            <?php 
        do_action( 'eggl_ph_before_editable_table' );
        ?>

            <?php 
        $this->editable_product_price_history( $id );
        ?>

        </div>

<?php 
    }

    /**
     * Build editable table.
     *  
     * @since     1.0.0
     */
    public function editable_product_price_history( $product_id, $no_entries_notice = true ) {
        $is_copy = false;
        $entries = Helper::get_price_history( $product_id );
        if ( !empty( $entries ) ) {
            // Get the date and time formats from the General Settings
            $date_format = get_option( 'date_format' );
            $count = count( $entries );
            do_action( 'eggl_ph_before_table_wrapper', $count );
            $limit = 1000;
            echo '<div class="eggl-ph-table-wrapper">';
            if ( $is_copy ) {
                echo '<p class="notice notice-warning">' . esc_html__( 'This is a translated version of the original product. Price history is inherited from the original and cannot be edited here.', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</p>';
            }
            if ( $count > $limit ) {
                $entries = array_slice( $entries, -$limit, $limit );
                echo '<p><small>' . esc_html__( 'To ensure optimal performance, only a limited number of results are shown in the table.', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</small></p>';
                printf( '<p>%s</p>', sprintf( 
                    /* translators: 1: number of displayed results, 2: total number of results */
                    esc_html__( 'Displaying %1$d out of %2$d results.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    esc_attr( $limit ),
                    esc_attr( $count )
                 ) );
            }
            $entries = array_reverse( $entries ); // newest first
            echo '<table class="eggl-ph-table widefat striped">';
            echo '<thead><tr>';
            echo '<th class="eggl-ph-col-price">' . esc_html__( 'Price', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</th>';
            echo '<th class="eggl-ph-col-date">' . esc_html__( 'Recorded', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</th>';
            if ( !$is_copy ) {
                echo '<th class="eggl-ph-col-hide">' . esc_html__( 'Hide', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ( $entries as $entry ) {
                $date_created = Helper::format_date( $entry['date_created'], get_option( 'time_format' ) );
                $price_html   = wc_price( $entry['price'], ['currency' => $entry['currency']] );
                echo '<tr data-id="' . esc_attr( $entry['id'] ) . '">';
                echo '<td class="eggl-ph-col-price">' . wp_kses_post( $price_html ) . ' <span class="eggl-ph-currency">' . esc_html( $entry['currency'] ) . '</span></td>';
                echo '<td class="eggl-ph-col-date">' . esc_html( $date_created ) . '</td>';
                if ( !$is_copy ) {
                    echo '<td class="eggl-ph-col-hide"><input type="checkbox" name="eggl_ph_hidden" value="1" aria-label="' . esc_attr__( 'Hide this entry', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '" ' . checked( (bool) $entry['hidden'], true, false ) . ' /></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        } else {
            if ( $no_entries_notice ) {
                echo '<div class="eggl-ph-empty">';
                echo esc_html__( 'No pricing data recorded since plugin activation - This message indicates that no pricing data has been saved in the database since the activation of the plugin. This can occur if the plugin was recently installed or if the product price has not changed since the activation of the plugin.', 'garanlabs-eu-guarantee-notice-durability-labels' );
                echo '</div>';
            }
        }
    }

    /**
     * Output editable table to variation panel.
     *  
     * @since     1.0.0
     */
    public function variation_panel( $loop, $variation_data, $variation ) {
        $this->editable_product_price_history( $variation->ID, false );
    }

    /**
     * Create entry on price change.
     *  
     */
    public function update_product( $product, $data_store ) {
        static $processed = [];
        $product_id = $product->get_id();
        // New CRUD objects do not receive an ID until after this hook runs.
        // Their initial price is recorded by record_initial_product().
        if ( !$product_id ) {
            return;
        }
        if ( isset( $processed[$product_id] ) ) {
            return;
        }
        $processed[$product_id] = true;
        if ( $product->is_type( 'variable' ) ) {
            $values = $this->build_variable_parent_values( $product );
        } else {
            // Simple, Variations, etc.
            $values = $this->build_regular_product_values( $product );
        }
        // Nothing to save?
        if ( empty( $values ) ) {
            return;
        }
        $this->record_entry( $product, $values );
    }

    /**
     * Record the initial price after WooCommerce has assigned the product ID.
     *
     * @param int         $product_id Product or variation ID.
     * @param \WC_Product $product    Product object that was just created.
     */
    public function record_initial_product( $product_id, $product ) {
        if ( !$product_id || !is_a( $product, 'WC_Product' ) ) {
            return;
        }
        if ( $product->is_type( 'variable' ) ) {
            $values = $this->build_variable_parent_values( $product );
            if ( !empty( $values ) ) {
                $this->record_entry( $product, $values );
            }
            return;
        }
        $this->record_entry( $product );
        // A variable parent has no standalone price. Once a variation exists,
        // derive and record the parent's initial price from its variations.
        if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent && $parent->is_type( 'variable' ) ) {
                $values = $this->build_variable_parent_values( $parent );
                if ( !empty( $values ) ) {
                    $this->record_entry( $parent, $values );
                }
            }
        }
    }

    /**
     * Create an entry and run the integrations that react to saved records.
     */
    private function record_entry( $product, $values = [] ) {
        $interval = null;
        Helper::create_entry( $product, $interval, $values );
        do_action( 'eggl_ph_after_record_saved', $product, $interval );
    }

    private function build_variable_parent_values( $product ) {
        $parent_id = $product->get_id();
        $latest_entries = Helper::get_variable_latest_entries( $parent_id );
        if ( empty( $latest_entries ) ) {
            return null;
        }
        // Find lowest current variation price
        $min_entry = null;
        $min_price = null;
        foreach ( $latest_entries as $entry ) {
            $p = (float) $entry['price'];
            if ( $min_price === null || $p < $min_price ) {
                $min_price = $p;
                $min_entry = $entry;
            }
        }
        if ( !$min_entry ) {
            return null;
        }
        return [
            'regular_price'     => $min_entry['regular_price'],
            'sale_price'        => $min_entry['sale_price'],
            'price'             => $min_entry['price'],
            'currency'          => $min_entry['currency'],
            'product_type'      => 'variable',
            'parent_product_id' => $parent_id,
        ];
    }

    private function build_regular_product_values( $product ) {
        $changes = $product->get_changes();
        // New values from classic admin (simple only)
        $req_regular = ( isset( $_REQUEST['_regular_price'] ) ? wc_format_decimal( wc_clean( $_REQUEST['_regular_price'] ) ) : null );
        $req_sale = ( isset( $_REQUEST['_sale_price'] ) ? wc_format_decimal( wc_clean( $_REQUEST['_sale_price'] ) ) : null );
        // New values from REST/block editor
        $rest_regular = ( isset( $changes['regular_price'] ) ? wc_format_decimal( $changes['regular_price'] ) : null );
        $rest_sale = ( isset( $changes['sale_price'] ) ? wc_format_decimal( $changes['sale_price'] ) : null );
        // Compute new values
        $new_regular = $req_regular ?? $rest_regular ?? $product->get_regular_price();
        $new_sale = $req_sale ?? $rest_sale ?? $product->get_sale_price();
        // New effective
        $new_effective = ( $new_sale > 0 ? $new_sale : $new_regular );
        // Old effective (BEFORE save)
        $old_effective = $product->get_price();
        if ( !Helper::did_price_change( $old_effective, $new_effective ) ) {
            return null;
        }
        return [
            'regular_price' => $new_regular,
            'sale_price'    => $new_sale,
            'price'         => $new_effective,
        ];
    }

    /**
     * Ajax action for updating editable table fields.
     *  
     * @since     1.0.0
     */
    public function update_eggl_ph_db_row() {
        check_ajax_referer( 'eggl-ph-nonce', 'security' );
        if ( !current_user_can( 'edit_products' ) ) {
            wp_send_json_error( null, 403 );
        }
        $id = ( isset( $_POST['args']['id'] ) ? intval( $_POST['args']['id'] ) : null );
        $data = [];
        if ( isset( $_POST['args']['hidden'] ) ) {
            $data['hidden'] = absint( $_POST['args']['hidden'] ) || 0;
        }
        $response = Helper::update_entry( $id, $data );
        if ( $response !== false && isset( $data['date_created'] ) ) {
            $response = [
                'updated'        => $response,
                'formatted_date' => Helper::format_date( $data['date_created'], 'H:i:s' ),
            ];
        }
        wp_send_json( $response );
        wp_die();
    }

    /**
     * Ajax action for deleting all product entries.
     *  
     * @since     2.1.0
     */
    public function delete_eggl_ph_product_entries() {
        check_ajax_referer( 'eggl-ph-nonce', 'security' );
        if ( !current_user_can( 'edit_products' ) ) {
            wp_send_json_error( null, 403 );
        }
        $id = ( isset( $_POST['args']['id'] ) ? intval( $_POST['args']['id'] ) : null );
        $delete = Helper::delete_product_entries( $id );
        wp_send_json( $delete );
        wp_die();
    }

    /**
     * Ajax action for deleting single product entry.
     *  
     * @since     2.1.0
     */
    public function delete_eggl_ph_entry() {
        check_ajax_referer( 'eggl-ph-nonce', 'security' );
        if ( !current_user_can( 'edit_products' ) ) {
            wp_send_json_error( null, 403 );
        }
        $id = ( isset( $_POST['args']['id'] ) ? intval( $_POST['args']['id'] ) : null );
        $delete = Helper::delete_entry( $id );
        wp_send_json( $delete );
        wp_die();
    }

    /**
     * When deleting the product or variation, delete also entries from pph table.
     *  
     * @since     1.0.0
     */
    public function remove_deleted_products_from_eggl_ph_table( $post_id, $post ) {
        if ( $post->post_type === 'product' || $post->post_type === 'product_variation' ) {
            Helper::delete_product_entries( $post_id );
        }
    }

}
