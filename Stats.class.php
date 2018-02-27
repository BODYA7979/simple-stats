<?php

use Medoo\Medoo;

class Stats {

  /** @var $database Medoo */
  protected $database;

  public function __construct($database)
  {
    $this->database = $database;
    try {
      $this->redis = get_redis();
    }
    catch (Exception $exception) {
      exit($exception->getMessage());
    }
  }

  private function getRedisKey($entity_type, $entity_id, $data = []) {
    $rediskey = 'stats:'.$entity_type.':'.$entity_id;
    if (!empty($data)) {
      foreach ($data as $key => $value) {
        $rediskey .= ':'.$key.':'.$value;
      }
    }
    return $rediskey;
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

  public function topAllTimeByEntityType($entity_type, $limit = 10) {
    $redis_key_name = $this->getRedisKey($entity_type, null, ['top' => 1, 'all-time' => 1, 'limit' => $limit]);
    if ($this->redis->exists($redis_key_name)) {
      $results = @unserialize($this->redis->get($redis_key_name));
    }
    else {
      $query = $this->database->query('select `entity_id`, count(*) as `cnt`
                                             from `stats`
                                             WHERE `entity_type` = \''.$entity_type.'\'
                                             group by `entity_id`
                                             order by `cnt` desc
                                             limit '.$limit);
      if ($query) {
        $query_results = $query->fetchAll();
        $results = [];
        foreach ($query_results as $result) {
          $results[] = [
            'entity_id' => $result['entity_id'],
            'count' => $result['cnt']
          ];
        }
        $this->redis->set($redis_key_name, serialize($results), Config::CACHE_LIFETIME['stats']['top-all-time']);
      }
      else {
        $results = [];
      }
    }
    return $results;
  }

  public function topEntitiesByEntityTypeAndTimestamp($entity_type, $from, $to, $limit) {
    $redis_key_name = $this->getRedisKey($entity_type, 0, ['top' => 1, 'from' => $from, 'to' => $to, 'limit' => $limit]);
    if ($this->redis->exists($redis_key_name)) {
      $results = @unserialize($this->redis->get($redis_key_name));
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
      $query = $this->database->query($sql);
      if ($query) {
        $query_result = $query->fetchAll();
        $results = [];
        foreach ($query_result as $item) {
          $results[] = [
            'entity_id' => $item['entity_id'],
            'count' => $item['cnt']
          ];
        }
        $this->redis->set($redis_key_name, serialize($results), Config::CACHE_LIFETIME['stats']['top-by-timestamp']);
      }
      else {
        $results = [];
      }
    }
    return $results;
  }

}