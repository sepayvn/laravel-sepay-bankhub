<?php

return [
    'api_key' => env('SEPAY_BANKHUB_API_KEY'),
    'api_secret' => env('SEPAY_BANKHUB_API_SECRET'),
    'api_url' => env('SEPAY_BANKHUB_API_URL', 'https://partner-api.sepay.vn/merchant/v1'),
    'ipn_token' => env('SEPAY_BANKHUB_IPN_TOKEN'),
];
