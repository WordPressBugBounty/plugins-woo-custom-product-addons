<?php

namespace Acowebs\WCPA\Free;


function extractFormData($field)
{
    $attrs = [
        'name',
        'label',
        'description',
        'value',
        'active',
        'required',
        'elementId',
        'fee_label',
        'subtype',
        'maxlength',
        'allow_multiple',
        'minlength',
        'min',
        'max',
        'multiple'

    ];
    $_field = [];
    foreach ($attrs as $att) {
        if (isset($field->{$att})) {
            $_field[$att] = $field->{$att};
        }
    }

    return (object)$_field;
}


/**
 * Reads a field's submitted value from the request (add to cart, re-render after a refused add to cart).
 *
 * Called with only a name (older callers, custom code), it reads `$_POST[$name]` exactly as before ADM-007.
 * Called with the field (ReadForm, Render), it reads the key the page used (wcpa_request_lookup): Field Names with
 * a space, dot or bracket are rewritten by PHP before the plugin sees the request, and fields sharing a name used
 * to overwrite each other, so a plain `$_POST[$name]` lost their input (ADM-007, DEC-127; tickets 37046, 37245).
 *
 * @param string      $name   Stored Field Name.
 * @param string      $action 'isset' (was anything posted for it) or 'value'.
 * @param bool        $prefix Unused in Free (kept for the signature Pro shares).
 * @param object|null $field  The field (name, elementId, requestName, requestShared); null for the name-only read.
 * @return bool|mixed isset: bool; value: the posted value, or null when nothing was posted.
 */
function fieldFromName($name, $action = 'value', $prefix = false, $field = null)
{
    if (is_object($field)) {
        list($found, $value) = wcpa_request_lookup($_POST, $field, wcpa_request_marked($_POST));
    } else {
        $found = isset($_POST[$name]);
        $value = $found ? $_POST[$name] : null;
    }
    if ($action == 'isset') {
        return $found;
    }

    if ($action == 'value') {
        return $value;
    }
}

/*
 * ADM-007 request names (DEC-127): a field's input is never lost because of its Field Name. Same rule as Pro
 * (includes/process/request-names.php RequestNames there); hub guide docs/code/data-contracts.md "Request names".
 *
 * The product page posts each input under the field's stored name. PHP rewrites request names before any plugin code
 * runs (main/php_variables.c): leading spaces are dropped, spaces and dots become "_", "[" starts array syntax (an
 * unmatched "[" becomes "_", and PHP 8 also converts what follows it while 7.4 does not), text after a matched "]"
 * and a name starting with "[" are dropped. When two inputs arrive under one key, PHP keeps the last one, so the field
 * order on the page decided which field got it. Two rules fix the class:
 *
 * 1. No two fields of a product page post to the same key (wcpa_assign_request_names, run by Product::get_fields,
 *    whose result feeds both the page and the server read).
 * 2. The server reads the key the page used (wcpa_request_lookup). A page rendered with request names posts the
 *    marker input WCPA_REQUEST_NAMES_MARK.
 */

/**
 * Request key a page that uses request names also posts (hidden input, value 1; Render::render_form). Its presence
 * tells the server that the page posted request names; without it the request comes from a page rendered before
 * ADM-007 (page cache) or was built in memory under stored names (Omnisend recovery, COMPAT-007).
 */
if (!defined('WCPA_REQUEST_NAMES_MARK')) {
    define('WCPA_REQUEST_NAMES_MARK', 'wcpa_request_names');
}

/**
 * The key path PHP delivers for a name posted by a form, asked from PHP's own request parser.
 *
 * | Posted name  | Path (PHP 8)        | Why                                                      |
 * | ------------ | ------------------- | -------------------------------------------------------- |
 * | `size`       | ['size']            | kept                                                     |
 * | `Regel 1`    | ['Regel_1']         | space becomes `_` (also `.`, and an unmatched `[`)        |
 * | ` x`         | ['x']               | leading spaces are dropped                               |
 * | `regel[1]`   | ['regel', '1']      | matched brackets are array syntax                        |
 * | `a[b.c d`    | ['a_b_c_d']         | PHP 7.4 gives ['a_b.c d']: the rules differ by version    |
 * | `[x]`        | []                  | dropped: the value never reaches PHP code                |
 *
 * Computed at run time rather than copied from a table, because PHP 7.4 and 8 differ (characterized byte by byte on
 * 7.4.33 and 8.2.12: only bytes 32, 46 and 91 are rewritten; hub evidence
 * work/evidence/test-runs/2026-11-12-adm007-characterization.md §1). Cached per name for the request.
 *
 * @param string $posted Name as the browser posts it.
 * @return string[] Keys from the top level down; [] when PHP drops the name.
 */
function wcpa_request_path($posted)
{
    static $cache = [];
    $posted = (string)$posted;
    if (!isset($cache[$posted])) {
        $parsed = [];
        parse_str(rawurlencode($posted) . '=1', $parsed);
        $path = [];
        while (is_array($parsed) && count($parsed) === 1) {
            $k = array_keys($parsed)[0];
            $path[] = (string)$k;
            $parsed = $parsed[$k];
        }
        $cache[$posted] = $path;
    }

    return $cache[$posted];
}

