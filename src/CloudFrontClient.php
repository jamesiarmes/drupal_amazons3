<?php

/**
 * @file
 * Contains \Drupal\amazons3\CloudFrontClient.
 */

namespace Drupal\amazons3;

use Aws\CloudFront\CloudFrontClient as AwsCloudFrontClient;

/**
 * @class CloudFrontClient
 * @package Drupal\amazons3
 */
class CloudFrontClient extends AwsCloudFrontClient {

  /**
   * Override factory() to set credential defaults.
   */
  public static function factory($config = array()) {
    if (!isset($config['private_key'])) {
      $drupal_config = new Config();
      $config['private_key'] = $drupal_config->cloudfront_private_key();
      $config['key_pair_id'] = $drupal_config->cloudfront_keypair_id();
    }

    return parent::factory($config);
  }

}
