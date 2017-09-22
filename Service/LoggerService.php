<?php

namespace Silksh\BigLogBundle\Service;

use PDO;
use Redis;
use Silksh\BigLogBundle\Helper\DictionaryTableExtractor;
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
    private $agentHelper;
    private $metaHelper;

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
        $this->agentHelper = new DictionaryTableExtractor($pdo, 'agent');
        $this->metaHelper = new DictionaryTableExtractor($pdo, 'meta');
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
               INSERT INTO log(id, path, query, source, agent, start, duration, meta)
               VALUES (:id, :path, :query, :source, :agent, :start, :duration, :meta)
            ");
        }
        foreach ($params as $k => $v) {
            $this->stm->bindValue(':'.$k, $v);
        }
        $this->stm->execute();
        return false;
    }

    private function normaliseKeys(&$d)
    {
        if (!array_key_exists('agent', $d)) {
            $d['agent'] = '';
        }
        $common = array_flip(['id', 'ip', 'uri', 'agent', 'stop', 'start']);
        $rest = array_diff_key($d, $common);
        ksort($rest);
        $d = array_intersect_key($d, $common);
        $d['meta'] = json_encode((array)$rest);
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
        if ($redisStatus[1] != DictionaryTableExtractor::GENERATED) {
            /* Record exists. Nothing to do. */
            return false;
        }
        $d['id'] = $redisStatus[0];
        $this->processRecord($d);
    }

    private function processRecord(array $d)
    {
        $this->normaliseKeys($d);
        $params = array();
        $path = parse_url($d['uri'], \PHP_URL_PATH);
        $path = $this->percentUnicode($path);
        $params['path'] = $this->pathHelper->getId($path);
        $query = parse_url($d['uri'], \PHP_URL_QUERY);
        $query = $this->percentUnicode($query);
        if (strlen($query) > 250) {
            $query = substr($query, 0, 250) . '...';
        }
        $params['query'] = $this->queryHelper->getId($query);
        $params['source'] = $this->sourceHelper->getId($d['ip']);
        $params['agent'] = $this->agentHelper->getId($d['agent']);
        $params['id'] = $d['id'];
        $params['duration'] = $d['stop'] - $d['start'];
        $startObj = new \DateTime('@'.(int)$d['start']);
        $params['start'] = $startObj->format('Y-m-d H:i:s');
        $params['meta'] = $this->metaHelper->getId($d['meta']);
        return $this->doInsert($params);
    }

    public function importFromArray(array $input, callable $logger)
    {
        $processing = count($input);
        $msg = "Processing: $processing";
        $logger($msg);
        $this->pdo->beginTransaction();
        foreach ($input as $d) {
            $d['id'] = $this->redisHelper->getId($d['id']);
            $this->processRecord($d);
        }
        $this->pdo->commit();
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