/** Whether PHP delivers $name under exactly that key. */
function wcpa_request_kept($name)
{
    return is_string($name) && $name !== '' && wcpa_request_path($name) === [$name];
}

/**
 * The suffix inputs a field posts beside its main input, as in Pro (quantity, "Other" text, place parts, repeater
 * copy counts). Free's storefront renders none of these today, so the list is empty for Free's field types; it is
 * kept so the rule stays the same as Pro's when a Free field carries such settings (imported from Pro).
 *
 * @param object $field
 * @return string[]
 */
function wcpa_request_suffixes($field)
{
    $s = [];
    if (!empty($field->enable_quantity)) {
        $s[] = '_quantity';
    }
    if (!empty($field->other)) {
        $s[] = 'other_value';
    }
    if (isset($field->type) && $field->type === 'placeselector') {
        $s = array_merge($s, ['_street_number', '_route', '_locality', '_area_level_1', '_administrative_area_level_1',
            '_postal_code', '_country', '_lat', '_lng']);
    }
    if (!empty($field->repeater)) {
        $s[] = '_cl';
    }

    return $s;
}

/** Top-level request keys a field occupies when posted under $name; null when PHP folds or drops one of them. */
function wcpa_request_keys_of($field, $name)
{
    $keys = [];
    foreach (array_merge([''], wcpa_request_suffixes($field)) as $suffix) {
        $p = wcpa_request_path($name . $suffix);
        if (count($p) !== 1 || $p[0] === '') {
            return null;
        }
        $keys[] = $p[0];
    }

    return $keys;
}

/** Whether $name posts every input of $field under exactly the key it is given. */
function wcpa_request_stable($field, $name)
{
    foreach (array_merge([''], wcpa_request_suffixes($field)) as $suffix) {
        if (!wcpa_request_kept($name . $suffix)) {
            return false;
        }
    }

    return true;
}

/**
 * Keys a product form request carries beside the fields, which a field never takes: WooCommerce's own inputs
 * (quantity, add-to-cart, product_id, variation_id; variation attributes by their prefix `attribute_`, as their slugs
 * are not known here) and the keys Free itself puts in such a request (the marker; `wcpa_current_cart_key` in the
 * Omnisend recovery request, compatibility/omnisend.php). Exact keys only, so a merchant field named e.g. `wcpa_note`
 * keeps its name and request key, as before ADM-007 (DEC-039). Pro lists its own extra hidden inputs (wcpa_child,
 * wcpa_meta, wcpa_product_image, wcpa_row_count), which Free's product form does not post.
 */
function wcpa_request_reserved($key)
{
    return in_array($key, ['quantity', 'add-to-cart', 'product_id', 'variation_id', 'wcpa_current_cart_key',
            WCPA_REQUEST_NAMES_MARK], true)
        || strpos($key, 'attribute_') === 0;
}

/**
 * Gives every field of a product page a request name where its stored name would not arrive as itself, alone (rule 1).
 *
 * | Stored name(s) on the page                     | requestName                       | Why                                   |
 * | ---------------------------------------------- | --------------------------------- | ------------------------------------- |
 * | `text_1a2b`, `voor_regel-1`, `système` (alone) | none (posted as stored)           | PHP keeps it; almost every store      |
 * | `Regel 1` (alone)                              | `Regel_1`                         | the key PHP gives it today, so custom code reading `$_POST['Regel_1']` keeps working |
 * | `regel[1]`, `[x]`                              | `regel_1__<elementId>`            | PHP folds or drops it                 |
 * | `size` in two forms                            | first `size__<elementId>`, last none | the last keeps the key: `$_POST['size']` holds what it holds today |
 * | `a b` and `a_b` in one form                    | `a_b__<elementId>`, last none / `a_b` | both arrive as `a_b`               |
 * | a field named `quantity`                       | `quantity__<elementId>`           | WooCommerce's own quantity input uses the key |
 *
 * Fields that share a key (also through a suffix input, transitively) are one group: every field but the last in
 * render order gets a derived name, and all of them are marked `requestShared` (rule 2 then never reads a converted
 * key for them on an unmarked request). A derived name is the base as letters, digits, `_` and `-` (at most 60),
 * `__`, the element ID, and `_2`, `_3`... when that key is taken (forms duplicated with the same element IDs).
 * Properties are only written where needed (and removed otherwise), so a page whose fields all keep their names is
 * byte-identical to the page before ADM-007.
 *
 * @param object|array $sections Product::get_fields 'fields' (sections → rows → fields, render order).
 * @return bool Whether any field got a request name (the page then posts WCPA_REQUEST_NAMES_MARK).
 */
