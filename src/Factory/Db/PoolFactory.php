<?php

declare(strict_types=1);

namespace Hyperf\DbConnection\Pool;


use Hyperf\Di\Container;
use Hyperf\DbConnection\Pool\DbPool;
use Psr\Container\ContainerInterface;

class PoolFactory
{
    /**
     * @var array<string, DbPool>
     */
    protected array $pools = [];

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function getPool(string $name): DbPool
    {
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }

        if ($this->container instanceof Container) {
            $pool = $this->container->make(DbPool::class, ['name' => $name]);
        } else {
            $pool = new DbPool($this->container, $name);
        }

        return $this->pools[$name] = $pool;
    }

    public function clearPool(string $name)
    {
        if (isset($this->pools[$name])) {
            $this->pools[$name]->flush();
            $this->pools[$name]->flushOne(true);
        }
        unset($this->pools[$name]);
    }
}
