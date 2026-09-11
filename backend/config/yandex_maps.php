<?php

return [

    'base_url' => env('YANDEX_MAPS_BASE_URL', 'https://yandex.ru'),

    'timeout' => env('YANDEX_MAPS_TIMEOUT', 10),

    'reviews_page_size' => env('YANDEX_MAPS_REVIEWS_PAGE_SIZE', 50),

    'max_reviews' => env('YANDEX_MAPS_MAX_REVIEWS', 600),

    'throttle_min_seconds' => env('YANDEX_MAPS_THROTTLE_MIN', 1.0),

    'throttle_max_seconds' => env('YANDEX_MAPS_THROTTLE_MAX', 3.0),

    'user_agents' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ],

];
