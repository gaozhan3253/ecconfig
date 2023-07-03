<?php

declare(strict_types=1);

namespace Yicang\Config;

use Hyperf\ConfigCenter\AbstractDriver;
use Hyperf\Engine\Channel;
use Hyperf\Utils\Coordinator\Constants;
use Hyperf\Utils\Coordinator\CoordinatorManager;
use Hyperf\Utils\Coroutine;
use Psr\Container\ContainerInterface;
use Hyperf\Utils\ApplicationContext;
use Hyperf\ConfigCenter\Contract\PipeMessageInterface;
use Hyperf\Process\ProcessCollector;
use Hyperf\ConfigCenter\Contract\ClientInterface as ConfigClientInterface;


class ConfigDriver extends AbstractDriver
{
    /**
     * @var Client
     */
    protected ConfigClientInterface $client;

    protected string $driverName = 'ecconfig';

    protected $configTemplates = [];

    protected $namespacePoolFactory = array(
        'databases' => \Hyperf\DbConnection\Pool\PoolFactory::class,
        'redis' => \Hyperf\Redis\Pool\PoolFactory::class,
    );

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->client = $container->get(ClientInterface::class);
        $this->loadConfigTemplate();
    }

    protected function loadConfigTemplate()
    {
        foreach ($this->namespacePoolFactory as $namespace => $factory) {
            $filepath = __DIR__ . '/config/' . $namespace . '.php';
            $this->configTemplates[$namespace] = file_exists($filepath) ? include $filepath : [];
        }
    }

    protected function getSupportNamespaces()
    {
        return array_keys($this->namespacePoolFactory);
    }

    public function createMessageFetcherLoop(): void
    {
        $prevConfig = [];
        $this->loop(function () use (&$prevConfig) {
            $config = $this->client->pull();
            if ($config !== $prevConfig) {
                foreach ($config as $c => $list) {
                    if (count($list) > 200) {
                        $tempConfigs = array_chunk($list, 200, true);
                        foreach ($tempConfigs as $item) {
                            $this->syncConfig([$c => $item]);
                        }
                    } else {
                        $this->syncConfig([$c => $list]);
                    }
                }
                $prevConfig = $config;
            }
        });
    }

    protected function loop(callable $callable, ?Channel $channel = null): int
    {
        return Coroutine::create(function () use ($callable, $channel) {
            $interval = $this->getInterval();
            $sleep = $interval > 60 ? 60 : $interval;
            retry(INF, function () use ($callable, $channel, $interval) {
                while (true) {
                    try {
                        $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);
                        $untilEvent = $coordinator->yield($interval);
                        if ($untilEvent) {
                            $channel && $channel->close();
                            break;
                        }
                        $callable();
                    } catch (\Throwable $exception) {
                        $this->logger->error((string)$exception);
                        throw $exception;
                    }
                }
            }, $sleep * 1000);
        });
    }

    /**
     * 配置格式化
     * @param $namespace
     * @param $value
     * @return array|bool|float|int|null|string
     */
    protected function formatValue($namespace, $value)
    {
        if (is_array($value) && isset($this->configTemplates[$namespace])) {
            $value = array_merge($this->configTemplates[$namespace], $value);
        }

        if (!$this->config->get('config_center.drivers.ecconfig.strict_mode', false) || !is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            $value = (strpos($value, '.') === false) ? (int)$value : (float)$value;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }
        return $value;
    }

    /**
     * 更新配置
     * @param array $config
     */
    protected function updateConfig(array $config): void
    {
        $mergedConfigs = [];
        foreach ($config as $c) {
            foreach ($c as $key => $value) {
                $mergedConfigs[$key] = $value;
            }
        }
        unset($config);
        foreach ($mergedConfigs ?? [] as $key => $value) {
            list($namespace, $poolName) = $this->getNamespacePoolNmae($key);
            $prevConfig = $this->config->get($key);
            $newConfigs = $this->formatValue($namespace, $value);
            if ($prevConfig !== $newConfigs) {
                $this->config->set($key, $newConfigs);
                $this->poolClear($namespace, $poolName);
                $this->logger->debug(sprintf('Config [%s] is updated', $key));
            }
        }
    }

    /**
     * 格式化命名空间和poolname
     * @param $key
     * @return array
     */
    protected function getNamespacePoolNmae($key)
    {
        $namespace = $poolName = '';
        $patten = '#(' . implode('|', $this->getSupportNamespaces()) . ')\.(.+)#is';
        if (preg_match($patten, $key, $matches)) {
            $namespace = $matches[1];
            $poolName = $matches[2];
        }
        return [$namespace, $poolName];
    }

    /**
     * 连接池随动清理
     * @param $namespace
     * @param $poolName
     */
    protected function poolClear($namespace, $poolName)
    {
        $poolFactory = $this->namespacePoolFactory[$namespace] ?? null;
        if ($poolFactory) {
            $pool = ApplicationContext::getContainer()->get((string)$poolFactory);
            if ($pool && method_exists($pool, 'clearPool')) {
                $pool->clearPool($poolName);
            }
        }
    }

    protected function shareMessageToUserProcesses(PipeMessageInterface $message): void
    {
        $processes = ProcessCollector::all();
        if ($processes) {
            $string = serialize($message);
            /** @var \Swoole\Process $process */
            foreach ($processes as $process) {
                if (posix_getpid() == $process->pid) {
                    continue;
                }
                $result = $process->exportSocket()->send($string, 10);
                if ($result === false) {
                    $this->logger->error('Configuration synchronization failed. Please restart the server.');
                }
            }
        }
    }
}
