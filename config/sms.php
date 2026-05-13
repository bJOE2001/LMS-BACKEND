<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SMS Gateway
    |--------------------------------------------------------------------------
    |
    | Leave approval SMS notifications are optional and can be toggled via
    | environment variables. Set SMS_ENABLED=true and provide the gateway URL.
    |
    */
    'enabled' => (bool) env('SMS_ENABLED', false),
    'gateway_url' => env('SMS_GATEWAY_URL'),
    'timeout_seconds' => (int) env('SMS_TIMEOUT', 8),
];
