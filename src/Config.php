<?php

/**
 * @file
 * Contains \Drupal\amazons3\Config.
 */

namespace Drupal\amazons3;

use Aws\Credentials\Credentials;
use Drupal\amazons3\Matchable\BasicPath;
use Drupal\amazons3\Matchable\MatchablePaths;
use Drupal\amazons3\Matchable\PresignedPath;

class Config extends \ArrayObject {

  /**
   * Amazon S3 API version.
   *
   * @var string
   */
  const API_VERSION = '2006-03-01';

  /**
   * Drupal configuration object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * Instantiates a new config class for the Amazon S3 stream wrapper.
   */
  public function __construct() {
    $this->config = \Drupal::config('amazons3.settings');
  }

  /**
   * Retrieves the bucket name.
   *
   * @return string
   *   Value of the bucket setting.
   */
  public function bucket() {
    return $this->get('bucket');
  }

  /**
   * Retrieves the access key.
   *
   * @return string
   *   Value of the access key setting.
   */
  public function key() {
    return $this->get('key');
  }

  /**
   * Retrieves the secret key.
   *
   * @return string
   *   Value of the secret key setting.
   */
  public function secret() {
    return $this->get('secret');
  }

  /**
   * Retrieves the cache setting.
   *
   * @return boolean
   *   Whether or not caching is enabled.
   */
  public function cache() {
    return $this->get('cache', FALSE);
  }

  /**
   * Retrieves the CNAME setting.
   *
   * @return boolean
   *   Whether or not a CNAME should be used.
   */
  public function cname() {
    return $this->get('cname', FALSE);
  }

  /**
   * Retrieves the domain setting.
   *
   * @return boolean
   *   Value of the domain setting.
   */
  public function domain() {
    return $this->get('domain');
  }

  /**
   * Retrieves the CloudFront setting.
   *
   * @return boolean
   *   Whether or not CloudFront is enabled.
   */
  public function cloudfront() {
    return $this->get('cloudfront', FALSE);
  }

  /**
   * Retrieves the CloudFront keypair id setting.
   *
   * @return boolean
   *   ID for the CloudFront keypair.
   */
  public function cloudfront_keypair_id() {
    return $this->get('cloudfront_keypair_id');
  }

  /**
   * Retrieves the CloudFront private key setting.
   *
   * @return boolean
   *   Private key to use for CloudFront.
   */
  public function cloudfront_private_key() {
    return $this->get('cloudfront_private_key');
  }

  /**
   * Retrieves the hostname setting.
   *
   * @return boolean
   *   Value of the hostname setting.
   */
  public function hostname() {
    return $this->get('hostname');
  }

  /**
   * Retrieves the file URI scheme override setting.
   *
   * @return boolean
   *   Value of the file URI scheme override setting.
   */
  public function file_uri_scheme_override() {
    return $this->get('file_uri_scheme_override', FALSE);
  }

  /**
   * Retrieves the migrate credentials setting.
   *
   * @return boolean
   *   Value of the migrate credentials setting.
   */
  public function migrate_credentials() {
    return $this->get('migrate_credentials', TRUE);
  }

  public function saveas() {
    $paths = $this->get('saveas', array());
    $paths = BasicPath::factory($paths);

    return new MatchablePaths($paths);
  }

  public function torrents() {
    $paths = $this->get('torrents', array());
    $paths = BasicPath::factory($paths);

    return new MatchablePaths($paths);
  }

  public function presigned_paths() {
    $paths = array();
    foreach ($this->get('presigned_urls', array()) as $presigned_url) {
      $paths[] = new PresignedPath($presigned_url['pattern'], $presigned_url['timeout']);
    }

    return new MatchablePaths($paths);
  }

  public function rrs() {
    $paths = $this->get('rrs', array());
    $paths = BasicPath::factory($paths);

    return new MatchablePaths($paths);
  }

  /**
   * Retrieves the value of a configuration setting.
   *
   * @param string $name
   *   Name of the setting to retrieve.
   * @param mixed $default
   *
   * @return mixed
   *   Value of the setting or the default value if it was not set.
   */
  public function get($name, $default = NULL) {
    return ($this->config->get($name) ?: $default);
  }

  public function region() {
    return $this->get('region', 'us-east-1');
  }

  public function version() {
    return self::API_VERSION;
  }

  /**
   * Creates credentials using the access and secret keys.
   *
   * @return \Aws\Credentials\Credentials
   *   Credentials to be used to access Amazon S3.
   */
  public function credentials() {
    return new Credentials($this->key(), $this->secret());
  }

  /**
   * Builds an array of options that can be used for the S3 client.
   *
   * @return array
   */
  public function clientOptions() {
    return array(
      'bucket' => $this->bucket(),
      'credentials' => $this->credentials(),
      'region' => $this->region(),
      'version' => $this->version(),
      'endpoint' => $this->hostname(),
    );
  }

  public function offsetGet($key) {
    if (!method_exists($this, $key)) {
      throw new \Exception("Configuration key $key does not exist.");
    }

    return $this->$key;
  }

}
