<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Advertising
    |--------------------------------------------------------------------------
    |
    | Advertising is deliberately opt-in. This keeps local, staging, and newly
    | deployed environments free from third-party ad requests until the
    | publisher ID and each placement have been explicitly configured.
    |
    */

    'enabled' => env('ADVERTISING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | AdSense site review
    |--------------------------------------------------------------------------
    |
    | Enable this only while the site is being reviewed by AdSense. It exposes
    | the publisher script and ads.txt without rendering any ad units.
    |
    */

    'verification_enabled' => env('ADSENSE_VERIFICATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Development ad placeholder
    |--------------------------------------------------------------------------
    |
    | This renders a local placeholder in the same locations as manual ads,
    | without loading the AdSense script or making a third-party ad request.
    | Enable it only in a development environment when checking the layout.
    |
    */

    'test_mode' => env('ADVERTISING_TEST_MODE', false),

    'adsense' => [
        'client' => env('ADSENSE_CLIENT'),

        'slots' => [
            'home_footer' => env('ADSENSE_SLOT_HOME_FOOTER'),
            'parking_spot_footer' => env('ADSENSE_SLOT_PARKING_SPOT_FOOTER'),
            'search_footer' => env('ADSENSE_SLOT_SEARCH_FOOTER'),
        ],
    ],
];