function wcpa_assign_request_names($sections)
{
    $fields = [];
    foreach ((array)$sections as $section) {
        if (!is_object($section) || !isset($section->fields) || !is_array($section->fields)) {
            continue;
        }
        foreach ($section->fields as $row) {
            foreach ((array)$row as $field) {
                if (!is_object($field)) {
                    continue;
                }
                unset($field->requestName, $field->requestShared);
                if (!isset($field->name) || !is_string($field->name) || $field->name === ''
                    || (isset($field->type) && in_array($field->type, ['content', 'header', 'separator', 'formselector'], true))) {
                    continue;
                }
                $fields[] = $field;
            }
        }
    }
    if (!$fields) {
        return false;
    }

    // Keys each field occupies as the page posted it before ADM-007 (stored names).
    $owners = [];
    $keys = [];
    foreach ($fields as $i => $field) {
        $keys[$i] = wcpa_request_keys_of($field, $field->name);
        foreach ((array)$keys[$i] as $k) {
            $owners[$k][] = $i;
        }
    }
    // Fields that share a key, transitively (two names may meet through a suffix input).
    $group = range(0, count($fields) - 1);
    $find = function ($i) use (&$group) {
        while ($group[$i] !== $i) {
            $i = $group[$i] = $group[$group[$i]];
        }

        return $i;
    };
    foreach ($owners as $list) {
        for ($n = 1; $n < count($list); $n++) {
            $a = $find($list[0]);
            $b = $find($list[$n]);
            if ($a !== $b) {
                $group[max($a, $b)] = min($a, $b);
            }
        }
    }
    $members = [];
    foreach ($fields as $i => $field) {
        $members[$find($i)][] = $i;
    }

    $taken = [];
    foreach ($keys as $list) {
        foreach ((array)$list as $k) {
            $taken[$k] = true;
        }
    }
    $any = false;
    foreach ($members as $list) {
        $shared = count($list) > 1;
        $last = end($list);
        foreach ($list as $i) {
            $field = $fields[$i];
            $own = $keys[$i];
            $base = $own === null ? null : $own[0];
            $clash = $own === null || array_filter($own, __NAMESPACE__ . '\\wcpa_request_reserved');
            if ($shared) {
                $field->requestShared = true;
            }
            if (!$clash && (!$shared || $i === $last)) {
                if ($base === $field->name && wcpa_request_stable($field, $base)) {
                    continue;                                   // posted as stored: nothing to do
                }
                if (wcpa_request_stable($field, $base)) {
                    $field->requestName = $base;                // the key PHP gives it today
                    $any = true;
                    continue;
                }
            }
            $field->requestName = wcpa_request_derived($field, $base, $taken);
            $any = true;
        }
    }

    return $any;
}

/** A key PHP keeps and no other input of the page uses: "<name as letters, digits, _ and ->__<elementId>". */
function wcpa_request_derived($field, $base, &$taken)
{
    $stem = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $base !== null && $base !== '' ? $base : (string)$field->name);
    $stem = trim(substr((string)$stem, 0, 60), '_');
    // A stem with a reserved prefix would make every candidate reserved (`attribute_size__<id>`): prefix it.
    if ($stem !== '' && wcpa_request_reserved($stem . '__x')) {
        $stem = 'f_' . $stem;
    }
    $id = preg_replace('/[^A-Za-z0-9_\-]+/', '_', isset($field->elementId) ? (string)$field->elementId : 'field');
    $name = ($stem === '' ? 'field' : $stem) . '__' . $id;
    $try = $name;
    for ($n = 2; $n < 1000; $n++) {
        $keys = wcpa_request_keys_of($field, $try);
        $free = $keys !== null && wcpa_request_stable($field, $try);
        foreach ((array)$keys as $k) {
            if (isset($taken[$k]) || wcpa_request_reserved($k)) {
                $free = false;
            }
        }
        if ($free) {
            foreach ($keys as $k) {
                $taken[$k] = true;
            }

            return $try;
        }
        $try = $name . '_' . $n;
    }

    // Not reached in practice (999 taken keys with this stem); a key no merchant name produces.
    return 'f_' . substr(md5($name . count($taken)), 0, 12) . '__' . $id;
}

/** Whether a request was posted by a page that uses request names. */
function wcpa_request_marked($source)
{
    return is_array($source) && isset($source[WCPA_REQUEST_NAMES_MARK]);
}

/**
 * Finds a field's value in a request array (rule 2).
 *
 * - Marked request (the page posted request names): a field with a request name is read under it only; reading
 *   its stored name there would read another field's input (a field sharing `size` with a later field posts as
 *   `size__<id>`; left empty, `size` holds the later field's choice). A field without one is read under its name.
 * - Unmarked request (a page cached before the update, a URL prefill, or a request built in memory under stored
 *   names by the Omnisend recovery): the stored name first, as before ADM-007; when it is absent and the field has
 *   a request name and shares no key with another field, the key path PHP made of the stored name.
 *
 * | Request                                            | Field `Regel 1` (request name `Regel_1`) | Fields `size`, `size` (first `size__s1`) |
 * | -------------------------------------------------- | ----------------------------------------- | ---------------------------------------- |
 * | new page: marker, `Regel_1=x` / `size__s1=a&size=b` | x                                         | a, b                                     |
 * | page cached before the update: `Regel 1=x`          | x (PHP's key)                             | b, b (as before: PHP kept the later one) |
 * | Omnisend recovery: `['Regel 1' => 'x']` in memory  | x (stored name)                           | the stored value                         |
 *
 * @param array  $source $_POST or $_GET.
 * @param object $field  name, optional requestName and requestShared (wcpa_assign_request_names()).
 * @param bool   $marked wcpa_request_marked($source).
 * @return array [bool found, mixed value]
 */
