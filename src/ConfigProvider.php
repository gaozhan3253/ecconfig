<?php

declare(strict_types=1);

namespace Yicang\Config;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ClientInterface::class => ClientFactory::class,
            ],
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        \Hyperf\DbConnection\Pool\PoolFactory::class => __DIR__ . '/Factory/Db/PoolFactory.php',  //用class_map处理redis的PoolFactory
                        \Hyperf\Redis\Pool\PoolFactory::class => __DIR__ . '/Factory/Redis/PoolFactory.php',  //用class_map处理redis的PoolFactory
                    ],
                ],
            ],
        ];
    }
}
