<?php

declare(strict_types=1);


namespace Yicang\Config;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use Psr\Container\ContainerInterface;

class ClientFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->get(ConfigInterface::class);
        /** @var \Yicang\Config\Option $option */
        $option = make(Option::class);
        $option->setServer($config->get('config_center.drivers.ecconfig.server', 'http://127.0.0.1:8080/api/config/http'))
            ->setAppid($config->get('config_center.drivers.ecconfig.appid', ''))
            ->setSecret($config->get('config_center.drivers.ecconfig.secret', ''))
            ->setCluster($config->get('config_center.drivers.ecconfig.cluster', ''))
            ->setClientIp($config->get('config_center.drivers.ecconfig.client_ip', current(swoole_get_local_ip())))
            ->setPullTimeout($config->get('config_center.drivers.ecconfig.pull_timeout', 10))
            ->setIntervalTimeout($config->get('config_center.drivers.ecconfig.interval_timeout', 60));

        $httpClientFactory = function (array $options = []) use ($container) {
            return $container->get(GuzzleClientFactory::class)->create($options);
        };
        return make(Client::class, compact('option', 'httpClientFactory'));
    }
}
