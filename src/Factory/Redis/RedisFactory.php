<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Yicang\Config\Factory\Redis;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Exception\InvalidRedisProxyException;
use Hyperf\Redis\RedisProxy;

class RedisFactory
{
    /**
     * @var RedisProxy[]
     */
    protected $proxies;

    /**
     * @var ConfigInterface
     */
    protected $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
        $redisConfig = $config->get('redis');

        foreach ($redisConfig as $poolName => $item) {
            $this->proxies[$poolName] = make(RedisProxy::class, ['pool' => $poolName]);
        }
    }

    /**
     * @return RedisProxy
     */
    public function get(string $poolName)
    {
        $proxy = $this->proxies[$poolName] ?? null;
        if (!$proxy || !$proxy instanceof RedisProxy) {
            if ($this->config->has('redis.' . $poolName)) {
                $proxy = $this->proxies[$poolName] = make(RedisProxy::class, ['pool' => $poolName]);
            }
        }
        if (!$proxy instanceof RedisProxy) {
            throw new InvalidRedisProxyException('Invalid Redis proxy.');
        }

        return $proxy;
    }
}
