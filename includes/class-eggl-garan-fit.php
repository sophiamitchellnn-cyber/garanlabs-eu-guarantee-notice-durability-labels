<?php
if (!defined('ABSPATH')) exit;

/**
 * Measures the GARAN label's three editable fields against the space the
 * official artwork (assets/official/garan-full.svg) leaves for them, using
 * the bundled Inter fonts' own advance widths (assets/fonts/inter-metrics.json,
 * generated from Inter-Regular.ttf / Inter-ExtraBold.ttf). Kerning is ignored,
 * which slightly over-estimates widths — the safe direction.
 *
 * Used to warn merchants in the product editor, and by garan_svg_markup() to
 * compress any text that would otherwise run into the QR code or next field.
 */
class EGGL_Garan_Fit {

    /** Available widths in SVG user units — the same boxes the email renderer fits text into. */
    const BRAND_WIDTH = 186.43; // x=6.32 up to 4 units before the model column (x=196.75)
    const MODEL_WIDTH = 66.22;  // x=196.75 up to the divider line's right end (x=262.97)
    const YEARS_WIDTH = 112.4;  // x=5.07 up to 4 units before the calendar icon (x=121.47)

    const FIELD_SIZE = 9;       // Inter Regular, 9px (.cls-5)
    const YEARS_SIZE = 80;      // Inter ExtraBold, 80px (.cls-3)
    const YEARS_TRACKING = -0.03; // .cls-3 letter-spacing, in em

    /** Slack in SVG units before text counts as too wide — kerning is ignored, so measured widths run slightly high (the artwork's own "Model identifier" placeholder measures 0.3 over). */
    const TOLERANCE = 1.0;

    /** True if $actual (SVG units) overflows $available, allowing for TOLERANCE. */
    public static function overflows($actual, $available) {
        return $actual > $available + self::TOLERANCE;
    }

    private static $metrics = null;

    public static function metrics() {
        if (self::$metrics === null) {
            self::$metrics = ['units_per_em' => 2048, '400' => [], '800' => []];
            $path = EGGL_PATH . 'assets/fonts/inter-metrics.json';
            if (is_readable($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (is_array($decoded)) self::$metrics = $decoded;
            }
        }
        return self::$metrics;
    }

    /** Rendered width of $text in SVG units. Characters missing from the table count as 0.6em. */
    public static function width($text, $weight, $size, $tracking_em = 0.0) {
        $m     = self::metrics();
        $upm   = (float) ($m['units_per_em'] ?: 2048);
        $table = isset($m[$weight]) ? $m[$weight] : [];
        $chars = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars) return 0.0;

        $units = 0.0;
        foreach ($chars as $char) {
            $cp = function_exists('mb_ord') ? mb_ord($char, 'UTF-8') : 0;
            $units += isset($table[(string) $cp]) ? $table[(string) $cp] : 0.6 * $upm;
        }
        return $units / $upm * $size + (count($chars) - 1) * $tracking_em * $size;
    }

    public static function brand_width($brand) {
        return self::width($brand, '400', self::FIELD_SIZE);
    }

    public static function model_width($model) {
        return self::width($model, '400', self::FIELD_SIZE);
    }

    public static function years_width($years) {
        return self::width((string) $years, '800', self::YEARS_SIZE, self::YEARS_TRACKING);
    }

    /**
     * Human-readable problems for these label values, or [] if everything fits.
     * $model_from_name flags that the model is the product-name fallback, so the
     * message can say to fill in the Model identifier field instead.
     */
    public static function problems($brand, $model, $years, $model_from_name = false) {
        $problems = [];
        if ($brand !== '' && self::overflows(self::brand_width($brand), self::BRAND_WIDTH)) {
            /* translators: %s: the brand text */
            $problems[] = sprintf(__('Brand/Trademark "%s" is too wide for the label and will be squeezed to fit — shorten it if you can.', 'garanlabs-eu-guarantee-notice-durability-labels'), $brand);
        }
        if ($model !== '' && self::overflows(self::model_width($model), self::MODEL_WIDTH)) {
            $problems[] = $model_from_name
                /* translators: %s: the product name used as the model identifier */
                ? sprintf(__('No Model identifier is set, so the product name "%s" is used — it is too wide for the label and will be squeezed. Enter a short Model identifier instead.', 'garanlabs-eu-guarantee-notice-durability-labels'), $model)
                /* translators: %s: the model identifier text */
                : sprintf(__('Model identifier "%s" is too wide for the label and will be squeezed to fit — shorten it if you can.', 'garanlabs-eu-guarantee-notice-durability-labels'), $model);
        }
        if ($years !== '' && self::overflows(self::years_width($years), self::YEARS_WIDTH)) {
            /* translators: %s: the duration in years */
            $problems[] = sprintf(__('Duration "%s" has too many digits for the label.', 'garanlabs-eu-guarantee-notice-durability-labels'), $years);
        }
        return $problems;
    }

    /** SVG attributes that compress $text into $available units when it's too wide, else ''. */
    public static function squeeze_attrs($actual, $available) {
        if (!self::overflows($actual, $available)) return '';
        return ' textLength="' . esc_attr(number_format($available, 2, '.', '')) . '" lengthAdjust="spacingAndGlyphs"';
    }
}