function wcpa_request_lookup($source, $field, $marked)
{
    if (!is_array($source) || !isset($field->name) || !is_scalar($field->name)) {
        return [false, null];
    }
    $name = (string)$field->name;
    $request = isset($field->requestName) ? (string)$field->requestName : null;
    if ($marked && $request !== null) {
        return isset($source[$request]) ? [true, $source[$request]] : [false, null];
    }
    if (isset($source[$name])) {
        return [true, $source[$name]];
    }
    if ($marked || $request === null || !empty($field->requestShared)) {
        return [false, null];
    }
    $path = wcpa_request_path($name);
    if (!$path || $path === [$name]) {
        return [false, null];
    }
    $value = $source;
    foreach ($path as $k) {
        if (!is_array($value) || !isset($value[$k])) {
            return [false, null];
        }
        $value = $value[$k];
    }

    return [true, $value];
}

function getDateFormat($field)
{

    if ($field->type == 'time') {
        $dateFormat = __(get_option('time_format'), 'woo-custom-product-addons');
    } elseif ($field->type == 'datetime-local') {
        $dateFormat = __(get_option('date_format'), 'woo-custom-product-addons') . ' ' . __(
                get_option('time_format'),
                'woo-custom-product-addons'
            );
    } else {
        $dateFormat = __(get_option('date_format'), 'woo-custom-product-addons');
    }

    return $dateFormat;
}

function isEmpty($var)
{
    if (is_array($var)) {
        return empty($var);
    } else {
        return ($var === null || $var === false || $var === '');
    }
}

function emptyObj($obj)
{
    foreach ($obj as $prop) {
        return false;
    }

    return true;
}

function priceToFloat($price)
{
    $locale = localeconv();
    $decimals = array(wc_get_price_decimal_separator(), $locale['decimal_point'], $locale['mon_decimal_point']);
    $price = str_replace($decimals, '.', $price);

    return (float)$price;
}

function getValueFromArrayValues($val)
{
    if (is_array($val)) {
        if (isset($val['value'])) {
            /** place selector */
            return $val['value'];
        } elseif (count($val)) {
            /**
             * For array of values, sum the values if the values are numeric
             * Otherwise return the first value
             */

            $p_temp = array_values($val)[0];
            $_i = -1;
            $valueSum = 0.0;
            foreach ($val as $_p) {
                $_i++;
                if (is_array($_p)) {
                    if (is_numeric($_p['value'])) {
                        $valueSum += (float)$_p['value'];
                    } elseif ($_i == 0) {
                        $valueSum = $_p['value'];
                        break;
                    }
                } else {
                    if (is_numeric($_p)) {
                        $valueSum += (float)$_p;
                    } elseif ($_i == 0) {
                        $valueSum = $_p;
                        break;
                    }
                }
            }

            return $valueSum;

        }

        return false;
    }

    return $val;
}


/**
 * Read a stored add-on date or time text into a DateTime in UTC (conditions; processDateValueForCl()).
 *
 * Always UTC (ORD-010): the text names a calendar day, so it must not move with PHP's default zone, which a
 * plugin or snippet may change (WordPress keeps it at UTC, where the result is the same as before). A date-only
 * text keeps the current time of day as before ('Y-m-d' without '!'); both sides of a condition are read the
 * same way. date_create_from_format() refuses a text in another format (DateTime() accepts almost anything).
 *
 * @param string $dateString Stored text, for example '2030-06-15'.
 * @param string $type       Field type.
 *
 * @return \DateTime|false
 */
function getUNIDate($dateString, $type = 'date')
{
    $utc = new \DateTimeZone('UTC');
    if ($type == 'time') {
        return date_create_from_format('H:i', $dateString, $utc);
    } elseif ($type == 'datetime-local' || $type == 'datetime') {
        return date_create_from_format('Y-m-d H:i', $dateString, $utc);
    } else {
        return date_create_from_format('Y-m-d', $dateString, $utc);
    }
}


function confToCss($conf)
{
    $css = '';
    foreach ($conf as $k => $v) {
        if (strpos($v, '#') == 0 && strlen($v) == 9) {
            /** convert hex color with opacity to rgba */
            list($r, $g, $b, $a) = sscanf($v, "#%02x%02x%02x%02x");
            $a = round($a / 255, 2);
            $v = "rgba($r,$g,$b,$a)";
        }
        $css = $css . '  --wcpa' . $k . ':' . $v . '; ';
    }

    return ':root{' . $css . '}';
}

function metaToBoolean($v)
{
    if ($v === '' || $v === '0') {
        return false;
    } elseif ($v === '1') {
        return true;
    }

    return $v;
}

