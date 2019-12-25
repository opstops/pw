<?php
declare(strict_types=1);

namespace OpstopsPw;


use phpDocumentor\Reflection\DocBlock\Tags\Reference\Url;
use phpDocumentor\Reflection\Types\Object_;
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
     * @var null
     */
    protected $sqlMulti = [];

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
    public static function helperTimeToSql($time = null)
    {
        $time = $time ?? time();
        return date('Y-m-d H:i:s', is_string($time) ? strtotime($time) : $time);
    }

    /**
     * For multi insert if data to long (1K+)
     *
     * For example: [[1,2], [1,2], ..] => [[[1,2], [1,2]], ..] if limit 2
     *
     * @param array $data
     * @param int $limit
     * @return array
     */
    public static function helperGroupToChunks(array $data, $limit = 100)
    {
        $i = 0;
        $res = [];
        $tmpAr = [];

        foreach ($data as $r) {
            $i++;
            $tmpAr[] = $r;
            if ($i == $limit) {
                $res[] = $tmpAr;
                $tmpAr = [];
                $i = 0;
            }
        }

        if ($tmpAr) { // last
            $res[] = $tmpAr;
        }

        return $res;
    }


    /**
     * @param string $table
     * @param array $data
     * @param bool $addTimestamps
     * @param bool $insertIgnore
     * @return QueryBuilder
     */
    protected function _insert(string $table, array $data, $addTimestamps = false, bool $insertIgnore = false):self
    {
        $this->method = 'insert';

        if ($addTimestamps) {
            $data['created_at'] = self::helperTimeToSql();
            $data['updated_at'] = $data['created_at'];
        }

        $dt = self::prepareData($data);

        $fieldNames = $dt->fields;
        $fieldValues = $dt->values;

        $this->inputData = $dt->data;

        $ins = $insertIgnore ? 'INSERT IGNORE' : 'INSERT';
        $this->setPart(self::PART_SELECT, "{$ins} INTO {$table} ({$fieldNames}) VALUES ({$fieldValues})");

        return $this;
    }

    /**
     * @param string $table
     * @param array $colNames
     * @param array $dataVals
     * @param bool $insertIgnore
     * @param string|null $dup
     * @return $this
     */
    protected function _insertMulti(string $table, array $colNames, array $dataVals, bool $insertIgnore = false, string $dup = null, $splitTo = 100)
    {
        $this->method = 'insert_multi';

//        $splitTo = 3;
//        $dataVals = array_slice($dataVals, 0 , 3); // for test todo
        $chunks = self::helperGroupToChunks($dataVals, $splitTo);

        foreach ($chunks as $c) {
//            var_dump($c);

            // memory warning: this is creating a copy all of $dataVals
            $dataToInsert = array();

            foreach ($c as $l) { // to line array
                foreach($l as $val) {
                    $dataToInsert[] = $val;
                }
            }

            $onDup = '';
            if ($dup) {
                $onDup = " ON DUPLICATE KEY UPDATE {$dup}";
            }

            // setup the placeholders - a fancy way to make the long "(?, ?, ?)..." string
            $rowPlaces = '(' . implode(', ', array_fill(0, count($colNames), '?')) . ')';
            $allPlaces = implode(', ', array_fill(0, count($c), $rowPlaces));

            $ins = $insertIgnore ? 'INSERT IGNORE' : 'INSERT';
            $this->sqlMulti[] = $ins . " INTO {$table} (" . implode(', ', $colNames) . ") VALUES " . $allPlaces . $onDup;

            $this->inputData[] = $dataToInsert;
        }

        // as result
//        'sqlMulti' =>
//            array (size=2)
//                0 => string 'INSERT INTO td_prv_directions (provider_id, from_country_id, from_city_id, to_country_id, to_city_id, updated_at) VALUES (?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)' (length=273)
//                1 => string 'INSERT INTO td_prv_directions (provider_id, from_country_id, from_city_id, to_country_id, to_city_id, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)' (length=193)

        return $this;
    }

    /**
     * @param string $table
     * @param array $colNames       ['provider_id', 'time_create']
     * @param array $dataVals       [[1, '2012-01-01'], ['provider_id'=>2, 'time_create' => '2012-01-02'], ..]
     * @param bool $insertIgnore      Insert ignore on dup
     * @param string|null $dup      Example: 'provider_id = VALUES(provider_id), time_create=NOW()'
     * @return QueryBuilder
     */
    public static function insertMulti(string $table, array $colNames, array $dataVals, bool $insertIgnore = false, string $dup = null):self
    {
        // self(...$args); _insertMulti(...$args)
        return self::get()->_insertMulti( $table,  $colNames,  $dataVals,  $insertIgnore,  $dup );
    }

    /**
     * @param string $table
     * @param array $data
     * @param bool $addTimestamps
     * @param bool $insertIgnore
     * @return QueryBuilder
     */
    public static function insert(string $table, array $data, $addTimestamps = false, $insertIgnore = false):self
    {
        return self::get()->_insert($table, $data, $addTimestamps, $insertIgnore);
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
            $data['updated_at'] = self::helperTimeToSql();
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
            'sqlMulti' => $this->sqlMulti,
        ];
    }


}
