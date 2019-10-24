<?php
declare(strict_types=1);

namespace OpstopsPw;


use PDO;
use PDOStatement;
use PDOException;


class Database extends PDO
{
    /**
     * @var array
     */
    protected static $instances = array();

    /**
     * @var array
     */
    protected $params;

    /**
     * @param array $opt
     */
    protected function setParams(array $opt):void
    {
        $this->params = $opt;
    }

    /**
     * @return array
     */
    public function getParams():array
    {
        return $this->params;
    }

    /**
     * get instance
     * 
     * @param array $params
     * @return Database
     */
    public static function get(array $params):Database
    {
        $defOpt = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false, // true: requests are created by PDO and sent ready
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ, // PDO::FETCH_ASSOC,..
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8 COLLATE utf8_unicode_ci"
        ];

        if (!array_key_exists('options', $params)) {
            $params['options'] = [];
        }

        $pdoOptions =  array_replace($defOpt, $params['options']);

        $id = md5(implode('.', [$params['dsn'], $params['username']]));

        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }

        $instance = new Database(
            $params['dsn'], // Example: 'mysql:host=127.0.0.1;dbname=db;charset=utf8'
            $params['username'],
            $params['password'],
            $pdoOptions
        );

        $instance->setParams($params);

        self::$instances[$id] = $instance;

        return $instance;
    }


    /**
     * @param PDOStatement $stmt
     * @param array $params
     * @return PDOStatement
     */
    protected function bindValueFromArr(PDOStatement $stmt , array $params): PDOStatement
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue("$key", $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue("$key", $value);
            }
        }

        return $stmt;
    }

    /**
     * @param QueryBuilder $sql
     * @param $input
     * @param $where
     * @return PDOStatement
     */
    protected function prepareBindFromObj(string $sql, array $input, array $where):PDOStatement
    {
        $stmt = $this->prepare($sql);

        if ($input) {
            $this->bindValueFromArr($stmt, $input);
        }

        if ($where) {
            $this->bindValueFromArr($stmt, $where);
        }

        return $stmt;
//        var_dump($stmt, $t); exit;
    }


    /**
     * @param string|null $className
     */
    protected function checkClass(string $className = null):void
    {
        if ($className && !class_exists($className)) { // RowInterface
            throw new PDOException("Class {$className} not exists");
        }
    }

    /**
     * @param QueryBuilder $query
     * @param RowInterface|null $rowClass
     * @param int|null $fetchMode
     * @return mixed
     */
    public function findRow(QueryBuilder $query, RowInterface $rowClass = null, int $fetchMode = null)
    {
        $qb = $query->build();
        $stmt = $this->prepareBindFromObj($qb->sql, $qb->input, $qb->where);

        $stmt->execute();

        if (!is_null($fetchMode)) {
            return $stmt->fetch($fetchMode);
        } else {
            return is_null($rowClass) ? $stmt->fetch() : $stmt->fetch(PDO::FETCH_CLASS , $rowClass);
        }
    }

    /**
     * @param QueryBuilder $query
     * @param string|null $rowClass
     * @param int|null $fetchMode   PDO::FETCH_OBJ, ...
     * @return array
     */
    public function findAll(QueryBuilder $query, string $rowClass = null, int $fetchMode = null)
    {
        $this->checkClass($rowClass);

        $qb = $query->build();
        $stmt = $this->prepareBindFromObj($qb->sql, $qb->input, $qb->where);

        $stmt->execute();

        if (!is_null($fetchMode)) {
            return $stmt->fetchAll($fetchMode);
        } else {
            return is_null($rowClass) ? $stmt->fetchAll() : $stmt->fetchAll(PDO::FETCH_CLASS , $rowClass);
        }
    }

    /**
     * @param QueryBuilder $query
     * @param string|null $rowClass
     * @return array
     */
    public function findCol(QueryBuilder $query, string $rowClass = null)
    {
        $this->checkClass($rowClass);
        return $this->findAll($query, $rowClass, PDO::FETCH_COLUMN);
    }

    /**
     * @param QueryBuilder $query
     * @param string|null $rowClass
     * @return array
     */
    public function findAssoc(QueryBuilder $query, string $rowClass = null)
    {
        return $this->findAll($query, $rowClass, PDO::FETCH_KEY_PAIR);
    }

    /**
     * @param QueryBuilder $query
     * @param string|null $rowClass
     * @return mixed
     */
    public function findOne(QueryBuilder $query, string $rowClass = null)
    {
        $qb = $query->build();
        $stmt = $this->prepareBindFromObj($qb->sql, $qb->input, $qb->where);

        $stmt->execute();
        return is_null($rowClass) ? $stmt->fetchColumn() : $stmt->fetchColumn(PDO::FETCH_CLASS , $rowClass);
    }

    /**
     * @param QueryBuilder $query
     * @param string $column
     * @return mixed
     */
    public function findCount(QueryBuilder $query, string $column = '*')
    {
        $query->toCount($column);
        return $this->findOne($query);
    }

    /**
     * If insert return lastInsertId, else number of rows affected (0 if not changed or not found)
     *
     * @param QueryBuilder $query
     * @return PDOStatement
     */
    public function run(QueryBuilder $query)
    {
        $qb = $query->build();
        $stmt = $this->prepareBindFromObj($qb->sql, $qb->input, $qb->where);

        $stmt->execute();

        return $qb->method == 'insert' ? $this->lastInsertId() : $stmt->rowCount();
    }


    /**
     * @param QueryBuilder $query
     * @return bool
     */
    public function runDebug(QueryBuilder $query)
    {
        $qb = $query->build();
        $stmt = $this->prepareBindFromObj($qb->sql, $qb->input, $qb->where);

        $stmt->execute();
        return $stmt->debugDumpParams(); // you can see real request  if PDO::ATTR_EMULATE_PREPARES = true
    }

    /**
     * @param string $sql
     * @param array|null $params
     * @return bool|int
     */
    public function runRaw(string $sql, array $params = null)
    {
        if (!is_null($params)) {
            $stmt = $this->prepare($sql);
            $this->bindValueFromArr($stmt, $params);
            return $stmt->execute();

        } else {
            return $this->exec($sql);
        }

    }

}