<?php


namespace Acowebs\WCPA\Free;


class CLogic
{
    public $form_data = false;
    public $fields = false;
    public $product = false;
    public $parentProduct = false;
    public $quantity = false;

    public function __construct($form_data, $fields, $product, $parentProduct, $quantity)
    {
        $this->form_data = $form_data;
        $this->fields = $fields;
        $this->product = $product;
        $this->parentProduct = $parentProduct;
        $this->quantity = $quantity;
    }

    /** formData is not passed as reference, so each  time it has to update as changes occures
     * @param $form_data
     */
    public function setFormData($form_data)
    {
        $this->form_data = $form_data;
    }
    /**
     * @param $cl_dependency
     * @param $processed_ids
     *
     * @since 5.0
     */
    public function processClDependency($cl_dependency, &$processed_ids)
    {
        if ($cl_dependency && is_array($cl_dependency) && count($cl_dependency)) {
            foreach ($cl_dependency as $elementID) {
                if ($processed_ids !== false && !in_array($elementID, $processed_ids)) {
                    return;
                }
                $fieldIndex = findFieldById($this->form_data, $elementID, true);
                if ($fieldIndex === false) {
                    return;
                }
                $field = $this->fields->{$fieldIndex['sectionKey']}->fields[$fieldIndex['rowIndex']][$fieldIndex['colIndex']];
                $clStatus = $this->evalConditions($field->cl_rule,
                    $field->relations); // returns false if it catch error
                if ($clStatus !== false) {
                    $this->form_data[$fieldIndex['sectionKey']]['fields'][$fieldIndex['rowIndex']][$fieldIndex['colIndex']]['clStatus'] = $clStatus;
                    if ($field->cl_dependency) {
                        $this->processClDependency($field->cl_dependency, $processed_ids);
                    }
                }
            }
        }
    }

    /** Joins a stored relation may use between two condition results (SEC-008, as Pro 5.8.3). */
    const JOIN_OPERATORS = ['and', 'or', '&&', '||'];

    /**
     * Whether a stored relation operator is a known join (any case, spaces around it ignored).
     *
     * @param mixed $operator stored value
     *
     * @return bool
     */
    public static function isJoinOperator($operator)
    {
        return is_string($operator) && in_array(strtolower(trim($operator)), self::JOIN_OPERATORS, true);
    }

    /**
     * The join to write between two condition results.
     *
     * The evaluated expression may contain only rule results, brackets and these words, so a stored value is
     * used only when it is a known join, kept as written (trimmed). Anything else (another word, an empty
     * string, null, `false`) joins as `and`, the editor's default join. After the last rule or group the join
     * is cut off before evaluation, so it has no effect there. Before SEC-008 the first known word found
     * anywhere inside the value was used (`XOR` became `OR`), and a value without one left the join empty,
     * which stopped the page with a PHP ParseError; editor-saved forms only hold known joins or `false` at
     * the end, so they evaluate as before.
     *
     * | Stored        | Joins as |
     * | ------------- | -------- |
     * | `and`, `AND`  | as written |
     * | ` or `, `Or`  | `or`, `Or` |
     * | `&&`, `\|\|`  | as written |
     * | `XOR`, `android`, `''`, null, false | `and` |
     *
     * The storefront preview applies the same rule (`wcpaJoinOperator` in assets/js/front-end.js and
     * assets/shared.js).
     *
     * @param mixed $operator stored value
     *
     * @return string
     */
    public static function joinOperator($operator)
    {
        return self::isJoinOperator($operator) ? trim($operator) : 'and';
    }

    /**
     * Process conditional logic with relations provided
     *
     * @param $clRule
     * @param $relations
     * @param $product_id
     *
     * @return string
     */

