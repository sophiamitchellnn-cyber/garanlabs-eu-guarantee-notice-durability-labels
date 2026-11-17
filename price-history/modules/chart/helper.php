<?php

namespace EGGL\PriceHistory\Modules\Chart;


if (! defined('ABSPATH')) exit;


class Helper
{
    public static function is_date_in_range($entry_date, $range)
    {
        if ('all' === $range) {
            return true;
        }

        [$num, $text] = explode('_', $range);
        $period = 0;

        if ($text === 'days') {
            $period = $num * DAY_IN_SECONDS;
        } elseif ($text === 'months') {
            $period = $num * 30 * DAY_IN_SECONDS;
        }

        $now = current_time('timestamp');
        $past = $now - $period;
        $entry_time = strtotime($entry_date);

        return ($entry_time >= $past && $entry_time <= $now);
    }
}
