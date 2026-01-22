<?php
/**
 * Drop-ship notifications on Processing (APF-only details)
 *
 * Sends HTML email to assigned sales reps when order status changes to Processing.
 * Groups items by warehouse → sales rep (via term meta).
 * Now includes billing phone in shipping address section.
 *
 * @package Gunsafes_Core
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('GScore_Dropship_Notifier')) :

class GScore_Dropship_Notifier {
    
    public function __construct() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        add_action('woocommerce_order_status_processing', [$this, 'send_dropship_notification'], 10, 1);
    }

    // --- APF Helper: Convert complex APF values to text ---
    private function apf_val_to_text($v): string {
        if (is_array($v)) {
            foreach (['label', 'text', 'value', 'name'] as $k) {
                if (isset($v[$k]) && $v[$k] !== '') return (string)$v[$k];
            }
            return implode(' ', array_map([$this, 'apf_val_to_text'], $v));
        }
        return (string)$v;
    }

    // --- Extract APF-only details from order item ---
    private function get_apf_details_text($item): string {
        if (!($item instanceof WC_Order_Item_Product)) return '';

        $pairs = [];
        $label_allowlist = [];

        // 1. Consolidated payload keys (WAPF, etc.)
        $payload_keys = ['wapf', '_wapf', 'wapf_fields', 'wapf_options'];
        $payloads = [];
        foreach ($payload_keys as $pk) {
            $val = $item->get_meta($pk, true);
            if (!empty($val)) $payloads[] = $val;
        }

        foreach ($payloads as $payload) {
            if (!is_array($payload)) continue;
            foreach ($payload as $field) {
                if (!is_array($field)) continue;

                $label = '';
                foreach (['label', 'name', 'title', 'key'] as $k) {
                    if (!empty($field[$k])) { $label = (string)$field[$k]; break; }
                }

                $value = '';
                if (isset($field['value']) && $field['value'] !== '') {
                    $value = is_array($field['value'])
                        ? implode(', ', array_map([$this, 'apf_val_to_text'], $field['value']))
                        : (string)$field['value'];
                } elseif (!empty($field['values']) && is_array($field['values'])) {
                    $value = implode(', ', array_map([$this, 'apf_val_to_text'], $field['values']));
                } elseif (!empty($field['selected']) && is_array($field['selected'])) {
                    $value = implode(', ', array_map([$this, 'apf_val_to_text'], $field['selected']));
                }

                $label = trim(wp_strip_all_tags($label));
                $value = trim(wp_strip_all_tags($value));

                if ($label !== '') $label_allowlist[strtolower($label)] = true;
                if ($label !== '' && $value !== '') {
                    $pairs[] = $label . ': ' . $value;
                }
            }
        }

        // 2. Raw WAPF meta fields
        $formatted_meta = $item->get_formatted_meta_data('');
        foreach ($formatted_meta as $fm) {
            $raw_key = strtolower((string)$fm->key);
            if (strpos($raw_key, 'wapf') !== false) {
                $dk = trim(wp_strip_all_tags((string)$fm->display_key));
                $dv = trim(wp_strip_all_tags((string)$fm->display_value));
                if ($dk !== '' && $dv !== '') {
                    $pairs[] = $dk . ': ' . $dv;
                    $label_allowlist[strtolower($dk)] = true;
                }
            }
        }

        // 3. Fallback: non-wapf human labels
        $have_payload = !empty($label_allowlist);
        $blacklist = [
            '_product_id','_variation_id','_line_total','_line_tax','_line_subtotal',
            '_line_subtotal_tax','_line_tax_data','_alg_wc_cog_item_cost','_reduced_stock',
            '_qty','_tax_class','_tax_status','_downloadable','_virtual','_backorders',
            '_manage_stock','_stock','_sku','Method','warehouse','Warehouse'
        ];

        foreach ($formatted_meta as $fm) {
            $raw_key = (string)$fm->key;
            $dk = trim(wp_strip_all_tags((string)$fm->display_key));
            $dv = trim(wp_strip_all_tags((string)$fm->display_value));
            if ($dk === '' || $dv === '') continue;
            $dk_l = strtolower($dk);
            if (in_array($raw_key, $blacklist, true)) continue;
            if ($dk[0] === '_' || $raw_key[0] === '_') continue;
            if (is_numeric($dk)) continue;

            if ($have_payload) {
                if (isset($label_allowlist[$dk_l])) {
                    $pairs[] = $dk . ': ' . $dv;
                }
            } else {
                $pairs[] = $dk . ': ' . $dv;
            }
        }

        $pairs = array_values(array_unique($pairs));
        return implode(', ', array_map('wp_strip_all_tags', $pairs));
    }

    // --- Main email sender ---
    public function send_dropship_notification($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $order_number = $order->get_order_number();
        $order_date = $order->get_date_created() ? $order->get_date_created()->date_i18n('n/j/Y g:i:s A') : '';
        $payment_status = 'Payment information received.';

        $shipping_methods = $order->get_shipping_methods();
        $shipping_method_names = array_map(fn($sm) => trim($sm->get_name()), $shipping_methods);
        $shipping_method_text = !empty($shipping_method_names) ? implode(', ', $shipping_method_names) : '—';

        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');
        $customer_email = $billing['email'] ?? '';

        // Get billing phone — always available
        $billing_phone = $billing['phone'] ?? '';
        $shipping_phone = $shipping['phone'] ?? '';
        if ( $shipping_phone === '' ) {
            $shipping_phone = (string) $order->get_meta( '_shipping_phone', true );
        }

        $items_by_rep = [];

        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            $product = $item->get_product();
            if (!$product) continue;

            $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $warehouse_terms = wp_get_post_terms($product_id, 'warehouse');
            if (empty($warehouse_terms) || is_wp_error($warehouse_terms)) continue;

            $sku = $product->get_sku();
            $mfr_part = get_post_meta($product_id, 'manufacturer_part_number', true);
            $apf_details = $this->get_apf_details_text($item);
            $unit_cost = (float) wc_get_order_item_meta($item_id, '_alg_wc_cog_item_cost', true);
            $qty = (int) $item->get_quantity();
            $item_total_cost = $unit_cost * $qty;

            foreach ($warehouse_terms as $term) {
                $rep_user_id = get_term_meta($term->term_id, 'assigned_sales_rep', true);
                if (!$rep_user_id) continue;
                $user = get_userdata($rep_user_id);
                if (!$user || empty($user->user_email)) continue;

                $items_by_rep[$user->user_email][] = [
                    'name' => $product->get_name(),
                    'sku' => $sku,
                    'mfr_part' => $mfr_part,
                    'qty' => $qty,
                    'unit_cost' => $unit_cost,
                    'item_total' => $item_total_cost,
                    'apf_details' => $apf_details,
                ];
            }
        }

        if (empty($items_by_rep)) return;

        foreach ($items_by_rep as $rep_email => $products) {
            $rep_subtotal = array_sum(array_column($products, 'item_total'));

            ob_start();
            ?>
            <div style="font-family: Arial, Helvetica, sans-serif; line-height:1.4; font-size:14px;">
                <h2 style="margin:0 0 12px 0;">New Drop Ship Order</h2>

                <div style="margin-bottom:14px;">
                    <div><strong>Order #:</strong> <?php echo esc_html($order_number); ?></div>
                    <div><strong>Order Date:</strong> <?php echo esc_html($order_date); ?></div>
                    <div><strong>Payment Status:</strong> <?php echo esc_html($payment_status); ?></div>
                </div>

                <hr style="border:none;border-top:1px solid #ddd;margin:16px 0;">
                <div style="margin-bottom:10px;"><strong>Billing Address (Customer)</strong></div>
                <div>Name: <?php echo esc_html(trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''))); ?></div>
                <?php if (!empty($billing['company'])): ?>
                    <div>Company: <?php echo esc_html($billing['company']); ?></div>
                <?php endif; ?>
                <div>Address Line 1: <?php echo esc_html($billing['address_1'] ?? ''); ?></div>
                <?php if (!empty($billing['address_2'])): ?>
                    <div>Address Line 2: <?php echo esc_html($billing['address_2']); ?></div>
                <?php endif; ?>
                <div>City/State/Postal Code: <?php echo esc_html(($billing['city'] ?? '') . ', ' . ($billing['state'] ?? '') . ' ' . ($billing['postcode'] ?? '')); ?></div>
                <?php if (!empty($billing_phone)): ?>
                    <div>Phone Number: <?php echo esc_html($billing_phone); ?></div>
                <?php endif; ?>

                <hr style="border:none;border-top:1px solid #ddd;margin:16px 0;">
                <div style="margin-bottom:10px;"><strong>Shipping Address (Customer)</strong></div>
                <div>Name: <?php echo esc_html(trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''))); ?></div>
                <?php if (!empty($shipping['company'])): ?>
                    <div>Company: <?php echo esc_html($shipping['company']); ?></div>
                <?php endif; ?>
                <div>Address Line 1: <?php echo esc_html($shipping['address_1'] ?? ''); ?></div>
                <?php if (!empty($shipping['address_2'])): ?>
                    <div>Address Line 2: <?php echo esc_html($shipping['address_2']); ?></div>
                <?php endif; ?>
                <div>City/State/Postal Code: <?php echo esc_html(($shipping['city'] ?? '') . ', ' . ($shipping['state'] ?? '') . ' ' . ($shipping['postcode'] ?? '')); ?></div>
                
                <!-- Always show phone: prefer shipping phone, fallback to billing -->
                <div>Phone Number: <?php echo esc_html(!empty($shipping_phone) ? $shipping_phone : $billing_phone); ?> <?php echo empty($shipping_phone) && !empty($billing_phone) ? ' (Billing Phone)' : ''; ?></div>
                
                <?php if (!empty($customer_email)): ?>
                    <div style="margin-top:10px;">Customer Email: <?php echo esc_html($customer_email); ?></div>
                <?php endif; ?>
                <?php if ($order->get_customer_note()): ?>
                    <div style="margin-top:10px;"><strong>Order Notes:</strong><br><?php echo nl2br(esc_html($order->get_customer_note())); ?></div>
                <?php endif; ?>

                <hr style="border:none;border-top:1px solid #ddd;margin:16px 0;">
                <div style="margin-bottom:10px;"><strong>Items Needed:</strong></div>
                <?php foreach ($products as $p): ?>
                    <div style="margin-bottom:14px; padding-bottom:10px; border-bottom:1px dashed #e3e3e3;">
                        <div><strong>Item</strong>: <?php echo esc_html($p['name']); ?></div>
                        <div><strong>SKU</strong>: <?php echo esc_html($p['sku']); ?></div>
                        <div><strong>Mfg Part Nr</strong>: <?php echo esc_html($p['mfr_part']); ?></div>
                        <div><strong>Details</strong>: <?php echo esc_html($p['apf_details'] ?: '—'); ?></div>
                        <div><strong>Quantity</strong>: <?php echo (int)$p['qty']; ?></div>
                        <div><strong>Unit Cost</strong>: $<?php echo number_format((float)$p['unit_cost'], 2); ?></div>
                        <div><strong>Item Total</strong>: $<?php echo number_format((float)$p['item_total'], 2); ?></div>
                    </div>
                <?php endforeach; ?>
                <div style="margin-top:6px;"><strong>Sub-Total:</strong> $<?php echo number_format($rep_subtotal, 2); ?></div>
                <div style="margin-top:10px;"><strong>Shipping Method:</strong> <?php echo esc_html($shipping_method_text); ?></div>
            </div>
            <?php
            $message_html = ob_get_clean();

            wp_mail(
                $rep_email,
                'New Drop Ship Order Notification - Order #' . $order_number,
                $message_html,
                ['Content-Type: text/html; charset=UTF-8']
            );
        }
    }

}

// Initialize immediately when file is loaded (plugins_loaded already fired in gunsafes-core.php)
if (class_exists('WooCommerce')) {
    new GScore_Dropship_Notifier();
} else {
}

endif;
