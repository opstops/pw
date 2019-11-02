<?php
declare(strict_types=1);

namespace OpstopsPw;


use \RuntimeException;

class QueryBuilder
{

    const RAW = '{RAW}';

    protected const PART_SELECT = 'SELECT';
    protected const PART_FROM = 'FROM';
    protected const PART_WHERE = 'WHERE';
    protected const PART_GROUP = 'GROUP';
    protected const PART_HAVING = 'HAVING';
    protected const PART_ORDER = 'ORDER';
    protected const PART_LIMIT = 'LIMIT';
    protected const PART_OFFSET= 'OFFSET';

    /**
     * @var array
     */
    protected $parts = [
        self::PART_SELECT => null,
        self::PART_FROM => null,
        self::PART_WHERE => null,
        self::PART_GROUP => null,
        self::PART_HAVING => null,
        self::PART_ORDER => null,
        self::PART_LIMIT => null, // ofset, limit
        self::PART_OFFSET => null,
    ];

    /**
     * @var null string
     */
    protected $method = null;

    /**
     * for insert, update, ..
     * @var array
     */
    protected $inputData = [];

    /**
     * for condition
     * @var array
     */
    protected $whereData = [];

    /**
     * @var string
     */
    protected $sql = null;

    /**
     * auto convert sql to count only if not null
     *
     * @var string
     */
    protected $countId = null;

    /**
     * @return QueryBuilder
     */
    protected static function get()
    {
        return new self();
    }

    /**
     * Replace prefix, SeleCt * table.. => SELECT * table.. OR * table.. => SELECT * table..
     *
     * @param string $prefix
     * @param string $str
     * @param bool $setIfNotExists
     * @return string
     */
    protected function replacePrefix(string $prefix, string $str, bool $setIfNotExists = true):string
    {
        $str = trim($str);
        $prefixLen = strlen($prefix);
        $strLen = strlen($str);


        if ($strLen >= $prefixLen) {
            $part = strtolower(substr($str, 0, $prefixLen));
//            var_dump($str, $part, $prefixLen, $strLen); exit;

            if ($part === strtolower($prefix)) {
                $str = ltrim(substr($str, $prefixLen));
            }
        }

        if ($setIfNotExists) {
            $str = strtoupper($prefix) . ' ' . $str;
        }

        return $str;
    }

    /**
     * @param $name
     * @param $v
     * @return QueryBuilder
     */
    protected function setPart($name, $v):self
    {
        if (!array_key_exists($name, $this->parts)) {
            throw new RuntimeException("Part: {$name} not exists");
        }

        $this->parts[$name] = $v;
        return $this;
    }

    /**
     * @param string $v
     * @return QueryBuilder
     */
    public function from(string $v):self
    {
        $v = $this->replacePrefix('FROM', $v, true);
//        var_dump($v); exit;

        $this->setPart(self::PART_FROM, $v);
        return $this;
    }

    /**
     * @param array|null $data
     * @return QueryBuilder
     */
    protected function setDataWhere(array $data = null): void
    {
        if ($data) {
            foreach ($data as $key => $value) {
                $this->whereData[$key] = $value; // users data..
            }
        }
    }

