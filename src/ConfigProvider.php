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
                \Hyperf\DbConnection\Pool\PoolFactory::class => \Yicang\Config\Factory\Db\PoolFactory::class, //替代db的PoolFactory
                \Hyperf\Redis\RedisFactory::class => \Yicang\Config\Factory\Redis\RedisFactory::class, //替代redis的RedisFactory
            ],
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        \Hyperf\Redis\Pool\PoolFactory::class => __DIR__ . '/Factory/Redis/PoolFactory.php',  //用class_map处理redis的PoolFactory
                    ],
                ],
            ],
        ];
    }
}
