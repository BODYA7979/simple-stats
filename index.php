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
        $limit = (isset($_GET['limit'])) ? intval($_GET['limit']) : 10;
        $response = $stats->topAllTimeByEntityType($entity_type, $limit);
      }
      // /stats/get/top/{entity_type}/{timestamp_from}/{timestamp_to} [?limit={limit}]
      elseif ($path_parts[2] == 'top' && !empty($path_parts[3]) && !empty($path_parts[4]) && !empty($path_parts[5])) {
        $entity_type = $path_parts[3];
        $from = $path_parts[4];
        $to = $path_parts[5];
        $limit = (isset($_GET['limit'])) ? intval($_GET['limit']) : FALSE;
        $results = $stats->topEntitiesByEntityTypeAndTimestamp($entity_type, $from, $to, $limit);
        $response = $results;
      }
      else {
        // route callback: /stats/get/{entity_type}/{entity_id}
        if (!empty($path_parts[2]) && !empty($path_parts[3])) {
          // show total view results for selected entity
          $entity_type = $path_parts[2];
          $entity_id = $path_parts[3];
          $data = (!empty($_GET['data']) ? $_GET['data'] : []);
          if ($count = $stats->get($entity_type, $entity_id, $data)) {
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
        $data = (!empty($_GET['data']) ? $_GET['data'] : []);
        $stats->write($entity_type, $entity_id, $data);
        $response['status'] = 'OK';
      }
    }
    elseif ($path_parts[1] == 'trigger') {
      if (!empty($path_parts[2]) && !empty($path_parts[3])) {
        // we need to add plus one to views count and show results
        $entity_type = $path_parts[2];
        $entity_id = $path_parts[3];
        $data = (!empty($_GET['data']) ? $_GET['data'] : []);
        $stats->write($entity_type, $entity_id, $data);
        $count = $stats->get($entity_type, $entity_id, $data);
        $response['status'] = 'OK';
        $response['count'] = $count;
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