<?php

namespace Acowebs\WCPA\Free;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * COMPAT-007: the Omnisend abandoned-cart link keeps the add-on answers (Pro: Comp::omnisend_*).
 *
 * Omnisend for WooCommerce (checked with 1.22.9) e-mails a link `?action=rebuildCart&omniCart=…`. Its
 * omnisend_rebuild_cart() empties the cart and adds each line back with product, quantity and variation only,
 * through WC()->cart->add_to_cart(), which skips woocommerce_add_to_cart_validation. Without this class every
 * add-on field of such a line was stored empty, fields shown by conditions were dropped and required fields were
 * never checked (ticket 37183, reproduced on Pro; Free has the same unvalidated path).
 *
 * Owner decision (2026-11-06): use only Omnisend's own filters and pass the field values through them; nothing is
 * stored on the store. The answers are therefore part of the cart event sent to Omnisend and of the e-mail link.
 *
 * | Omnisend filter                    | Here                                                             |
 * | ---------------------------------- | ---------------------------------------------------------------- |
 * | omnisend_cart_line_item            | adds the posted answers of a line with add-on data (`wcpa_omnisend`) |
 * | omnisend_cart_checkout_url_item    | copies them into the link line                                    |
 * | omnisend_recover_cart_item_data    | validates them as a product-form post and builds the line's add-on data, or refuses the line |
 *
 * The link format is the same as Pro's ({v: 1, post: {field name: value}}), so a store that moves between the
 * editions keeps working links for the fields both have.
 */
class Omnisend
{
    /** Key of the answers in Omnisend's cart items and link lines. Not WCPA_CART_ITEM_KEY ('wcpa_data'). */
    const KEY = 'wcpa_omnisend';

    /** Marker of a link line that is refused (refuse_line). */
    const REFUSED = 'wcpa_omnisend_refused';

    /** Marker carrying the add-on data built by the separate run, put on the line by restore_line. */
    const RESTORED = 'wcpa_omnisend_restored';

    /** Cart item data keys that only WCPA sets; never taken from a link. */
    const RESERVED = ['wcpa_cart_rules', self::REFUSED, self::RESTORED];

    /** Request keys that steer an add to cart rather than answer a field; never taken from a link. */
    const CONTROL_POST = ['add-to-cart', 'quantity', 'wcpa_current_cart_key'];

    public function __construct()
    {
        add_filter('omnisend_cart_line_item', array($this, 'cart_line_item'), 10, 2);
        add_filter('omnisend_cart_checkout_url_item', array($this, 'checkout_url_item'), 10, 2);
        add_filter('omnisend_recover_cart_item_data', array($this, 'recover_cart_item_data'), 10, 2);
        add_filter('woocommerce_add_cart_item_data', array($this, 'refuse_line'), 1, 1);
        add_filter('woocommerce_add_cart_item_data', array($this, 'restore_line'), 11, 1);
    }

    /**
     * Omnisend `omnisend_cart_line_item` ($omnisend_cart_item, $woo_cart_item): one line of a cart event.
     *
     * @param array $omnisend_cart_item
     * @param array $woo_cart_item
     * @return array
     */
    public function cart_line_item($omnisend_cart_item, $woo_cart_item)
    {
        if (!is_array($omnisend_cart_item) || !is_array($woo_cart_item)
            || empty($woo_cart_item[WCPA_CART_ITEM_KEY]) || !is_array($woo_cart_item[WCPA_CART_ITEM_KEY])) {
            return $omnisend_cart_item;
        }
        $omnisend_cart_item[self::KEY] = ['v' => 1, 'post' => self::posted_values($woo_cart_item[WCPA_CART_ITEM_KEY])];

        return $omnisend_cart_item;
    }

    /**
     * Omnisend `omnisend_cart_checkout_url_item` ($line_item_to_recover, $cart_item): one line of the recovery link.
     * $cart_item is the Omnisend item built above, not the WooCommerce cart item.
     *
     * @param array $line_item_to_recover
     * @param array $cart_item
     * @return array
     */
    public function checkout_url_item($line_item_to_recover, $cart_item)
    {
        if (is_array($line_item_to_recover) && is_array($cart_item) && isset($cart_item[self::KEY])) {
            $line_item_to_recover[self::KEY] = $cart_item[self::KEY];
        }

        return $line_item_to_recover;
    }

