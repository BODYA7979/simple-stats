<?php

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