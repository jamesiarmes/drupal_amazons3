<?php

/**
 * @file
 * Contains \Drupal\amazons3\DrupalAdapter\Bootstrap.
 */

namespace Drupal\amazons3\DrupalAdapter;

/**
 * Methods that map to includes/bootstrap.inc.
 */
trait Bootstrap {

  /**
   * @todo Determine if we actually need this.
   */
  public static function variable_get($name, $default = NULL) {
    return (\Drupal::config('amazons3.settings')->get($name) ?: $default);
  }

}
