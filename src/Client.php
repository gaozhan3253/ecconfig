<?php

declare(strict_types=1);

namespace Yicang\Config;

use App\Utility\Tools;
use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Utils\Parallel;
use Lysice\HyperfRedisLock\RedisLock;
use Lysice\HyperfRedisLock\LockTimeoutException;
use RuntimeException;
use Hyperf\Redis\RedisFactory;
use Hyperf\Utils\ApplicationContext;

class Client implements ClientInterface
{
    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var Option
     */
    protected $option;

    /**
     * @var array
     */
    protected $cache = [];

    /**
     * @var Closure
     */
    protected $httpClientFactory;

    /**
     * @var \Hyperf\Contract\StdoutLoggerInterface
     */
    protected $logger;

    /**
     * redis
     * @var \Redis
     */
    protected $redis;

    /**
     * 持久化配置标识
     * @var mixed
     */
    protected $persistenceConfigurationsKey;

    /**
     * 最新持久化配置标识
     * @var mixed
     */
    protected $lastPersistenceConfigurationsKey;

    public function __construct(
        Option $option,
        Closure $httpClientFactory,
        ConfigInterface $config,
        StdoutLoggerInterface $logger
    )
    {
        $this->option = $option;
        $this->httpClientFactory = $httpClientFactory;
        $this->config = $config;
        $this->logger = $logger;
        $this->redis = ApplicationContext::getContainer()->get(RedisFactory::class)->get('default');
        $this->lastPersistenceConfigurationsKey = $this->config->get('config_center.drivers.ecconfig.persistence_configuration_last_data_key', 'ecconfig_persistence_configuration_last_data');
        $this->persistenceConfigurationsKey = $this->config->get('config_center.drivers.ecconfig.persistence_configuration_data_key', 'ecconfig_persistence_configuration_data');
    }

    public function getOption(): Option
    {
        return $this->option;
    }

    public function pull(): array
    {
        $namespaces = $this->config->get('config_center.drivers.ecconfig.namespaces');
        $pullTimeout = $this->config->get('config_center.drivers.ecconfig.pullTimeout');
        $interval = $this->config->get('config_center.drivers.ecconfig.interval');
        $option = $this->getOption();
        $parallel = new Parallel();
        $httpClientFactory = $this->httpClientFactory;
        foreach ($namespaces as $namespace) {
            $parallel->add(function () use ($option, $httpClientFactory, $namespace, $interval, $pullTimeout) {
                $cacheKey = $option->buildCacheKey($namespace);
                $lastRefreshKey = $this->getRefreshKey($cacheKey);
                try {
                    $lock = new RedisLock($this->redis, $cacheKey . ':lock', $pullTimeout);
                    $configurations = $lock->block($pullTimeout, function () use ($option, $httpClientFactory, $namespace, $interval, $cacheKey, $lastRefreshKey) {
                        if ($lastConfigurations = $this->getLastPersistenceConfigurations($cacheKey)) {
                            return $lastConfigurations;
                        }
                        $client = $httpClientFactory();
                        if (!$client instanceof \GuzzleHttp\Client) {
                            throw new RuntimeException('Invalid http client.');
                        }

                        $query = [
                            'ip' => $option->getClientIp(),
                            'cluster' => $option->getCluster(),
                            'namespace' => $namespace,
                            'version' => $option->getVersion(),
                            'refreshKey' => $lastRefreshKey,
                        ];
                        $timestamp = $this->getTimestamp();
                        $headers = [
                            'Authorization' => $this->getAuthorization($timestamp, http_build_query($query)),
                            'Timestamp' => $timestamp,
                        ];

                        $response = $client->get($option->buildBaseUrl(), [
                            'query' => $query,
                            'headers' => $headers,
                        ]);

                        if ($response->getStatusCode() === 200 && strpos($response->getHeaderLine('Content-Type'), 'application/json') !== false) {
                            $body = json_decode((string)$response->getBody(), true);
                            $refreshKey = $body['refreshKey'] ?? '';
                            $lastConfigurations = $refreshKey == $lastRefreshKey ? ($this->cache[$cacheKey]['configurations'] ?? []) : $body['configurations'] ?? [];
                            if ($refreshKey != $lastRefreshKey) {
                                $this->cache[$cacheKey] = [
                                    'refreshKey' => (string)$refreshKey,
                                    'configurations' => $lastConfigurations,
                                ];
                            }
                            $this->setLastPersistenceConfigurations($cacheKey, $lastConfigurations, (int)$interval);
                            $this->setPersistenceConfigurations($cacheKey, $lastConfigurations);
                        } else {
                            // Status code is 304 or Connection Failed, use the previous config value
                            $lastConfigurations = $this->cache[$cacheKey]['configurations'] ?? [];
                            if ($response->getStatusCode() !== 304) {
                                $this->logger->error('Connect to Ecconfig server failed');
                            }
                        }
                        return $lastConfigurations;
                    });
                } catch (LockTimeoutException $exception) {
                    $this->logger->error('Connect to Ecconfig server failed' . $exception->getMessage());
                    //获取持久化配置
                    $configurations = $this->getPersistenceConfigurations($cacheKey);
                }
                return $configurations;
            }, $namespace);
        }
        return $parallel->wait();
    }

    protected function setLastPersistenceConfigurations(string $cacheKey, array $configurations = [], int $timeout = 120)
    {
        return $this->redis->set($this->lastPersistenceConfigurationsKey . '_' . $cacheKey, json_encode($configurations), $timeout);
    }

    protected function getLastPersistenceConfigurations(string $cacheKey, array $default = [])
    {
        $cache = $this->redis->get($this->lastPersistenceConfigurationsKey . '_' . $cacheKey);
        $cache = $cache ? json_decode($cache, true) : [];
        return $cache ? $cache : $default;
    }

    protected function setPersistenceConfigurations(string $cacheKey, array $configurations = [])
    {
        return $this->redis->set($this->persistenceConfigurationsKey . '_' . $cacheKey, json_encode($configurations));
    }

    protected function getPersistenceConfigurations(string $cacheKey, array $default = [])
    {
        $cache = $this->redis->get($this->persistenceConfigurationsKey . '_' . $cacheKey);
        $cache = $cache ? json_decode($cache, true) : [];
        return $cache ? $cache : $default;
    }

    protected function getRefreshKey(string $cacheKey): ?string
    {
        return $this->cache[$cacheKey]['refreshKey'] ?? '';
    }

    private function hasSecret(): bool
    {
        return !empty($this->option->getSecret());
    }

    private function getTimestamp(): string
    {
        [$usec, $sec] = explode(' ', microtime());
        return sprintf('%.0f', (floatval($usec) + floatval($sec)) * 1000);
    }

    private function getAuthorization(string $timestamp, string $pathWithQuery): string
    {
        if (!$this->hasSecret()) {
            return '';
        }
        $toSignature = $timestamp . "\n" . $pathWithQuery;
        $signature = base64_encode(hash_hmac('sha1', $toSignature, $this->option->getSecret(), true));
        return sprintf('%s:%s', $this->option->getAppid(), $signature);
    }
}
