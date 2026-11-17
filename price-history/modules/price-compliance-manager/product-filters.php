<?php

namespace EGGL\PriceHistory\Modules\PriceComplianceManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for which products match a given search/type/category/status filter set,
 * shared by the Bulk Editor and (from Phase 4) CSV Export so both always resolve the same product set.
 */
class Product_Filters
{
    public static function sanitize($input)
    {
        $text = static function ($key) use ($input) {
            return isset($input[$key]) && is_scalar($input[$key]) ? sanitize_text_field(wp_unslash((string) $input[$key])) : '';
        };
        return [
            's'        => $text('s'),
            'type'     => in_array($text('type'), ['simple', 'variable'], true) ? $text('type') : 'all',
            'category' => absint($text('category')),
            'status'   => in_array($text('status'), ['set', 'unset'], true) ? $text('status') : 'all',
        ];
    }

    /** WHERE fragment for search/type/category against `{$wpdb->posts} p LEFT JOIN wc_product_meta_lookup l`. */
    public static function where($filters)
    {
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        $where  = "p.post_type = 'product' AND p.post_status IN ('publish','private','draft','pending','future')";
        $types  = $filters['type'] === 'all' ? ['simple', 'variable'] : [$filters['type']];
        $where .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug IN (" . implode(',', array_fill(0, count($types), '%s')) . '))', $types);
        if ($filters['category']) {
            $children   = get_term_children($filters['category'], 'product_cat');
            $categories = array_merge([$filters['category']], is_wp_error($children) ? [] : $children);
            $where .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} cr JOIN {$wpdb->term_taxonomy} ct ON ct.term_taxonomy_id = cr.term_taxonomy_id WHERE cr.object_id = p.ID AND ct.taxonomy = 'product_cat' AND ct.term_id IN (" . implode(',', array_fill(0, count($categories), '%d')) . '))', $categories);
        }
        if ($filters['s'] !== '') {
            $search = '%' . $wpdb->esc_like($filters['s']) . '%';
            $where .= $wpdb->prepare(" AND (p.post_title LIKE %s OR l.sku LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->posts} v LEFT JOIN %i vl ON vl.product_id = v.ID WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status IN ('publish','private','draft') AND (v.post_title LIKE %s OR vl.sku LIKE %s)))", $search, $search, $lookup, $search, $search);
        }
        return $where;
    }

    /** The selected module's prepared "is set" SQL boolean, or null if it doesn't support status filtering. */
    public static function status_condition($module)
    {
        return $module && !empty($module['set_condition']) ? call_user_func($module['set_condition']) : null;
    }
}
