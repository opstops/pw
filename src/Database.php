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
     * @param PDOStatement $stmt
     * @param $classNameOrFetchMode
     */
    protected function setClassFechMode(PDOStatement $stmt , $classNameOrFetchMode)
    {
        $classNameObject = null;

        if (is_int($classNameOrFetchMode)) {
            $fetchMode = $classNameOrFetchMode;

            if (PDO::FETCH_COLUMN === $classNameOrFetchMode) {
                $classNameObject = 0; // column number
            }

        } elseif(is_subclass_of($classNameOrFetchMode, RowInterface::class)) {
            $fetchMode = PDO::FETCH_CLASS;
            $classNameObject = $classNameOrFetchMode;

        } else {
            throw new PDOException("Invalid attribute (class name must be instanse of " . RowInterface::class . " or PDO::fech mode)");
        }

//        var_dump($fetchMode, $classNameObject); exit;
        if (is_null($classNameObject)) {
            $stmt->setFetchMode($fetchMode); // For FETCH_KEY_PAIR -  General error: fetch mode doesn't allow any extra arguments
        } else {
            $stmt->setFetchMode($fetchMode, $classNameObject);
        }
    }

    /**
     * @param QueryBuilder $query
     * @param null|string|int $classNameOrFetchMode
     * @return mixed
     */
    public function findRow(QueryBuilder $query, $classNameOrFetchMode = null)
    {
        $qb = $query->build();
        $stmt = $this->prepareBindFromObj($qb->sql, $qb->input, $qb->where);

        if (!is_null($classNameOrFetchMode)) {
            $this->setClassFechMode($stmt, $classNameOrFetchMode);
        }

        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * @param QueryBuilder $query
     * @param null|string|int $classNameOrFetchMode
     * @return array
     */
    public function findAll(QueryBuilder $query, $classNameOrFetchMode = null)
    {
        $qb = $query->build();
        $stmt = $this->prepareBindFromObj($qb->sql, $qb->input, $qb->where);

        if (!is_null($classNameOrFetchMode)) {
            $this->setClassFechMode($stmt, $classNameOrFetchMode);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @param QueryBuilder $query
     * @return array
     */
    public function findAssoc(QueryBuilder $query)
    {
        return $this->findAll($query, PDO::FETCH_KEY_PAIR);
    }

    /**
     * @param QueryBuilder $query
     * @return array
     */
    public function findCol(QueryBuilder $query)
    {
        return $this->findAll($query,  PDO::FETCH_COLUMN);
    }

    /**
     * @param QueryBuilder $query
     * @param string|null $rowClass
     * @return mixed
     */
    public function findOne(QueryBuilder $query)
    {
        $qb = $query->build();
        $stmt = $this->prepareBindFromObj($qb->sql, $qb->input, $qb->where);

        $stmt->execute();
        return $stmt->fetchColumn();
    }

    /**
     * @param QueryBuilder $query
     * @param string $column
     * @return mixed
     */
    public function findCount(QueryBuilder $query, string $column = '*')
    {
        $qq = clone $query; //
        $q = $qq->toCount($column);
        return $this->findOne($q);
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