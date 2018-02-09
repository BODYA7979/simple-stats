<?php
require_once './vendor/autoload.php';
require_once './config.php';

use \React\Http\Server as HttpServer;
use \React\Socket\Server as SocketServer;
use \Psr\Http\Message\ServerRequestInterface;
use \React\Http\Response;
use Medoo\Medoo;

if (Config::DEBUG) {
  error_reporting(E_ALL);
}
else {
  error_reporting(0);
}

class Errors {

  const STATS_NO_DATA = 0;

  static function getMessage($error) {
    switch ($error) {

      case self::STATS_NO_DATA:
        return 'No records found!';
        break;

    }
  }

}

/**
 * Init runtime variables
 */

$database = new Medoo([
  'database_type' => 'sqlite',
  'database_file' => Config::DATABASE_FILENAME
]);

/**
 * Declare functions
 * TODO: move this to separate files or/and create classes
 */

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

$server = new HttpServer(function (ServerRequestInterface $request) use ($database) {
  $start_request = microtime(true);
  $redis = get_redis();
  $url_parts = parse_url($request->getUri());
  $path_parts = explode('/', ltrim($url_parts['path'], '/'));
  $response = [];
  if (!empty($path_parts)) {
    if ($path_parts[0] == 'stats') {
      if ($path_parts[1] == 'get') {
        // route callback: /stats/get/top/all-time/{entity_type}
        if ($path_parts[2] == 'top' && $path_parts[3] == 'all-time' && !empty($path_parts[4])) {
          $entity_type = $path_parts[4];
          $redis_key_name = 'stats:top:all-time:'.$entity_type;
          if ($redis->exists($redis_key_name)) {
            $result = @unserialize($redis->get($redis_key_name));
          }
          else {
            $query = $database->query('select `entity_id`, count(*) as `cnt`
                                             from `stats`
                                             WHERE `entity_type` = \''.$entity_type.'\'
                                             group by `entity_id`
                                             order by `cnt` desc
                                             limit 1
                                     ');
            if ($query) {
              $query_result = $query->fetch();
              $result = [
                'entity_id' => $query_result['entity_id'],
                'count' => $query_result['cnt']
              ];
              $redis->set($redis_key_name, serialize($result), Config::CACHE_LIFETIME['stats']['top-all-time']);
            }
          }

          if (!empty($result)) {
            $response['entity_id'] = $result['entity_id'];
            $response['count'] = $result['count'];
          }
        }
        else {
          // route callback: /stats/get/{entity_type}/{entity_id}
          if (!empty($path_parts[2]) && !empty($path_parts[3])) {
            // show total view results for selected entity
            $entity_type = $path_parts[2];
            $entity_id = $path_parts[3];
            if ($count = stats_get_count($entity_type, $entity_id)) {
              $response['status'] = 'OK';
              $response['count'] = $count;
            }
            else {
              $response['status'] = 'FAIL';
              $response['code'] = Errors::STATS_NO_DATA;
              $response['message'] = Errors::getMessage(Errors::STATS_NO_DATA);
            }
          }
        }
      }
      elseif ($path_parts[1] == 'write') {
        if (!empty($path_parts[2]) && !empty($path_parts[3])) {
          // add plus one count
          $entity_type = $path_parts[2];
          $entity_id = $path_parts[3];
          stats_write_one($entity_type, $entity_id);
          $response['status'] = 'OK';
        }
      }
      elseif ($path_parts[1] == 'trigger') {
        if (!empty($path_parts[2]) && !empty($path_parts[3])) {
          // we need to add plus one to views count and show results
          $entity_type = $path_parts[2];
          $entity_id = $path_parts[3];
          $count = stats_get_count($entity_type, $entity_id);
          stats_write_one($entity_type, $entity_id);
          $response['status'] = 'OK';
          $response['count'] = ($count + 1);
        }
      }
    }
  }

  $time_elapsed_secs = microtime(true) - $start_request;
  if (Config::DEBUG) {
    echo 'Request "' . $request->getUri() . '" processed in: ' . $time_elapsed_secs . PHP_EOL;
  }

  if (!empty($response)) {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
  }
  else {
    return new Response(404);
  }
});


// Run server
$loop = React\EventLoop\Factory::create();
$socket = new SocketServer(Config::PORT, $loop);
$server->listen($socket);
echo 'Local server started on http://127.0.0.1:'.Config::PORT.PHP_EOL;
$loop->run();