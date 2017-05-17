<?php

namespace Silksh\BigLogBundle\Service;

use BaseBundle\Helper\DictionaryTableExtractor;
use PDO;
use Redis;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class LoggerService
{
    private $pdo;
    private $redis;
    private $redisAdapter;
    private $prefixLen;
    private $stm;
    private $pathHelper;
    private $queryHelper;
    private $redisHelper;
    private $sourceHelper;

    public function __construct(Redis $redis, RedisAdapter $redisAdapter, PDO $pdo)
    {
        $this->redis = $redis;
        $this->redisAdapter = $redisAdapter;
        $this->pdo = $pdo;
        $this->prefixLen = strlen($redis->getOption(\Redis::OPT_PREFIX));
        $this->pathHelper = new DictionaryTableExtractor($pdo, 'path');
        $this->queryHelper = new DictionaryTableExtractor($pdo, 'query');
        $this->redisHelper = new DictionaryTableExtractor($pdo, 'redis');
        $this->sourceHelper = new DictionaryTableExtractor($pdo, 'source');
    }

    private function usAsciiPercentMaker($special)
    {
        return function($string) use($special) {
            $p = explode($special, $string);
            $mapper = function($x) { return urlencode(urldecode($x)); };
            $p = array_map($mapper, $p);
            return implode($special, $p);
        };
    }

    private function usAsciiPercentMakerDouble($first, $second)
    {
        $mapper = $this->usAsciiPercentMaker($second);
        return function($string) use($first, $mapper) {
            $p = explode($first, $string);
            $p = array_map($mapper, $p);
            return implode($first, $p);
        };
    }

    private function percentUnicode($input)
    {
        $callback = function(array $m) {
            $x = reset($m);
            return urlencode($x);
        };
        return preg_replace_callback('/[\x80-\xff]/', $callback, $input);
    }

    private function doInsert(array $params)
    {
        if (!$this->stm) {
            $this->stm = $this->pdo->prepare("
               INSERT INTO log(id, path, query, source, start, duration)
               VALUES (:id, :path, :query, :source, :start, :duration)
            ");
        }
        foreach ($params as $k => $v) {
            $this->stm->bindValue(':'.$k, $v);
        }
        $this->stm->execute();
        return false;
    }

    private function processKey($key)
    {
        $item = $this->redisAdapter->getItem($key);
        $j = $item->get();
        $d = json_decode($j, true);
        if (!$d) {
            return true;
        }
        $redisStatus = $this->redisHelper->getIdExtended($key);
        if ($redisStatus[1] != DictionaryTableExtractor::NEW) {
            /* Record exists. Nothing to do. */
            return false;
        }
        $params = array();
        $path = parse_url($d['uri'], \PHP_URL_PATH);
        $path = $this->percentUnicode($path);
        $params['path'] = $this->pathHelper->getId($path);
        $query = parse_url($d['uri'], \PHP_URL_QUERY);
        $query = $this->percentUnicode($query);
        $params['query'] = $this->queryHelper->getId($query);
        $params['source'] = $this->sourceHelper->getId($d['ip']);
        $params['id'] = $redisStatus[0];
        $params['duration'] = $d['stop'] - $d['start'];
        $startObj = new \DateTime('@'.(int)$d['start']);
        $params['start'] = $startObj->format('Y-m-d H:i:s');
        return $this->doInsert($params);
    }

    public function importFromRedis($max, callable $logger)
    {
        $keys = $this->redis->getKeys('http_access_*');
        $all = count($keys);
        if ($all > $max) {
            $processing = $max;
            $keys = array_slice($keys, 0, $processing);
        } else {
            $processing = $all;
        }
        $msg = "All keys: $all; Processing: $processing";
        $logger($msg);
        $this->pdo->beginTransaction();
        $todel = array();
        foreach ($keys as $idx => $key) {
            /* The keys are returned with prefixes. */
            $internalKey = substr($key, $this->prefixLen);
            $error = $this->processKey($internalKey);
            if ($error) {
                $msg = "The key $key is broken. Ignoring.";
                $logger($msg);
                continue;
            }
            $todel[] = $internalKey;
        }
        $this->pdo->commit();
        foreach ($todel as $key) {
            $this->redis->del($key);
        }
    }
}
