<?php

require_once './vendor/autoload.php';
require_once './Stats.class.php';
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
  $database->query('
CREATE TABLE stats
(
    entity_type VARCHAR NOT NULL,
    entity_id VARCHAR NOT NULL,
    timestamp INT,
    data BLOB
);
CREATE INDEX stats_entity_type_entity_id_timestamp_index ON stats (entity_type, entity_id DESC, timestamp DESC);
INSERT INTO stats(entity_type, entity_id, timestamp, data) SELECT entity_type, entity_id, timestamp, data FROM stats;
DROP TABLE stats;
ALTER TABLE stats RENAME TO stats;
')->execute();
}



// check if table exists
$table_check = $database->query('SELECT name FROM sqlite_master WHERE type=\'table\' AND name=\'stats\';')->fetchAll();
if (empty($table_check)) {
  // table does not exists - create
  create_tables();
}

$redis = get_redis();

$stats = new Stats($database, $redis);