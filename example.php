<?php

// ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

use OpstopsPw\QueryBuilder as qb;
use OpstopsPw\Database;
use OpstopsPw\RowInterface;

$db = Database::get([
    'dsn' => 'mysql:host=127.0.0.1;dbname=db;charset=utf8',
    'username' => 'admin',
    'password' => 'admin',
    'options' => [
        // PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,..
    ]
]);


// fast tests
//$q = qb::select('SELECT id, username FROM td_users WHERE id < 5');
//$d = $this->db->findAll($q); var_dump($d);
//$d = $this->db->findRow($q); var_dump($d);
//$d = $this->db->findAssoc($q); var_dump($d);
//$d = $this->db->findCol($q); var_dump($d);
//$d = $this->db->findOne($q); var_dump($d);
//$d = $this->db->findCount(qb::select('SELECT id, username')->from('td_users')); var_dump($d);


echo '<pre>';
$q = qb::select('SELECT * FROM users')->where(['id' => 110])->order('username ASC, id DESC')->limit(5)->group('id')->offset(6);
var_dump($q->build()->sql); // SELECT * FROM users WHERE id = :id GROUP BY id ORDER BY username ASC, id DESC LIMIT 5 OFFSET 6

$q = qb::select('SELECT *')->from('users')->order(['username ASC', 'id DESC'])->limit(5)->offset(6);
var_dump($q->build()->sql); // SELECT * FROM users ORDER BY username ASC, id DESC LIMIT 5 OFFSET 6

$q = qb::select("* FROM users WHERE id=:id", [':id' => 12]);
var_dump($q->build()); // SELECT * FROM users WHERE id=:id

//for other queries
$q = qb::query("CREATE TABLE IF NOT EXISTS tasks...");
var_dump($q->build()->sql); // CREATE TABLE IF NOT EXISTS tasks...

// --------------------------------------------------------------------------------

$q = qb::select("SELECT username, id FROM users WHERE id > :id ", ['id' => 15])->limit(3);
var_dump($q->build()->sql); // CREATE TABLE IF NOT EXISTS tasks...

$res = $db->findAll($q);

//Array
//(
//[0] => stdClass Object
//(
//[username] => Lew
//[id] => 16
//)
//
//[1] => stdClass Object
//(
//[username] => Chlo
//[id] => 17
//)
//
//[2] => stdClass Object
//(
//[username] => Brittan
//[id] => 18
//)
//
//)


$res = $db->findAll($q, PDO::FETCH_ASSOC);

//Array
//(
//[0] => Array
//(
//[username] => Lew
//[id] => 16
//)
//
//[1] => Array
//(
//[username] => Chlo
//[id] => 17
//)
//
//[2] => Array
//(
//[username] => Brittan
//[id] => 18
//)
//
//)



class RowModel implements RowInterface {}
$res = $db->findAll($q, RowModel::class);

//Array
//(
//[0] => RowModel Object
//(
//[username] => Lew
//[id] => 16
//)
//
//[1] => RowModel Object
//(
//[username] => Chlo
//[id] => 17
//)
//
//[2] => RowModel Object
//(
//[username] => Brittan
//[id] => 18
//)
//
//)



$res = $db->findCol($q);
//Array
//(
//[0] => Lew
//[1] => Chlo
//[2] => Brittan
//)


$res = $db->findAssoc($q);

//Array
//(
//    [Lew] => 16
//    [Chlo] => 17
//    [Brittan] => 18
//)


$res = $db->findRow($q,PDO::FETCH_ASSOC);
//Array
//(
//    [username] => Lew
//    [id] => 16
//)


//$res = $db->findOne($q);
//Lew


$res = $db->findOne(qb::select('NOW()'));
print_r($res);


$q = qb::select("SELECT username, id")->from('users')->where([qb::RAW => 'username IS NOT NULL AND 1', 'id' => ['>', 3] ])->limit(3);
var_dump($q->build()->sql);
$res = $db->findAll($q);
print_r($res);

$q->toCount();
$res = $db->findOne($q); // 997
print_r($res);

$res = $db->findCount($q); // 997
print_r($res);



$q = qb::insert('users2', [
    'username' => 'Alex-'.random_int(0, 9999),
    'email' => 'Email@host.xx',
    'password' => password_hash(random_bytes(32), PASSWORD_DEFAULT)
]);
print_r($q->build());
$res = $db->run($q); // return last insert id
var_dump($res);

$res = $db->runDebug($q); // pdo statement debug
var_dump($res);

// INSERT INTO users2 (username,email,password,created_at,updated_at) VALUES (:username, :email, :password, :created_at, :updated_at)

//[:username] => Alex-8299
//[:email] => Email@host.xx
//[:password] => $2y$10$yLsgth8Q.IcizeFuX/B4LeQ08qi3BRZ1/B9P78E/1V72X.oMSgiZK
//[:created_at] => 2019-10-23 12:38:27
//[:updated_at] => 2019-10-23 12:38:27



$qb = qb::update('users2', [
    'username' => 'Alex __ NEW__10',
], [
    'id' => 10
]);
var_dump($qb->build()->sql); // UPDATE users2 SET username = :username, updated_at = :updated_at WHERE id = :id

$res = $db->run($qb); // rows affected
var_dump($res);