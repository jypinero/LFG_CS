<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push Notification Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Web Push Notifications using VAPID keys.
    | VAPID (Voluntary Application Server Identification) keys are used
    | to identify your application server to push services.
    |
    */

    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'email' => env('VAPID_EMAIL', 'mailto:admin@example.com'),
    ],

];