/**
 * convert wcpa 1 field structure to wcpa2
 *
 * @param $fields
 *
 * @return array
 */
function toRowCol($fields)
{
    $newArray = array();
    $row = 0;
    $col = 0;
    foreach ($fields as $i => $item) {
        $newItem = $item;
        $newItem->active = true;
        if (!isset($newItem->col)) {
            $newItem->col = 6;
        }
        if (($col + $newItem->col) > 6) {
            $row++;
            $col = $newItem->col;
        } else {
            $col += $newItem->col;
        }
        $newArray[$row][] = $newItem;
    }


    return $newArray;
}

function fix_cols($data)
{
    $newArray = array();

    $colCount = 0;
    $rowCount = 0;
    $newArray = array();

    foreach ($data as $row) {
        if (is_array($row)) {
            foreach ($row as $field) {
                if (($colCount + $field->col) > 6) {
                    $rowCount++;
                    $colCount = $field->col;
                } else {
                    $colCount += $field->col;
                }
                $newArray[$rowCount][] = $field;
            }
        } else {
            if (($colCount + $row->col) > 6) {
                $rowCount++;
                $colCount = $row->col;
            } else {
                $colCount += $row->col;
            }
            $newArray[$rowCount][] = $row;
        }
    }

    return $newArray;
}


/**
 * Check if the fields are in old wcpa structure or new
 *
 * @param $data
 */
function checkFieldStructure($data)
{
    $value = reset($data); // get first value
    if (isset($value->fields)) {
        return 2;
    } else {
        return 1;
    }
}

function generateSectionFields($fields = [])
{
    $new_arr = (object)[];
    $sectionKey = 'sec_' . uniqSectionId();
    $new_arr->{$sectionKey} = (object)array(
        "extra" => (object)[
            'key' => $sectionKey,
            'section_id' => $sectionKey,
            'name' => __('Default', 'woo-custom-product-addons'),
            'status' => 1,

            "toggle" => true,


        ],
        "fields" => $fields
    );

    return $new_arr;
}

function uniqSectionId()
{
    return uniqid(rand(0, 10), false);
}

/**
 * Reduce a saved field type to the characters a field type can contain.
 *
 * Every type the form builder creates (text, checkbox-group, datetime-local,
 * productGroup, ...) uses only A-Z, a-z, 0-9, "_" and "-", so real types are
 * returned unchanged. Anything else (quotes, angle brackets, spaces) is
 * removed, because the type is later printed into HTML class names.
 * sanitize_key() is not used: it lowercases, which would change productGroup.
 *
 * | Input                     | Result             |
 * | ------------------------- | ------------------ |
 * | 'checkbox-group'          | 'checkbox-group'   |
 * | 'x"><img src=x>'          | 'ximgsrcx'         |
 * | array / object            | ''                 |
 *
 * @param mixed $type Field type from the submitted form JSON.
 * @return string
 */
function sanitizeFieldType($type)
{
    if ( ! is_scalar($type)) {
        return '';
    }

    return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $type);
}

function sanitizeFields(&$formBuilderData, $allowed_html)
{
    foreach ($formBuilderData as $sectionKey => $section) {
        foreach ($section['fields'] as $rowIndex => $row) {
            foreach ($row as $colIndex => $field) {
                $_field = &$formBuilderData[$sectionKey]['fields'][$rowIndex][$colIndex];
                if (isset($field['type'])) {
                    // The type is printed into HTML (cart class) later; keep only class characters (SEC-005).
                    $_field['type'] = $field['type'] = sanitizeFieldType($field['type']);
                }
                if (isset($field['label']) && ($field['type'] == 'content' || $field['type'] == 'header')) {
                    $_field['label'] = html_entity_decode(wp_kses($field['label'], 'post'));
                } elseif (isset($field['label'])) {
                    $_field['label'] = html_entity_decode(wp_kses($field['label'], array()));
                }
                if (isset($field['description'])) {
                    $_field['description'] = html_entity_decode(wp_kses($field['description'], $allowed_html));
                }

                if (isset($field['values'])) {
                    foreach ($field['values'] as $k => $v) {
                        if (isset($v['label'])) {
                            $_field['values'][$k]['label'] = html_entity_decode(
                                wp_kses($field['values'][$k]['label'], array())
                            );
                        }


                        if (isset($v['value'])) {
                            $_field['values'][$k]['value'] = trim($field['values'][$k]['value']);
                        }
                    }
                }
            }
        }
    }
}


/**
 * Using to check if the date contains from to value ( 2011-1-20 to 2022-02-30)
 */
function processDateValueForCl($val)
{
    $res = [];
    if (is_array($val)) {
        foreach ($val as $dt) {
            $d = getUNIDate($dt);
            if ($d) {
                $res[] = $d->getTimestamp();
            } else {
                $res[] = $dt;
            }
        }
    } else {
        $d = getUNIDate($val);
        if ($d) {
            $res[] = $d->getTimestamp();
        } else {
            $res[] = $val;
        }
    }
    return $res;
}


