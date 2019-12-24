<?php
declare(strict_types=1);

namespace OpstopsPw;


use \RuntimeException;

class QueryBuilder
{

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
     * Auto add created_at; updated_at for insert / update
     * @var bool
     */
//    protected $timestamps = false;

    /**
     * @return QueryBuilder
     */
    protected static function get()
    {
        return new self();
    }

    /**
     * @param string $value
     * @return string
     */
    public static function raw(string $value):string
    {
        return '{{RAW:' . $value . '}}';
    }

    /**
     * @param string $value
     * @return bool
     */
    protected static function isRaw($value):bool // public static function isRaw(string $value):bool
    {
        return is_string($value) && preg_match('/^{{RAW\:(.*)}}$/U', $value, $m) > 0;
    }

    /**
     * @param string $value
     * @return string
     */
    protected static function decodeRaw(string $value):string
    {
        preg_match('/^{{RAW\:(.*)}}$/U', $value, $m);

        if (!is_array($m) || empty($m[1])) {
            throw new RuntimeException("Invalid format: {$value}");
        }

        return $m[1];
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
     *
     * Example: [
        'd1' => 123,
        't.title' => 'Italy',
        'age' => ['>', 53],
        qb::RAW('time=NOW() AND d=123'), // no key
        qb::RAW('time=NOW() AND d=123'),
        't.time_create' => qb::RAW('NOW()'),
     ]
     *
     * @param $data
     * @return object
     */
    public static function prepareData(array $data)
    {
        $res = [
            'fields' => [], // for insert
            'values' => [],
            'fav' => [], // fields&values; for where
            'data' => []
        ];

        $fv = [];
        $f = [];

        foreach ($data as $key => $value) {

            if (is_integer($key) && self::isRaw($value)) { // Example: ['name'=>'Name', qb::RAW('time=NOW() AND d=123')]
                $res['fav'][] = self::decodeRaw($value);

            } else {
                $op = '=';
                $val = $value;

                if (is_array($value)) {
                    if (count($value) != 2) {
                        throw new \RuntimeException('Invalid \'where\' data format');
                    }
                    $op = $value[0];
                    $val = $value[1];
                }

                $f[] = $key;

                $keyTag = ":{$key}";
                $keyTag = str_replace('.', '_', $keyTag); // t.name -> t_name

                if (self::isRaw($value)) {
                    $val = self::decodeRaw($value);
                    $res['fav'][] = implode(' ', [$key, $op, $val]);

                } else {
                    $res['fav'][] = implode(' ', [$key, $op, $keyTag]);
                    $res['data'][$keyTag] = $val;
                }

                $fv[] = $keyTag;
            }

        }

        $res['fields'] =  implode(', ', $f);
        $res['values'] = implode(', ', $fv);

        return (object)$res;
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

        $dt = self::prepareData($where);

        $this->setDataWhere($dt->data);
        $whereDetails = 'WHERE ' . implode(' AND ', $dt->fav);

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
     * @param bool $v
     */
//    public function addTimestamps($v = true)
//    {
//        $this->timestamps = $v;
//        return $this;
//    }

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
     * @param string $table
     * @param array $data
     * @param bool $addTimestamps
     * @param bool $ignore
     * @return QueryBuilder
     */
    protected function _insert(string $table, array $data, $addTimestamps = false, $ignore = false):self
    {
        $this->method = 'insert';

        if ($addTimestamps) {
            $data['created_at'] = $this->timeToSql();
            $data['updated_at'] = $data['created_at'];
        }

        $dt = self::prepareData($data);

        $fieldNames = $dt->fields;
        $fieldValues = $dt->values;

        $this->inputData = $dt->data;

        $ins = $ignore ? 'INSERT IGNORE' : 'INSERT';
        $this->setPart(self::PART_SELECT, "{$ins} INTO {$table} ({$fieldNames}) VALUES ({$fieldValues})");

        return $this;
    }

    /**
     * @param string $table
     * @param array $data
     * @param bool $addTimestamps
     * @param bool $ignore
     * @return QueryBuilder
     */
    public static function insert(string $table, array $data, $addTimestamps = false, $ignore = false):self
    {
        return self::get()->_insert($table, $data, $addTimestamps, $ignore);
    }

    /**
     * @param string $table
     * @param array $data
     * @param array $where
     * @param bool $addTimestamps
     * @return QueryBuilder
     */
    protected function _update(string $table, array $data, array $where, $addTimestamps = false):self
    {
        $this->method = 'update';

        if ($addTimestamps) {
            $data['updated_at'] = $this->timeToSql();
        }

        $dt = self::prepareData($data);
        $fieldDetails = implode(', ', $dt->fav);
        $this->inputData = $dt->data;

        $this->setPart(self::PART_SELECT, "UPDATE {$table} SET {$fieldDetails}");
        $this->where($where);

        return $this;
    }

    /**
     * @param string $table
     * @param array $data
     * @param array $where
     * @param bool $addTimestamps
     * @return QueryBuilder
     */
    public static function update(string $table, array $data, array $where, $addTimestamps = false):self
    {
        return self::get()->_update($table, $data, $where, $addTimestamps);
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
        $pn = self::PART_FROM;

        if (!$this->getParts()[$pn]) {
            throw new RuntimeException("{$pn} not found");
        }

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
