<?php

namespace wpCloud\StatelessMedia {
  class Compatibility {
  }
  
  class WPStatelessStub {
  
    const TEST_GS_HOST = 'https://google.com'; 
  
    private static $instance = null;
  
    public static function instance() {
      return static::$instance ? static::$instance : static::$instance = new static;
    }
  
    public $options = [
      'sm.root_dir' => 'uploads',
      'sm.mode' => 'cdn',
    ];
  
    public function set($key, $value): void {
      $this->options[$key] = strval($value);
    }
  
    public function get($key): ?string {
      return $this->options[$key];
    }
  
    public function get_gs_host(): string {
      return self::TEST_GS_HOST;
    }
  }

  class Utility {
    private static $path_and_url = [];

    public static function set_path_and_url($path_and_url) {
      self::$path_and_url = $path_and_url;
    }

    public static function get_path_and_url() {
      return self::$path_and_url;
    }
  }
};
