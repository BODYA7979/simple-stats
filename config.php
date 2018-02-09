<?php

class Config {

  const PORT = 4567;

  const DATABASE_FILENAME = 'database.db';

  const CACHE_LIFETIME = [
    'stats' => [
      'top-all-time' => 300,
    ]
  ];

  const DEBUG = TRUE;

  const REDIS = [
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => '',
    'db' => 3
  ];

}