    /**
     * Omnisend `omnisend_recover_cart_item_data` ($cart_item_data, $product): the cart item data passed to
     * add_to_cart() for one link line ($product: product_id, quantity, variation_id, variation and extra keys).
     *
     * A separate, hook-free Process validates the answers as a product-form post and builds the add-on data. Free's
     * registered Process always reads the request again in add_cart_item_data (its stored-data path was removed in
     * 3.0.2), and the request of the link holds no answers, so the result travels in a marker that restore_line puts
     * back after it.
     *
     * | Link line                                    | Result                                             |
     * | -------------------------------------------- | -------------------------------------------------- |
     * | no WCPA key (old link, product without form) | unchanged: added as today                          |
     * | answers that pass validation                 | added with the answers and conditional fields      |
     * | answers that fail (option removed, field now required and empty) | refused: the validation notice and "X was not added to your cart again …" |
     *
     * @param array $cart_item_data
     * @param array $product
     * @return array
     */
    public function recover_cart_item_data($cart_item_data, $product)
    {
        if (!is_array($cart_item_data)) {
            return $cart_item_data;
        }
        $data = isset($cart_item_data[self::KEY]) ? $cart_item_data[self::KEY] : null;
        unset($cart_item_data[self::KEY], $cart_item_data[WCPA_CART_ITEM_KEY]);
        foreach (self::RESERVED as $key) {
            unset($cart_item_data[$key]);
        }
        if ($data === null || !is_array($product) || empty($product['product_id'])) {
            return $cart_item_data;
        }
        $product_id = (int)$product['product_id'];
        $variation_id = !empty($product['variation_id']) ? (int)$product['variation_id'] : 0;
        $quantity = (isset($product['quantity']) && is_numeric($product['quantity'])) ? $product['quantity'] : 1;

        if (!is_array($data) || !isset($data['post']) || !is_array($data['post'])) {
            self::refused_notice($product_id, $variation_id);
            $cart_item_data[self::REFUSED] = true;

            return $cart_item_data;
        }
        $post = array_diff_key($data['post'], array_flip(self::CONTROL_POST));
        if (isset($product['variation']) && is_array($product['variation'])) {
            foreach ($product['variation'] as $attribute => $value) {
                if (is_string($attribute) && strpos($attribute, 'attribute_') === 0 && is_scalar($value)) {
                    $post[$attribute] = (string)$value;
                }
            }
        }

        $process = new Process(false);
        $saved = [$_POST, $_REQUEST, $_FILES];
        $_POST = $_REQUEST = wp_slash($post);
        $_FILES = [];
        try {
            $passed = $process->add_to_cart_validation(true, $product_id, $quantity, $variation_id ?: false);
            $result = $passed ? $process->add_cart_item_data([], $product_id, $variation_id ?: false, $quantity) : false;
        } finally {
            list($_POST, $_REQUEST, $_FILES) = $saved;
        }

        if (!is_array($result)) {
            self::refused_notice($product_id, $variation_id);
            $cart_item_data[self::REFUSED] = true;

            return $cart_item_data;
        }
        $cart_item_data[self::RESTORED] = array_intersect_key($result, array_flip([WCPA_CART_ITEM_KEY, 'wcpa_cart_rules']));

        return $cart_item_data;
    }

    /**
     * woocommerce_add_cart_item_data at priority 1: refuse a marked link line. WC_Cart::add_to_cart() applies the
     * filter inside its try block; an exception with an empty message makes it return false without a notice.
     *
     * @param array $cart_item_data
     * @return array
     * @throws \Exception
     */
    public function refuse_line($cart_item_data)
    {
        if (is_array($cart_item_data) && !empty($cart_item_data[self::REFUSED])) {
            throw new \Exception('');
        }

        return $cart_item_data;
    }

    /**
     * woocommerce_add_cart_item_data at priority 11, after Process::add_cart_item_data (10): put the add-on data of a
     * restored link line in place of what Process read from the link's request (no answers).
     *
     * @param array $cart_item_data
     * @return array
     */
    public function restore_line($cart_item_data)
    {
        if (is_array($cart_item_data) && isset($cart_item_data[self::RESTORED]) && is_array($cart_item_data[self::RESTORED])) {
            $restored = $cart_item_data[self::RESTORED];
            unset($cart_item_data[self::RESTORED]);
            $cart_item_data = array_merge($cart_item_data, $restored);
        }

        return $cart_item_data;
    }

    /**
     * The notice for a link line that was not added: the generic wording of "Order again" (ORD-008), so one
     * translation serves both.
     *
     * @param int $product_id
     * @param int $variation_id
     */
    private static function refused_notice($product_id, $variation_id)
    {
        $product = wc_get_product($variation_id ?: $product_id);
        wc_add_notice(sprintf(
        /* translators: %s: product name */
            __('%s was not added to your cart again: its earlier add-on options cannot be used with the current product options. Please choose them again on the product page.',
                'woo-custom-product-addons'),
            $product ? $product->get_name() : ''), 'error');
    }

    /**
     * The request values the product form would post for a line's stored add-on data (the reverse of
     * ReadForm::_read_form). Free field names are plain strings and Free reads only $_POST[name].
     *
     * | Field type                 | Stored value            | Posted                          |
     * | -------------------------- | ----------------------- | ------------------------------- |
     * | select, radio-group        | options [{value, …}]    | the value                       |
     * | checkbox-group             | options                 | [values]                        |
     * | others (text, date, …)     | string                  | the string                      |
     *
     * Example: {text-1: "Ann", radio-2: [{value: "gift"}]} → ['text-1' => 'Ann', 'radio-2' => 'gift'].
     *
     * @param array $wcpa_data
     * @return array
     */
    public static function posted_values($wcpa_data)
    {
        $post = [];
        foreach ($wcpa_data as $section) {
            if (!isset($section['fields']) || !is_array($section['fields'])) {
                continue;
            }
            foreach ($section['fields'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $field) {
                    if (!is_array($field) || !isset($field['type'], $field['name']) || !is_string($field['name']) || !array_key_exists('value', $field)) {
                        continue;
                    }
                    if ((isset($field['clStatus']) && $field['clStatus'] !== 'visible') || in_array($field['type'], ['content', 'header'], true)) {
                        continue;
                    }
                    $value = $field['value'];
                    if ($value === false || $value === null) {
                        continue;
                    }
                    if (in_array($field['type'], ['select', 'radio-group', 'checkbox-group'], true) && is_array($value)) {
                        $values = [];
                        foreach ($value as $option) {
                            if (is_array($option) && isset($option['value'])) {
                                $values[] = (string)$option['value'];
                            }
                        }
                        if (empty($values)) {
                            continue;
                        }
                        $post[$field['name']] = (count($values) === 1 && $field['type'] !== 'checkbox-group') ? $values[0] : $values;
                        continue;
                    }
                    if (is_scalar($value)) {
                        $post[$field['name']] = (string)$value;
                    } elseif (is_array($value)) {
                        $post[$field['name']] = array_map(function ($v) {
                            return is_scalar($v) ? (string)$v : '';
                        }, $value);
                    }
                }
            }
        }

        return $post;
    }
}