    /**
     * @param array $where
     * @param array $data
     * @return $this
     */
    public function where(array $where, array $data = null):self
    {
        if (!$where) {
            return $this;
        }

//        ksort($where); // todo

        $whereDetails = null;

        $t = [];
        foreach ($where as $key => $value) {

            // old ['id', '>' , 10]
//            if (is_array($value)) {
//                if (count($value) != 3) {
//                    throw new \RuntimeException('Invalid \'where\' data format');
//                }
//
//                $key = $value[0];
//                $t[] = implode(' ', [$key, $value[1], ':' . $value[2]]);
//                $this->whereData[":{$key}"] = $value[2];
//
//            } else {
//                $t[] = "$key = :$key";
//                $this->whereData[":{$key}"] = $value;
//            }
//
            if ($key === self::RAW) {
                $t[] = $value;
            }

            // ['id' => ['>', 10]]
            elseif (is_array($value)) {
                if (count($value) != 2) {
                    throw new \RuntimeException('Invalid \'where\' data format');
                }

                $t[] = implode(' ', [$key, $value[0], ':' . $key]);
                $this->whereData[":{$key}"] = $value[1];

            } else {
                $t[] = "$key = :$key";
                $this->whereData[":{$key}"] = $value;
            }

        }

        $whereDetails = 'WHERE ' . implode(' AND ', $t);

//        $whereDetails = 'WHERE ' . ltrim($whereDetails, ' AND ');

        $this->setPart(self::PART_WHERE, $whereDetails);

        $this->setDataWhere($data);

        return $this;
    }


    /**
     * @param string|array $v
     * @return $this
     */
    public function group($v):self
    {
        if (is_array($v)) {
            $v = implode(', ', $v);    
        }
        
        $this->setPart(self::PART_GROUP, 'GROUP BY ' . $v);

        return $this;
    }

    /**
     * @param string $v
     * @return QueryBuilder
     */
    public function having(string $v):self
    {
        $this->setPart(self::PART_HAVING, $v);
        return $this;
    }

    /**
     * @param string|array $v
     * @return $this
     */
    public function order($v):self
    {
        if (is_array($v)) {
            $v = implode(', ', $v);
        }

        $this->setPart(self::PART_ORDER, 'ORDER BY ' . $v);

        return $this;
    }

    /**
     * @param int $v
     * @return $this
     */
    public function limit(int $v):self
    {
        if (!is_numeric($v)) {
            throw new \RuntimeException('Limit value is incorrect');
        }

        $this->setPart(self::PART_LIMIT , 'LIMIT ' . (int) $v);

        return $this;
    }

    /**
     * @param int $v
     * @return $this
     */
    public function offset(int $v):self
    {
        if (!is_numeric($v)) {
            throw new \RuntimeException('Offset value is incorrect');
        }

        $this->setPart(self::PART_OFFSET, 'OFFSET ' . (int) $v);

        return $this;
    }

    /**
     * @param int|string $time
     * @return false|string
     */
    protected function timeToSql($time = null)
    {
        $time = $time ?? time();
        return date('Y-m-d H:i:s', is_string($time) ? strtotime($time) : $time);
    }

    /**
     * @param $table
     * @param $data
     * @return $this
     */
    protected function _insert(string $table, array $data):self
    {
        $this->method = 'insert';

        $data['created_at'] = $this->timeToSql();
        $data['updated_at'] = $data['created_at'];

//        ksort($data); // todo
        $fieldNames = implode(',', array_keys($data));
        $fieldValues = ':' . implode(', :', array_keys($data));

        foreach ($data as $key => $value) {
            $this->inputData[":{$key}"] = $value;
        }

        $this->setPart(self::PART_SELECT, "INSERT INTO {$table} ({$fieldNames}) VALUES ({$fieldValues})");

        return $this;
    }

    /**
     * @param string $table
     * @param array $data
     * @return QueryBuilder
     */
    public static function insert(string $table, array $data):self
    {
        return self::get()->_insert($table, $data);
    }

    /**
     * @param string $table
     * @param array $data
     * @param array $where
     * @return QueryBuilder
     */
    protected function _update(string $table, array $data, array $where):self
    {
        $this->method = 'update';

        $data['updated_at'] = $this->timeToSql();

//        ksort($data); // todo
        $fieldDetails = null;

        foreach ($data as $key => $value) {
            $fieldDetails .= "$key = :$key, ";
            $this->inputData[":{$key}"] = $value;
        }

        $fieldDetails = rtrim($fieldDetails, ', ');


        $this->setPart(self::PART_SELECT, "UPDATE {$table} SET {$fieldDetails}");
        $this->where($where);

        return $this;
    }

