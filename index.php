<?php
require_once 'start.php';

$url_parts = parse_url($_SERVER['REQUEST_URI']);
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
      // /stats/get/top/{entity_type}/{timestamp_from}/{timestamp_to} [?limit={limit}]
      elseif ($path_parts[2] == 'top' && !empty($path_parts[3]) && !empty($path_parts[4]) && !empty($path_parts[5])) {
        $entity_type = $path_parts[3];
        $from = $path_parts[4];
        $to = $path_parts[5];
        $limit = (isset($_GET['limit'])) ? intval($_GET['limit']) : FALSE;
        $redis_key_name = 'stats:top:'.$entity_type.':'.$from.':'.$to.':limit-'.$limit;
        if ($redis->exists($redis_key_name)) {
          $results = @unserialize($redis->get($redis_key_name));
        }
        else {
          $sql = 'select `entity_id`, count(*) as `cnt`
                                             from `stats`
                                             WHERE `entity_type` = \''.$entity_type.'\' AND `timestamp` >= '.$from.' AND timestamp <= '.$to.'
                                             group by `entity_id`
                                             order by `cnt` desc';
          if ($limit) {
            $sql .= ' LIMIT '.$limit;
          };
          $query = $database->query($sql);
          if ($query) {
            $query_result = $query->fetchAll();
            $resuls = [];
            foreach ($query_result as $item) {
              $results[] = [
                'entity_id' => $item['entity_id'],
                'count' => $item['cnt']
              ];
            }
            $redis->set($redis_key_name, serialize($results), Config::CACHE_LIFETIME['stats']['top-by-timestamp']);
          }
        }
        $response = $results;
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

if (!empty($response)) {
  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($response);
}
else {
  http_response_code(404);
}