<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GST rate options (%)
    |--------------------------------------------------------------------------
    |
    | Used by purchase orders, client invoices, and related forms. Extend this
    | list when the business needs additional statutory rates. Values are
    | stored as decimal percentages (e.g. 18.00).
    |
    */
    'gst_rates' => array_values(array_unique(array_map(
        static fn ($rate) => number_format((float) $rate, 2, '.', ''),
        explode(',', (string) env('TAX_GST_RATES', '0,5,12,18,28'))
    ))),
];