    /**
     * @param string $table
     * @param array $data
     * @param array $where
     * @return QueryBuilder
     */
    public static function update(string $table, array $data, array $where):self
    {
        return self::get()->_update($table, $data, $where);
    }

    /**
     * @param string $table
     * @param array $where
     * @param int|null $limit
     * @return QueryBuilder
     */
    protected function _delete(string $table, array $where, int $limit = null):self
    {
        $this->method = 'delete';

        $this->setPart(self::PART_SELECT,"DELETE FROM {$table}");
        $this->where($where);

        if (!is_null($limit)) {
            $this->limit($limit);
        }

        return $this;
    }

    /**
     * @param string $table
     * @param array $where
     * @param int|null $limit
     * @return QueryBuilder
     */
    public static function delete(string $table, array $where, int $limit = null):self
    {
        return self::get()->_delete($table, $where, $limit);
    }

    /**
     * @param $table
     * @return $this
     */
    protected function _truncate(string $table):self
    {
        $this->method = 'truncate';

        $this->setPart(self::PART_SELECT,"TRUNCATE TABLE {$table}");

        return $this;
    }

    /**
     * @param $table
     * @return QueryBuilder
     */
    public static function truncate(string $table):self
    {
        return self::get()->_truncate($table);
    }

    /**
     * @param $str
     * @param array|null $data Named params
     * @return $this
     */
    protected function _select(string $str, array $data = null):self
    {
        $this->method = 'select';

        $str = $this->replacePrefix('SELECT', $str, true);

        $this->setPart(self::PART_SELECT, $str);

        $this->setDataWhere($data);

        return $this;
    }

    /**
     * Any request to db
     *
     * @param string $select
     * @param array|null $data
     * @return QueryBuilder
     */
    public static function select(string $select, array $data = null):self
    {
        return self::get()->_select($select, $data);
    }


    /**
     * @param string $str
     * @param array|null $data
     * @return QueryBuilder
     */
    protected function _query(string $str, array $data = null):self
    {
        $this->method = 'query';

        $this->setPart(self::PART_SELECT, $str);

        if ($data) {
            foreach ($data as $key => $value) {
                $this->whereData[$key] = $value; // users data..
            }
        }

        return $this;
    }

    /**
     * @param string $select
     * @param array|null $data
     * @return QueryBuilder
     */
    public static function query(string $select, array $data = null):self
    {
        return self::get()->_query($select, $data);
    }


    /**
     * Convert sql request for count only
     *
     * @param string $id
     * @return QueryBuilder
     */
    public function toCount(string $id = '*'): self
    {
        $this->countId = $id;
        return $this;
    }

    /**
     * @return string
     */
    protected function getSql():string
    {
        $res = [];
        foreach ($this->parts as $key => $val) {

            // convert for get count
            if (!is_null($this->countId)) {
                if ($key == self::PART_SELECT) {
                    $val = "SELECT COUNT({$this->countId})";
                }

                if (!is_null($val) && in_array($key, [self::PART_ORDER, self::PART_OFFSET, self::PART_LIMIT])) {
                    $val = null; // reset
                }
            }
            //
            
            if (!is_null($val)) {
                $res[] = $val;
            }
        }

        $this->sql = implode(' ', $res);

        return $this->sql;
    }

    /**
     * @return array
     */
    protected function getInputData():array
    {
        return $this->inputData;
    }

    /**
     * @return array
     */
    protected function getWhereData():array
    {
        return $this->whereData;
    }

    /**
     * @return array
     */
    protected function getParts():array
    {
        return $this->parts;
    }


    /**
     * @return object
     */
    public function build():object
    {
        return (object) [
            'parts' => $this->getParts(),
            'input' => $this->getInputData(),
            'where' => $this->getWhereData(),
            'method' => $this->method,
            'sql' => $this->getSql(),
        ];
    }


}