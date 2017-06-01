<?php

namespace Silksh\BigLogBundle\Helper;

use PDO;

class DictionaryTableExtractor
{

    const FROM_CACHE = 1;
    const FROM_DB = 2;
    const GENERATED = 3;

    private $pdo;
    private $table;
    private $cache;
    private $insert;
    private $select;

    public function __construct(PDO $pdo, $table)
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->cache = array();
    }

    private function generateId($value)
    {
        if (!$this->insert) {
            $this->insert = $this->pdo->prepare(
                "INSERT INTO {$this->table} (value) VALUES (:value);"
            );
        }
        $this->insert->bindParam(':value', $value);
        $this->insert->execute();
        return $this->pdo->lastInsertId();
    }

    private function retrieveId($value)
    {
        if (!$this->select) {
            $this->select = $this->pdo->prepare(
                "SELECT id FROM {$this->table} WHERE value = :value;"
            );
            $this->select->setFetchMode(PDO::FETCH_COLUMN, 0);
        }
        $this->select->bindParam(':value', $value);
        $this->select->execute();
        $id = $this->select->fetch();
        return $id;
    }

    public function getId($value)
    {
	$value = (string)$value;
        if (!array_key_exists($value, $this->cache)) {
            $id = $this->retrieveId($value);
            if (!$id) {
                $id = $this->generateId($value);
            }
            $this->cache[$value] = $id;
        }
        return $this->cache[$value];
    }

    public function getIdExtended($value)
    {
        if (array_key_exists($value, $this->cache)) {
            $status = self::FROM_CACHE;
        } else {
            $id = $this->retrieveId($value);
            if ($id) {
                $status = self::FROM_DB;
            } else {
                $id = $this->generateId($value);
                $status = self::GENERATED;
            }
            $this->cache[$value] = $id;
        }
        return [$this->cache[$value], $status];
    }
}
