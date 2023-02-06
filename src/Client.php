<?php

declare(strict_types=1);

namespace Yicang\Config;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Utils\Parallel;
use RuntimeException;

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
    }

    public function getOption(): Option
    {
        return $this->option;
    }

    public function pull(): array
    {
        $namespaces = $this->config->get('config_center.drivers.ecconfig.namespaces');
        $option = $this->getOption();
        $parallel = new Parallel();
        $httpClientFactory = $this->httpClientFactory;
        foreach ($namespaces as $namespace) {
            $parallel->add(function () use ($option, $httpClientFactory, $namespace) {
                $client = $httpClientFactory();
                if (!$client instanceof \GuzzleHttp\Client) {
                    throw new RuntimeException('Invalid http client.');
                }
                $cacheKey = $option->buildCacheKey($namespace);
                $lastRefreshKey = $this->getRefreshKey($cacheKey);
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
                    $result = $refreshKey == $lastRefreshKey ? ($this->cache[$cacheKey]['configurations'] ?? []) : $body['configurations'] ?? [];
                    if ($refreshKey != $lastRefreshKey) {
                        $this->cache[$cacheKey] = [
                            'refreshKey' => (string)$refreshKey,
                            'configurations' => $result,
                        ];
                    }
                } else {
                    // Status code is 304 or Connection Failed, use the previous config value
                    $result = $this->cache[$cacheKey]['configurations'] ?? [];
                    if ($response->getStatusCode() !== 304) {
                        $this->logger->error('Connect to Ecconfig server failed');
                    }
                }
                return $result;
            }, $namespace);
        }
        return $parallel->wait();
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
