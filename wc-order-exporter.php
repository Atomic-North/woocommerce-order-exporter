<?php

/**
 * Plugin Name: WooCommerce Order Exporter
 * Description: A plugin to export selected WooCommerce order data, allow selecting custom order data, and set a flag on an order that has already been exported.
 * Version: 0.1
 * Author: Striding Co
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    // Hook into WooCommerce
    add_action('plugins_loaded', 'wc_order_exporter_init');
}

function wc_order_exporter_init()
{
    // Add submenu item under WooCommerce menu
    add_action('admin_menu', 'wc_order_exporter_menu');

    // Register plugin settings
    add_action('admin_init', 'wc_order_exporter_register_settings');
}

function wc_order_exporter_menu()
{
    add_submenu_page(
        'woocommerce',
        'Order Exporter',
        'Order Exporter',
        'manage_woocommerce',
        'wc-order-exporter',
        'wc_order_exporter_settings_page'
    );
}

function wc_order_exporter_register_settings()
{
    register_setting('wc_order_exporter_settings', 'wc_order_exporter_custom_fields');
    register_setting('wc_order_exporter_settings', 'wc_order_exporter_exported_flag');
}

function wc_order_exporter_settings_page()
{
?>
    <div class="wrap">
        <h1>WooCommerce Order Exporter</h1>
        <form method="post" action="options.php">
            <?php settings_fields('wc_order_exporter_settings'); ?>
            <?php do_settings_sections('wc_order_exporter_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Custom Fields</th>
                    <td>
                        <input type="text" name="wc_order_exporter_custom_fields" value="<?php echo esc_attr(get_option('wc_order_exporter_custom_fields')); ?>" />
                        <p class="description">Enter the custom fields to be exported, separated by commas.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Exported Flag</th>
                    <td>
                        <input type="text" name="wc_order_exporter_exported_flag" value="<?php echo esc_attr(get_option('wc_order_exporter_exported_flag')); ?>" />
                        <p class="description">Enter the name of the flag to be used for marking orders as exported.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<?php
}
// Add Export Orders button to the Orders page
add_action('admin_footer-edit.php', 'wc_order_exporter_add_export_button');
function wc_order_exporter_add_export_button()
{
    global $post_type;

    if ('shop_order' == $post_type) {
        echo '<script>
        jQuery(document).ready(function() {
            jQuery(\'<a href="admin.php?page=wc-order-exporter" class="page-title-action">Export Orders</a>\').insertAfter(".wp-heading-inline");
        });
        </script>';
    }
}

// Add a custom column to the Orders page to display the exported flag status
add_filter('manage_edit-shop_order_columns', 'wc_order_exporter_add_exported_column', 20);
function wc_order_exporter_add_exported_column($columns)
{
    $new_columns = array();

    foreach ($columns as $column_name => $column_info) {
        $new_columns[$column_name] = $column_info;

        if ('order_total' === $column_name) {
            $new_columns['exported'] = __('Exported', 'wc-order-exporter');
        }
    }

    return $new_columns;
}

// Display the exported flag status in the custom column
add_action('manage_shop_order_posts_custom_column', 'wc_order_exporter_display_exported_column', 20, 2);
function wc_order_exporter_display_exported_column($column, $post_id)
{
    if ('exported' === $column) {
        $exported_flag = get_option('wc_order_exporter_exported_flag', '');
        $is_exported = get_post_meta($post_id, $exported_flag, true);

        if ($is_exported) {
            echo '<span class="dashicons dashicons-yes"></span>';
        } else {
            echo '<span class="dashicons dashicons-no"></span>';
        }
    }
}

// Enqueue admin scripts and styles
add_action('admin_enqueue_scripts', 'wc_order_exporter_enqueue_scripts');
function wc_order_exporter_enqueue_scripts($hook)
{
    if ('woocommerce_page_wc-order-exporter' == $hook) {
        wp_enqueue_style('wc-order-exporter-css', plugin_dir_url(__FILE__) . 'css/wc-order-exporter.css');
        wp_enqueue_script('wc-order-exporter-js', plugin_dir_url(__FILE__) . 'js/wc-order-exporter.js', array('jquery'));
    }
}

// AJAX callback to export orders and set the exported flag
add_action('wp_ajax_wc_order_exporter_export_orders', 'wc_order_exporter_export_orders_callback');
function wc_order_exporter_export_orders_callback()
{
    global $wpdb;

    // Check nonce for security
    check_ajax_referer('wc_order_exporter_nonce', 'security');

    // Get custom fields and exported flag from settings
    $custom_fields = get_option('wc_order_exporter_custom_fields', '');
    $custom_fields = array_map('trim', explode(',', $custom_fields));
    $exported_flag = get_option('wc_order_exporter_exported_flag', '');

    // Get orders to export
    $args = array(
        'post_type' => 'shop_order',
        'post_status' => 'wc-completed',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => $exported_flag,
                'compare' => 'NOT EXISTS'

            ),
        ),
    );
    $orders = get_posts($args);

    // Prepare order data for export
    $data = array();
    foreach ($orders as $order) {
        $order_data = new WC_Order($order->ID);
        $row = array(
            'order_id' => $order->ID,
            'order_date' => $order_data->get_date_created()->date('Y-m-d H:i:s'),
            'billing_email' => $order_data->get_billing_email(),
            'order_total' => $order_data->get_total(),
        );

        foreach ($custom_fields as $custom_field) {
            $row[$custom_field] = get_post_meta($order->ID, $custom_field, true);
        }

        $data[] = $row;

        // Set exported flag
        update_post_meta($order->ID, $exported_flag, true);
    }

    // Export data as CSV
    $filename = 'wc_order_export_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '";');

    $fh = @fopen('php://output', 'w');
    fputcsv($fh, array_keys(reset($data)));

    foreach ($data as $row) {
        fputcsv($fh, $row);
    }

    fclose($fh);
    exit;
}

// AJAX callback to unset the exported flag on orders
add_action('wp_ajax_wc_order_exporter_unset_exported_flag', 'wc_order_exporter_unset_exported_flag_callback');
function wc_order_exporter_unset_exported_flag_callback()
{
    global $wpdb;

    // Check nonce for security
    check_ajax_referer('wc_order_exporter_nonce', 'security');

    // Get exported flag from settings
    $exported_flag = get_option('wc_order_exporter_exported_flag', '');

    // Get orders with exported flag
    $args = array(
        'post_type' => 'shop_order',
        'post_status' => 'wc-completed',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => $exported_flag,
                'compare' => 'EXISTS'
            ),
        ),
    );
    $orders = get_posts($args);

    // Unset exported flag on orders
    foreach ($orders as $order) {
        delete_post_meta($order->ID, $exported_flag);
    }

    wp_send_json_success();
}
