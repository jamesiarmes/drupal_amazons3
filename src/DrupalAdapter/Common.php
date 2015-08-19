<?php

namespace Drupal\amazons3\DrupalAdapter;

/**
 * Methods that map to includes/common.inc.
 *
 * @trait Common
 * @package Drupal\amazons3\DrupalAdapter
 * @codeCoverageIgnore
 */
trait Common {

  /**
   * @param string $path
   * @param array $options
   * @return string
   */
  public static function url($path = NULL, $options = array()) {
    // @FIXME
// url() expects a route name or an external URI.
// return url($path, $options);

  }

  /**
   * @param $path
   * @return mixed
   */
  public static function drupal_encode_path($path) {
    return \Drupal\Component\Utility\UrlHelper::encodePath($path);
  }
}
