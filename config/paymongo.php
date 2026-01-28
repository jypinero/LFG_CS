<?php
return [
    'public_key'    => env('PAYMONGO_PUBLIC_KEY'),
    'secret' => env('PAYMONGO_SECRET_KEY'),
    'base_url'      => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
    'webhook_secret'=> env('PAYMONGO_WEBHOOK_SECRET'),
];