<?php
require_once 'start.php';

$app = new Slim\App();

$app->get('/stats/get/top/all-time/{entity_type}', function ($request, $response, $args) use ($stats) {
  /** @var $response \Slim\Http\Response */
  /** @var $request \Slim\Http\Request */
  return $response->withJson($stats->topAllTimeByEntityType($args['entity_type'], $request->getQueryParam('limit', 10)), 200);
});

$app->get('/stats/get/top/{entity_type}/{timestamp_from}/{timestamp_to}', function ($request, $response, $args) use ($stats) {
  /** @var $response \Slim\Http\Response */
  /** @var $request \Slim\Http\Request */
  return $response->withJson($stats->topEntitiesByEntityTypeAndTimestamp($args['entity_type'], $args['timestamp_from'], $args['timestamp_to'], $request->getQueryParam('limit', FALSE)), 200);
});

$app->get('/stats/get/{entity_type}/{entity_id}', function ($request, $response, $args) use ($stats) {
  /** @var $response \Slim\Http\Response */
  /** @var $request \Slim\Http\Request */
  $return = [];
  $data = $request->getQueryParam('data', []);
  if ($count = $stats->get($args['entity_type'], $args['entity_id'], $data)) {
    $return['status'] = 'OK';
    $return['count'] = $count;
  }
  else {
    $return['status'] = 'FAIL';
    $return['code'] = Errors::STATS_NO_DATA;
    $return['message'] = Errors::getMessage(Errors::STATS_NO_DATA);
  }
  return $response->withJson($return, 200);
});

$app->get('/stats/write/{entity_type}/{entity_id}', function ($request, $response, $args) use ($stats) {
  /** @var $response \Slim\Http\Response */
  /** @var $request \Slim\Http\Request */
  $stats->write($args['entity_type'], $args['entity_id'], $request->getQueryParam('data', []));
  return $response->withJson(['status' => 'OK'], 200);
});

$app->get('/stats/trigger/{entity_type}/{entity_id}', function ($request, $response, $args) use ($stats) {
  /** @var $response \Slim\Http\Response */
  /** @var $request \Slim\Http\Request */
  $stats->write($args['entity_type'], $args['entity_id'], $request->getQueryParam('data', []));
  $count = $stats->get($args['entity_type'], $args['entity_id'], $request->getQueryParam('data', []));
  return $response->withJson(['status' => 'OK', 'count' => $count], 200);
});

$app->run();