function getFormEditUrl($post_id)
{
    return admin_url('admin.php?page=wcpa-admin-ui#/form/' . $post_id);
}


/**
 * Print a stored add-on date or time text in $dateFormat, as the calendar day and clock time it names.
 *
 * Stored values are calendar texts ('Y-m-d', 'Y-m-d H:i', 'H:i'), not moments. They are read in UTC and printed
 * in UTC, so the text that comes out names the same day and time whatever PHP's default zone or the WordPress
 * "Timezone" setting is. Until ORD-010 the value was read with strtotime(), in PHP's default zone, and printed in
 * UTC: right while the default zone is UTC (WordPress sets it in wp-settings.php), but when a plugin, theme or
 * snippet calls date_default_timezone_set() a date-only value printed the day before in zones east of UTC and
 * date-and-time and time values moved by the zone's offset (ticket 37236, T-37236 evidence section 4).
 *
 * | $value             | $dateFormat | Result in every PHP default zone | Before ORD-010, PHP zone not UTC |
 * | ------------------ | ----------- | -------------------------------- | -------------------------------- |
 * | '1975-12-20'       | 'm/d/Y'     | 12/20/1975                       | Kolkata: 12/19/1975              |
 * | '1975-12-20 10:00' | 'm/d/Y H:i' | 12/20/1975 10:00                 | Chicago: 12/20/1975 16:00        |
 * | '14:30'            | 'g:i a'     | 2:30 pm                          | Chicago: 8:30 pm                 |
 * | anything           | false or '' | $value unchanged                 | same                             |
 * | '' or unreadable   | 'm/d/Y'     | false (wp_date() of no time)     | same                             |
 *
 * Used by every server view of a date or time field: MetaDisplay (cart, checkout, Store API, order screens,
 * e-mails, My account) and Order::order_meta_plain (the stored row when "Save Date and Date & Time in custom
 * format" is on). Pro has the same function.
 *
 * @param string       $value      Stored text.
 * @param string|false $dateFormat PHP date format; false or '' returns $value.
 *
 * @return string|false
 */
function formattedDate($value, $dateFormat = false)
{
    if (!$dateFormat) {
        return $value;
    }
    $utc       = new \DateTimeZone('UTC');
    $timestamp = false;
    if (is_scalar($value) && trim((string)$value) !== '') {
        // The parser strtotime() uses, in UTC instead of PHP's default zone.
        $parsed    = date_create((string)$value, $utc);
        $timestamp = $parsed ? $parsed->getTimestamp() : false;
    }

    return function_exists('wp_date') ? wp_date($dateFormat, $timestamp, $utc) : gmdate($dateFormat, (int)$timestamp);
}


/**
 * find field by elementID,
 *
 * @param $formData
 * @param $element_id
 * @param false $returnIndex whether to return section=>row>col indexes only or return field itself
 *
 * @param bool $isObject
 *
 * @return array|false|mixed
 * @since 5.0
 */

function findFieldById($formData, $element_id, $returnIndex = false, $isObject = false)
{
    $resp = false;
    foreach ($formData as $sectionKey => $section) {
        if ($isObject) {
            if (!isset($section->fields)) {
                continue;
            }
            foreach ($section->fields as $rowIndex => $row) {
                foreach ($row as $colIndex => $field) {
                    if ($field->elementId === $element_id) {
                        $resp = [
                            'sectionKey' => $sectionKey,
                            'rowIndex' => $rowIndex,
                            'colIndex' => $colIndex,
                        ];
                        break;
                    }
                }
                if ($resp !== false) {
                    break;
                }
            }
        } else {
            if (!isset($section['fields'])) {
                continue;
            }
            foreach ($section['fields'] as $rowIndex => $row) {
                foreach ($row as $colIndex => $field) {
                    if (!isset($field['elementId'])) {
                        continue;
                    }
                    if ($field['elementId'] === $element_id) {
                        $resp = [
                            'sectionKey' => $sectionKey,
                            'rowIndex' => $rowIndex,
                            'colIndex' => $colIndex,
                        ];
                        break;
                    }
                }
                if ($resp !== false) {
                    break;
                }
            }
        }

        if ($resp !== false) {
            break;
        }
    }
    if ($returnIndex) {
        return $resp;
    }
    if ($resp == false) {
        return $resp;
    }

    if ($isObject) {
        return $formData->{$resp['sectionKey']}->fields[$resp['rowIndex']][$resp['colIndex']];
    }

    return $formData[$resp['sectionKey']]['fields'][$resp['rowIndex']][$resp['colIndex']];
}

function orderMetaValueForDb($label,$value){

    $format = Config::get_config('item_meta_format');
    return str_replace(['{label}','{value}'], [$label,$value], $format);
}
/**
 * Clears the prepared-form cache (transients `wcpa_fields_<product_id>[_<lang>]`, 24 h) and the
 * settings cache after a form, product or settings change.
 *
 * It first tries a bulk delete by prefix through the options table. On sites with a persistent
 * object cache (Redis, Memcached) transients are not in that table, so each affected product
 * is cleared by key instead (products assigned directly or through the form's categories, the
 * only ways a Free form reaches a product). See bulkDeleteTransients() (CACHE-002, DEC-040).
 *
 * @param int|false $form_id    Form whose products must show its new version.
 * @param int|false $product_id Product whose cache must be rebuilt.
 */
