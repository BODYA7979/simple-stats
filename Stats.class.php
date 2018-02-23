<?php

use Medoo\Medoo;

class Stats {

  /** @var $database Medoo */
  protected $database;
  /** @var $redis Redis */
  protected $redis;

  public function __construct($database, $redis)
  {
    $this->database = $database;
    $this->redis = $redis;
  }

  private function getRedisKey($entity_type, $entity_id, $data = []) {
    $key = 'stats:'.$entity_type.':'.$entity_id;
    if (!empty($data)) {
      foreach ($data as $key => $value) {
        $key .= ':'.$key.':'.$value;
      }
    }
    return $key;
  }

  public function write($entity_type, $entity_id, $data = []) {
    $redis_record_name = $this->getRedisKey($entity_type, $entity_id, $data);

    if (!$this->redis->exists($redis_record_name)) {
      $current_count = $this->get($entity_type, $entity_id, $data);
      if ($current_count) {
        $this->redis->set($redis_record_name, $current_count);
      }
      else {
        $this->redis->set($redis_record_name, 0);
      }
    }

    $this->redis->incr($redis_record_name);
    $this->database->insert('stats', [
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'timestamp' => time(),
      'data' => serialize($data),
    ]);
  }

  public function get($entity_type, $entity_id, $data = []) {
    $redis_record_name = $this->getRedisKey($entity_type, $entity_id, $data);
    if (!$this->redis->exists($redis_record_name)) {
      if (!empty($data)) {
        $results = $this->database->select('stats', '*', [
          'entity_type' => $entity_type,
          'entity_id' => $entity_id
        ]);
        if ($results) {
          $count = 0;
          foreach ($results as $result) {
            $result['data'] = unserialize($result['data']);
            if ($result['data'] == $data) {
              $count++;
            }
          }
          return $count;
        }
        return 0;
      }
      else {
        if ($count = $this->database->count('stats', ['entity_type' => $entity_type, 'entity_id' => $entity_id])) {
          $this->redis->set($redis_record_name, $count);
          return $count;
        }
        else {
          return FALSE;
        }
      }
    }
    else {
      $count = $this->redis->get($redis_record_name);
      return $count;
    }
  }

}