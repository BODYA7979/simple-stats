<?php

require_once './vendor/autoload.php';
require_once './errors.php';
require_once './config.php';

use Medoo\Medoo;

if (Config::DEBUG) {
  error_reporting(E_ALL);
}
else {
  error_reporting(0);
}

/**
 * Init runtime variables
 */

$database = new Medoo([
  'database_type' => 'sqlite',
  'database_file' => Config::DATABASE_FILENAME
]);

$redis_connected = FALSE;
function redis_connect() {
  global $redis_connected;
  global $redis;
  $redis = new Redis();
  $redis->pconnect(Config::REDIS['host'], Config::REDIS['port']);
  $redis->auth(Config::REDIS['password']);
  $redis->select(Config::REDIS['db']);
  $redis_connected = TRUE;
}

/**
 * @return Redis
 * @throws Exception
 */
function get_redis() {
  global $redis_connected;
  global $redis;
  if ($redis_connected) {
    if ($redis->ping()) {
      return $redis;
    }
    else {
      $redis_connected = FALSE;
      throw new Exception('Can\'t ping redis!');
    }
  }
  else {
    redis_connect();
    return get_redis();
  }
}

function create_tables() {
  global $database;
  $database->query('CREATE TABLE stats
                          (
                            entity_type VARCHAR NOT NULL,
                            entity_id   VARCHAR NOT NULL,
                            timestamp   INT,
                            data        BLOB
                          );
                          
                          CREATE INDEX stats_entity_type_entity_id_index
                            ON stats (entity_type, entity_id);
                  ')->execute();
}

/**
 * @param $entity_type string
 * @param $entity_id string
 *
 * @return int|false
 */
function stats_get_count($entity_type, $entity_id) {
  global $database;
  /** @var $redis Redis */
  global $redis;
  $redis_record_name = 'stats:'.$entity_type.':'.$entity_id;
  if (!$redis->exists($redis_record_name)) {
    if ($count = $database->count('stats', ['entity_type' => $entity_type, 'entity_id' => $entity_id])) {
      $redis->set($redis_record_name, $count);
      return $count;
    }
    else {
      return FALSE;
    }
  }
  else {
    $count = $redis->get($redis_record_name);
    return $count;
  }
}

/**
 * @param $entity_type
 * @param $entity_id
 */
function stats_write_one($entity_type, $entity_id) {
  global $database;
  /** @var $redis Redis */
  global $redis;
  $redis_record_name = 'stats:'.$entity_type.':'.$entity_id;
  $database->insert('stats', [
    'entity_type' => $entity_type,
    'entity_id' => $entity_id,
    'timestamp' => time(),
    'data' => '',
  ])->execute();
//  $redis->set($redis_record_name, (stats_get_count($entity_type, $entity_id) + 1));
  $redis->incr($redis_record_name);
}


// check if table exists
$table_check = $database->query('SELECT name FROM sqlite_master WHERE type=\'table\' AND name=\'stats\';')->fetchAll();
if (empty($table_check)) {
  // table does not exists - create
  create_tables();
}

$redis = get_redis();