function refreshCaches($form_id = false, $product_id = false)
{
    delete_transient(WCPA_PRODUCTS_TRANSIENT_KEY);
    $ml = new ML();
    if($ml->is_active()){
        $langs = $ml->langList();
        foreach ($langs as $l) {
            delete_transient(WCPA_PRODUCTS_TRANSIENT_KEY . '_' . $l);
        }
    }
    if ($product_id) {
        delete_transient('wcpa_fields_' . $product_id);
        $status = bulkDeleteTransients('wcpa_fields_'.$product_id);
        if(!$status && $ml->is_active()){
            foreach ($langs as $l){
                delete_transient('wcpa_fields_' . $product_id.'_'.$l);
                bulkDeleteTransients('wcpa_fields_'.$product_id.'_'.$l);
            }
        }
    } elseif ($form_id) {
        $status = bulkDeleteTransients('wcpa_fields_');
        if (!$status) {
            /** some servers stores transients differently, in  that case this bulk action doesnt work,
             * so refresh cache individually findig all products connected with this form id
             */

            $form = new Form();
            $ids = $form->products_listing($form_id, true);
            global $wpdb;
            $query = "SELECT
distinct object_id from $wpdb->term_relationships
 where term_taxonomy_id"
                . " in (select tr.term_taxonomy_id from $wpdb->term_relationships as tr left join $wpdb->term_taxonomy as tt on(tt.term_taxonomy_id=tr.term_taxonomy_id) where tr.object_id in (" . implode(',',
                    [$form_id]) . ")"
                . "and  tt.taxonomy = 'product_cat')";

            $pro_ids = $wpdb->get_col($query);
            $ids = array_unique(array_merge($pro_ids, $ids));
            foreach ($ids as $id) {
                delete_transient('wcpa_fields_' . $id);

                if( $ml->is_active()){
                    $status = bulkDeleteTransients('wcpa_fields_'.$id);
                    if (!$status) {
                        foreach ($langs as $l){
                            delete_transient('wcpa_fields_' . $id.'_'.$l);
                        }
                    }

                }
            }
        }
    } else {
        $status = bulkDeleteTransients('wcpa_fields_');
        if (!$status) {
            /** some servers stores transients differently, in  that case this bulk action doesnt work, so refresh cache individually */
            $form = new Form();
            $ids = $form->get_wcpaProducts();
            if (isset($ids['full'])) {
                foreach ($ids['full'] as $id) {
                    delete_transient('wcpa_fields_' . $id);
                    if( $ml->is_active()){

                        foreach ($langs as $l){
                            delete_transient('wcpa_fields_' . $id.'_'.$l);
                        }
                    }
                }
            }

        }
    }

    deleteSettingsCaches(false); // CACHE-003
}

/**
 * Plugin versions whose settings cache may exist on this site (CACHE-003).
 *
 * The settings cache `wcpa_settings_<version>[_<lang>]` (Config::get_config) is a transient without
 * expiry named after the running version, so each version reads only its own entry, and an update
 * leaves the previous version's entry behind. A settings or design save clears every entry with a
 * prefix delete in the options table. On a site with a persistent object cache (Redis, Memcached)
 * that delete cannot run (bulkDeleteTransients), and until CACHE-003 only the running version's entry
 * was cleared. A rollback to the previous version then read the settings it had cached before the
 * update, until the settings were saved again (seen on a staging copy, ticket 37001).
 *
 * Entries in an object cache cannot be listed, so the versions are known instead: each version that
 * builds the cache records itself (recordSettingsCacheVersion), and releases from before CACHE-003,
 * which do not record, are listed below. Both editions are listed because Pro and Free use the same
 * settings option (`wcpa_settings_key`) and cache name, and a store can switch from one to the other
 * (inferred from the code; the list is the same in both editions).
 *
 * Example: a store on Pro 5.8.3 with Redis updates to 5.9.0 and changes a setting; the save clears
 * `wcpa_settings_5.8.3` (listed) as well as `wcpa_settings_5.9.0`, so going back to 5.8.3 shows the
 * new setting.
 *
 * Why no expiry instead: without an object cache, a transient with an expiry is not autoloaded, so
 * every page would read the options table twice more for the settings.
 *
 * @return string[] Version numbers, the running version included.
 */
