<?php

class Config {

  // filename for primary database
  const DATABASE_FILENAME = 'database.db';

  // set caching lifetime for application parts
  const CACHE_LIFETIME = [
    'stats' => [
      'top-all-time' => 300,
      'top-by-timestamp' => 300
    ]
  ];

  // show errors or not
  const DEBUG = TRUE;

  // credentials for Redis server
  const REDIS = [
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => '',
    'db' => 3
  ];

}