    public function evalConditions($clRule, $relations)
    {
        $eval_str = '';
        foreach ($relations as $relation) {
            if (is_array($relation->rules) && count($relation->rules)) {
                $eval_str .= '(';
                foreach ($relation->rules as $rule) {
                    $eval_str .= '(';
                    if ($this->eval_relation($rule->rules)) {
                        $eval_str .= ' true ';
                    } else {
                        $eval_str .= ' false ';
                    }
                    $eval_str .= ') ' . self::joinOperator(isset($rule->operator) ? $rule->operator : false) . ' ';
                }

                if (count($relation->rules) > 0) {
                    preg_match_all('/\(.*\)/', $eval_str, $match);
                    $eval_str = $match[0][0] . ' ';
                }
                $eval_str .= ') ' . self::joinOperator(isset($relation->operator) ? $relation->operator : false) . ' ';
            }
        }
        if (count($relations) > 0) {
            preg_match_all('/\(.*\)/', $eval_str, $match);
            $eval_str = $match[0][0] . ' ';
        } else {
            return 'visible';// jus return visible if no relations are set
        }

        $eval_str = str_replace(['and', 'or'], ['&&', '||'], $eval_str);

        $result = eval('return ' . $eval_str . ';');
        $clStatus = false;
        if ($result === true) {
           
            if ($clRule === 'show') {
                $clStatus = 'visible';
            } else {
                $clStatus = 'hidden';
            }
        } else {
            if ($clRule === 'show') {
                $clStatus = 'hidden';
            } else {
                $clStatus = 'visible';
            }
        }

        return $clStatus;
    }