function settingsCacheVersions()
{
    $before = [
        // Pro 5.x releases (readme.txt changelog)
        '5.8.3', '5.8.2', '5.8.1', '5.8.0', '5.7.1', '5.7.0', '5.6.0', '5.5.2', '5.5.1', '5.5.0', '5.4.7',
        '5.4.6', '5.4.5', '5.4.4', '5.4.3', '5.4.2', '5.4.1', '5.4.0', '5.3.1', '5.3.0', '5.2.2', '5.2.1',
        '5.1.0', '5.0.17', '5.0.16', '5.0.0',
        // Free 3.x releases (readme.txt changelog)
        '3.1.9', '3.1.8', '3.1.7', '3.1.6', '3.1.5', '3.1.4', '3.1.3', '3.1.2', '3.1.1', '3.1.0', '3.0.19',
        '3.0.18', '3.0.17', '3.0.16', '3.0.15', '3.0.14', '3.0.13', '3.0.12', '3.0.10', '3.0.9', '3.0.8',
        '3.0.7', '3.0.6', '3.0.5', '3.0.4', '3.0.3', '3.0.2', '3.0.0',
    ];
    $recorded = get_option('wcpa_settings_cache_versions', []);

    return array_values(array_unique(array_merge($before, is_array($recorded) ? $recorded : [], [WCPA_VERSION])));
}

/**
 * Records the running version as one whose settings cache exists (CACHE-003, see settingsCacheVersions).
 * Called by Config::get_config when it builds the cache, so it runs once per settings change at most;
 * the option is not autoloaded and is written only when the version is new to the site.
 */
function recordSettingsCacheVersion()
{
    $recorded = get_option('wcpa_settings_cache_versions', []);
    if (!is_array($recorded)) {
        $recorded = [];
    }
    if (in_array(WCPA_VERSION, $recorded, true)) {
        return;
    }
    $recorded[] = WCPA_VERSION;
    update_option('wcpa_settings_cache_versions', $recorded, false);
}

/**
 * Clears the settings cache `wcpa_settings_<version>[_<lang>]` (CACHE-003).
 *
 * | Caller                              | $allVersions | Without object cache                     | With object cache                                  |
 * | ----------------------------------- | ------------ | ---------------------------------------- | -------------------------------------------------- |
 * | Settings save, design save          | true         | prefix delete of every entry             | every known version's entry, plain and per language |
 * | refreshCaches (form, product saves) | false        | prefix delete of every entry (as before) | the running version's entry, plain and per language |
 *
 * Before CACHE-003, a settings save with an object cache cleared only the running version's entries,
 * and a design save cleared only the running version's plain entry (with or without an object cache),
 * so on a multilingual site a design change reached the storefront only after a form or settings save.
 *
 * @param bool $allVersions Also clear other plugin versions' entries (settings and design saves).
 */
function deleteSettingsCaches($allVersions = false)
{
    delete_transient('wcpa_settings_' . WCPA_VERSION);
    if (!wp_using_ext_object_cache()) {
        bulkDeleteTransients('wcpa_settings_');
        return;
    }
    $ml = new ML();
    $langs = $ml->is_active() ? $ml->langList() : [];
    foreach ($allVersions ? settingsCacheVersions() : [WCPA_VERSION] as $version) {
        delete_transient('wcpa_settings_' . $version);
        foreach ($langs as $l) {
            delete_transient('wcpa_settings_' . $version . '_' . $l);
        }
    }
}

/**
 * Deletes transients by prefix through the options table, unless the site uses a persistent
 * object cache. There, transients live in the cache; the options table finds nothing, or only
 * rows left from before the cache was enabled, which would wrongly report success and skip the
 * per-product purge in refreshCaches() (CACHE-002, same fix as Pro CACHE-001). Returning false
 * makes the caller clear each key.
 *
 * @param string $prefix Transient name prefix.
 * @return bool True when the bulk delete ran and found transients.
 */
function bulkDeleteTransients($prefix)
{
    if (wp_using_ext_object_cache()) {
        return false;
    }
    return delete_transients_with_prefix($prefix);
}

function get_transient_keys_with_prefix($prefix)
{
    global $wpdb;

    $prefix = $wpdb->esc_like('_transient_' . $prefix);
    $sql = "SELECT `option_name` FROM $wpdb->options WHERE `option_name` LIKE '%s'";
    $keys = $wpdb->get_results($wpdb->prepare($sql, $prefix . '%'), ARRAY_A);

    if (is_wp_error($keys)) {
        return [];
    }

    return array_map(function ($key) {
        // Remove '_transient_' from the option name.
        return ltrim($key['option_name'], '_transient_');
    }, $keys);
}

function delete_transients_with_prefix($prefix)
{
    $status = false;
    foreach (get_transient_keys_with_prefix($prefix) as $key) {
        $status = true;
        delete_transient($key);
    }

    return $status;
}


/**
 *  function to check a product has product form assigned
 * It can call Acowebs\WCPA\Free\has_form()
 *
 * @param $product_id
 *
 * @return string
 */
function has_form($product_id)
{
    $form = new Form();
    $wcpaProducts = $form->get_wcpaProducts();

    return in_array($product_id, $wcpaProducts['full']);
}

/**
 * Polyfill for `array_key_last()` function added in PHP 7.3.
 *
 * @param array $array An array.
 *
 * @return string|int|null The last key of array if the array
 *.                        is not empty; `null` otherwise.
 */
function array_key_last($array)
{

    if (empty($array)) {
        return null;
    }

    end($array);

    return key($array);
}

function array_key_first(array $array)
{

    foreach ($array as $key => $value) {
        return $key;
    }
}