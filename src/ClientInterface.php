<?php

declare(strict_types=1);

namespace Yicang\Config;


interface ClientInterface extends \Hyperf\ConfigCenter\Contract\ClientInterface
{
    public function getOption(): Option;

}