    public function eval_relation($rule)
    {
        if (!isset($rule->cl_field) || empty($rule->cl_field)) {
            return true;
        }
        if ($rule->cl_relation === '0') {
            return false;
        }

        $inputVal = [];

        $multipleAllowed = in_array($rule->cl_relation, [
            'is_in',
            'is_not_in',

        ]);
        $relationVal = $rule->cl_val;

        if (!is_array($relationVal)) {
            $relationVal = [$relationVal];
        }
        if (!$multipleAllowed) {
            /** there is chance to have multiple values in array even if it is 'is' comparison, that need to omit and take the first index value only */
            $relationVal = isset($relationVal[0]) ? [$relationVal[0]] : [];
        }
        $isDate = false;

        $fieldIndex = findFieldById($this->form_data, $rule->cl_field, true);
        /** if field not submitted, it can be taken as empty , and can match with is_empty/is_not_empty */
        if ($fieldIndex !== false) {
            $dataField = $this->form_data[$fieldIndex['sectionKey']]['fields'][$fieldIndex['rowIndex']][$fieldIndex['colIndex']];
            if ($dataField && $dataField['value']) {
                $dataSection = $this->form_data[$fieldIndex['sectionKey']]['extra'];
                if ($dataSection !== false) {
                    $is_visible = $dataSection->clStatus === 'visible';
                } else {
                    $is_visible = false;
                }
                if ($is_visible) {
                    $is_visible = $dataField['clStatus'] === 'visible';
                }
                if ($is_visible) {
                    switch ($dataField['type']) {
                        case 'hidden':
                        case 'text':
                        case 'color':
                        case 'textarea':
                        case 'url':
                        case 'email':
                            $inputVal[] = strtolower($dataField['value']);
                            $relationVal = array_map('strtolower', $relationVal);
                            break;

                        case 'number':
                            $inputVal[] = floatval($dataField['value']);
                            $relationVal = array_map('floatval', $relationVal);
                            break;
                        case 'checkbox':
                            $inputVal[] = $dataField['value'];
                            break;

                        case 'select':
                        case 'checkbox-group':
                        case 'radio-group':


                            $inputVal = array_map(function ($v) {


                                return strtolower($v['value']);
                            }, $dataField['value']);

                            $relationVal = array_map('strtolower', $relationVal);
                            break;
                        case 'date':

                            /** date value will not be array always */
                            $isDate = true;
                            $inputVal = processDateValueForCl(isset($dataField['value'][0]) ? $dataField['value'] : [$dataField['value']]);// array_map( 'processDateValueForCl', is_array( $dataField['value'] ) ? $dataField['value'] : [ $dataField['value'] ] );
                            $relationVal = processDateValueForCl($relationVal);// array_map( 'processDateValueForCl', $relationVal );

                            break;

                    }
                }
            }
        }


        $inputVal = array_map(function ($v) {
            return wp_unslash($v);
        }, $inputVal);
        $inputVal = array_values($inputVal);// reset array index

        if (count($inputVal) === 0) {
            switch ($rule->cl_relation) {
                case 'is_empty':
                    return true;
                default:
                    return false;
            }
        }

        switch ($rule->cl_relation) {
            case 'is':
            case 'is_not':

            case 'is_in':
            case 'is_not_in':
                $is_in = false;
                if ($isDate) {
                    foreach ($relationVal as $r) {
                        if (is_object($r)) {
                            foreach ($inputVal as $v) {
                                if (is_object($v)) {
                                    if (($v->start >= $r->start && $v->start <= $r->end) || ($v->end >= $r->start && $v->end <= $r->end)) {
                                        $is_in = true;
                                        break;
                                    }
                                } else {
                                    if ($v >= $r->start && $v <= $r->end) {
                                        $is_in = true;
                                    }
                                }
                            }
                            if ($is_in) {
                                break;
                            }
                        }
                        if ($is_in) {
                            break;
                        }
                        foreach ($inputVal as $v) {
                            if (is_object($v)) {
                                if ($r >= $v->start && $r <= $v->end) {
                                    $is_in = true;
                                }
                            } else {
                                if ($v == $r) {
                                    $is_in = true;
                                }
                            }
                            if ($is_in) {
                                break;
                            }
                        }
                        if ($is_in) {
                            break;
                        }
                    }


                    return $is_in ? ($rule->cl_relation == 'is_in' || $rule->cl_relation == 'is')
                        : ($rule->cl_relation == 'is_not_in' || $rule->cl_relation == 'is_not');
                }

                foreach ($relationVal as $r) {
                    if (in_array($r, $inputVal)) {
                        $is_in = true;
                    }
                    if ($is_in) {
                        break;
                    }
                }

                return $is_in ? ($rule->cl_relation == 'is_in' || $rule->cl_relation == 'is')
                    : ($rule->cl_relation == 'is_not_in' || $rule->cl_relation == 'is_not');


            case 'is_empty':
            case 'is_not_empty':
                if (count($inputVal) === 0 || $inputVal[0] === "" || $inputVal[0] === null) {
                    return $rule->cl_relation == 'is_empty';
                } else {
                    return $rule->cl_relation == 'is_not_empty';
                }

            case "is_greater":


                /** greater if all of the array values are greater */
                $is_less = false;
                foreach ($inputVal as $e) {
                    if ($isDate && is_object($e)) {
                        if ($e->start <= $relationVal[0]) {
                            $is_less = true;
                        }
                    } else {
                        if ($e <= $relationVal[0]) {
                            $is_less = true;
                        }
                    }
                    if ($is_less) {
                        break;
                    }
                }

                return !$is_less;

            case "is_lessthan_or_equal":
                $is_greater = false;

                /** check if any of the value less, then it is false */
                foreach ($inputVal as $e) {
                    if ($isDate && is_object($e)) {
                        if ($e->end > $relationVal[0]) {
                            $is_greater = true;
                        }
                    } else {
                        if ($e > $relationVal[0]) {
                            $is_greater = true;
                        }
                    }
                    if ($is_greater) {
                        break;
                    }
                }

                return !$is_greater;
            case "is_lessthan":
                $is_greater = false;

                foreach ($inputVal as $e) {
                    if ($isDate && is_object($e)) {
                        if ($e->end >= $relationVal[0]) {
                            $is_greater = true;
                        }
                    } else {
                        if ($e >= $relationVal[0]) {
                            $is_greater = true;
                        }
                    }
                    if ($is_greater) {
                        break;
                    }
                }

                return !$is_greater;
            case "is_greater_or_equal":
                $is_less = false;

                foreach ($inputVal as $e) {
                    if ($isDate && is_object($e)) {
                        if ($e->start < $relationVal[0]) {
                            $is_less = true;
                        }
                    } else {
                        if ($e < $relationVal[0]) {
                            $is_less = true;
                        }
                    }
                    if ($is_less) {
                        break;
                    }
                }

                return !$is_less;

        }

        return false;
    }




}
