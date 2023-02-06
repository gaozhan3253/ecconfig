# ecconfig
ecconfig


config/autoload/config_center.php


<?php

declare(strict_types=1);


use Hyperf\ConfigCenter\Mode;

return [
    'enable' => (bool)env('CONFIG_CENTER_ENABLE', true),
    'driver' => env('CONFIG_CENTER_DRIVER', 'apollo'),
    'mode' => env('CONFIG_CENTER_MODE', Mode::PROCESS),
    'drivers' => [
        'ecconfig' => [
            'driver' => Yicang\Config\ConfigDriver::class,
            'server' => env('ECCONFIG_CONFIG_URL', 'http://127.0.0.1:8080/api/config/http'),
            'appid' => env('ECCONFIG_APPID', 'test'),
            'secret' => env('ECCONFIG_SECRET', 'test'),
            'cluster' => env('ECCONFIG_CLUSTER', 'cn-shenzhen'),
            'namespaces' => explode(',', env('ECCONFIG_NAMESPACES', 'databases,redis')),
            'interval' => 60,
            'strict_mode' => true,
            'client_ip' => current(swoole_get_local_ip()),
            'pullTimeout' => 120,
            'interval_timeout' => 1,
        ]
    ],
];
