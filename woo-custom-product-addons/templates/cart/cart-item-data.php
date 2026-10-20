<?php


if ( ! defined('ABSPATH')) {
    exit;
}
?>
<ul class="wcpa_cart_meta">
    <?php
    foreach ($item_data as $data) :
        /*
         * The field type comes from the saved form and reaches this template
         * through Cart::get_item_data(). Forms saved before this fix may hold any
         * string here, so it is reduced to class characters and escaped (SEC-005).
         * Real types (text, checkbox-group, ...) keep the same class.
         */
        $class = isset($data['type']) && is_scalar($data['type'])
            ? ' wcpa_cart_item_'.sanitize_html_class((string) $data['type'])
            : '';
        ?>
        <li class="wcpa_cart_meta_item<?php
        echo esc_attr($class); ?>">
            <?php
            if( ('wcpa_empty_label'!==$data['key'])){

                echo '<p class="wcpa_cart_meta_item-label">'.wp_kses_post($data['key']).':</p>';
            }
            ?>
            <div class="wcpa_cart_meta_item-value">
                <?php
                echo wp_kses_post(wpautop($data['display'])); ?>
            </div>
        </li>
    <?php
    endforeach; ?>
</ul>
