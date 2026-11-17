<?php

namespace EGGL\PriceHistory\Modules\PriceComplianceManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Holds the Price Compliance modules (Anchor Price, and later Base Price / Custom Lowest Price)
 * that expose editable/importable/exportable data to the Price Compliance Manager.
 *
 * Each module registers via the `eggl_ph_price_compliance_manager_register` action with:
 * - label:      module display name.
 * - fields:     [field_id => ['label' => ..., 'csv_column' => ..., 'type' => 'date'|'price'|'text']].
 * - supports:   callable(\WC_Product): bool — whether this module applies to the product.
 * - version:    callable(\WC_Product): string — optimistic concurrency token for the module's data.
 * - get_values: callable(\WC_Product): array — current field values, keyed by field id.
 * - csv_get_values: optional callable(\WC_Product): array — read-only export values; defaults to get_values.
 * - validate:   callable(array $values): true|\WP_Error — format/range validation only (no product
 *   needed); checked for every change before any change in the batch is written, so one invalid row
 *   never leaves an earlier valid row half-saved.
 * - matches:    callable(\WC_Product, array $values): bool — whether the product's stored values
 *   already equal the given ones (used to skip no-op writes and to allow safe retries).
 * - set_values: callable(\WC_Product, array $values): true|false|\WP_Error — true if changed and
 *   staged (caller must still persist it), false if identical (no-op), WP_Error if invalid. Module
 *   decides how its own fields relate to each other (e.g. Anchor Price treats date+price as a pair).
 * - set_condition: optional callable(): string — prepared SQL boolean expression referencing `p.ID`
 *   for whether a product's fields are considered "set"; omit to disable the set/unset status filter.
 */
class Registry
{
    private static $modules = [];

    public static function register($id, array $module)
    {
        self::$modules[$id] = $module + ['id' => $id];
    }

    public static function all()
    {
        return self::$modules;
    }

    public static function get($id)
    {
        return self::$modules[$id] ?? null;
    }
}
