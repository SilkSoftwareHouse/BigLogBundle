<?php

namespace Silksh\BigLogBundle\Service\Factory;

use LogicException;
use PDO;
use Redis;
use RuntimeException;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class StorageFactory
{
    static function createPDO(array $params)
    {
        switch ($params['driver']) {
            case 'pdo_mysql':
                $dnsp = array();
                foreach (['host', 'port', 'dbname'] as $x) {
                    if ($params[$x]) {
                        $dnsp[] = $x.'='.$params[$x];
                    }
                }
                $dns = 'mysql:'.implode(';',$dnsp);
                $pdo = new PDO($dns, $params['user'], $params['password']);
                $pdo->exec("USE `{$params['schema']}`;");
                break;
            default:
                $msg = 'The driver '.$params['driver'].' is not supported.';
                throw new LogicException($msg);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    static function createRedis($url, $namespace = null)
    {
        preg_match('#^redis\:\/\/([^\:]+)(\:(\d+))?#', $url, $m);
        if (!$m) {
            $msg = "The url $url is broken.";
            return new RuntimeException($msg);
        }
        $host = $m[1];
        $port = isset($m[3]) ? $m[3] : null;
        $redis = new Redis();
        $redis->connect($host, $port);
        $errors = $redis->getLastError();
        if ($errors) {
            $msg = 'Cannot create Redis.';
            throw new RuntimeException($msg);
        }
        $redis->setOption(Redis::OPT_PREFIX, $namespace);
        return $redis;
    }

    static function createRedisAdapter($url, $namespace = null)
    {
        /* Fall back if there is no Redis server. */
        if (preg_match('/^filesystem\:\/\/([a-zA-Z0-9\_\.]*)$/', $url, $m)) {
            if ($m[1]) {
                $namespace .= $m[1];
            }
            return new FilesystemAdapter($namespace);
        }
        $connection = RedisAdapter::createConnection($url);
        $redis = new RedisAdapter($connection, (string)$namespace);
        return $redis;
    }
}
