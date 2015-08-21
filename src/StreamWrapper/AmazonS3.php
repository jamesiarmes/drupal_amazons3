<?php
/**
 * @file
 * Contains \Drupal\amazons3\StreamWrapper\AmazonS3
 */

namespace Drupal\amazons3\StreamWrapper;

use Drupal\amazons3\Config;
use \Drupal\amazons3\S3Url;

use \Drupal\Core\StreamWrapper\StreamWrapperInterface;
use \Doctrine\Common\Cache\ArrayCache;
use \Doctrine\Common\Cache\ChainCache;
use \Drupal\amazons3\Matchable\BasicPath;
use \Drupal\amazons3\Matchable\PresignedPath;
use \Guzzle\Cache\DoctrineCacheAdapter;

use \Aws\S3\S3Client;
use \Aws\CacheInterface;
use \Aws\Credentials\Credentials;

use \Guzzle\Http\Url;

/**
 * ImplementsStreamWrapperInterface to provide an Amazon S3 wrapper with the
 * s3:// prefix.
 */
class AmazonS3 extends \Aws\S3\StreamWrapper implements StreamWrapperInterface {
  use \Drupal\amazons3\DrupalAdapter\Common;
  use \Drupal\amazons3\DrupalAdapter\FileMimetypes;

  /**
   * The base domain of S3.
   *
   * @const string
   */
  const S3_API_DOMAIN = 's3.amazonaws.com';

  /**
   * The path to the image style generation callback.
   *
   * If this is changed, be sure to update amazons3_menu() as well.
   *
   * @const string
   */
  const stylesCallback = 'amazons3/image-derivative';

  /**
   * The name of the S3Client class to use.
   *
   * @var string
   */
  protected static $s3ClientClass = '\Aws\S3\S3Client';

  /**
   * Default configuration used when constructing a new stream wrapper.
   *
   * @var \Drupal\amazons3\StreamWrapperConfiguration
   */
  protected static $defaultConfig;

  /**
   * @var \Aws\S3\S3Client
   */
  protected $client;

  /**
   * Configuration for this stream wrapper.
   *
   * @var \Drupal\amazons3\Config
   */
  protected $config;

  /**
   * Instance URI referenced as "s3://bucket/key"
   *
   * @var \Drupal\amazons3\S3Url
   */
  protected $uri;

  /**
   * The URL associated with the S3 object.
   *
   * @var \Drupal\amazons3\S3Url
   */
  protected $s3Url;

  /**
   * Set default configuration to use when constructing a new stream wrapper.
   *
   * @param \Drupal\amazons3\StreamWrapperConfiguration $config
   */
  public static function setDefaultConfig(StreamWrapperConfiguration $config) {
    static::$defaultConfig = $config;
  }

  /**
   * Return the default configuration.
   *
   * @return \Drupal\amazons3\StreamWrapperConfiguration
   */
  public static function getDefaultConfig() {
    return static::$defaultConfig;
  }

  /**
   * Set the name of the S3Client class to use.
   *
   * @param string $client
   */
  public static function setS3ClientClass($client) {
    static::$s3ClientClass = $client;
  }

  /**
   * Construct a new stream wrapper.
   *
   * @param \Drupal\amazons3\StreamWrapperConfiguration $config
   *   (optional) A specific configuration to use for this wrapper.
   */
  public function __construct(StreamWrapperConfiguration $config = NULL) {
    if (!$config) {
      if (static::$defaultConfig) {
        $config = static::$defaultConfig;
      }
      else {
        // @codeCoverageIgnoreStartStreamWrapperConfiguration
        $config = new Config();
        // @codeCoverageIgnoreEnd
      }
    }

    // TODO: Accept the proper object.
    $this->config = new Config();

    if (!$this->getClient()) {
      /** @var S3Client $name */
      $name = static::$s3ClientClass;
      $client = new $name($config->asArray());
      $this->setClient($client);
    }

    if ($this->config->cache() && !static::$cache) {
      static::attachCache(
        new DoctrineCacheAdapter(new ChainCache([new ArrayCache(), new Cache()])),
        $this->config->getCacheLifetime()
      );
    }
  }

  /**
   * Get the client associated with this stream wrapper.
   *
   * @return \Aws\S3\S3Client
   */
  public function getClient() {
    return $this->client;
  }

  /**
   * Set the client associated with this stream wrapper.
   *
   * Note that all stream wrapper instances share a global client.
   *
   * @param \Aws\S3\S3Client $client
   *   The client to use. Set to NULL to remove an existing client.
   */
  public function setClient(S3Client $client = NULL) {
    $this->client = $client;
    stream_context_set_option(stream_context_get_default(), 's3', 'client', $client);
    stream_context_set_option(stream_context_get_default(), 's3', 'ACL', 'public-read');
  }

  /**
   * Support for flock().
   *
   * S3 has no support for file locking. If it's needed, it has to be
   * implemented at the application layer.
   *
   * @link https://docs.aws.amazon.com/AmazonS3/latest/API/RESTObjectPUT.html
   *
   * @param string $operation
   *   One of the following:
   *   - LOCK_SH to acquire a shared lock (reader).
   *   - LOCK_EX to acquire an exclusive lock (writer).
   *   - LOCK_UN to release a lock (shared or exclusive).
   *   - LOCK_NB if you don't want flock() to block while locking (not
   *     supported on Windows).
   *
   * @return bool
   *   returns TRUE if lock was successful
   *
   * @link http://php.net/manual/en/streamwrapper.stream-lock.php
   *
   * @codeCoverageIgnore
   */
  public function stream_lock($operation) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  function setUri($uri) {
    // file_stream_wrapper_get_instance_by_scheme() assumes that all schemesb
    // can work without a directory, but S3 requires a bucket. If a raw scheme
    // is passed in, we append our default bucket.
    if ($uri == 's3://') {
      $uri = 's3://' . $this->config->bucket();
    }

    $this->uri = S3Url::factory($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function getUri() {
    return (string) $this->uri;
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    if (!isset($this->uri)) {
      throw new \LogicException('A URI must be set before calling getExternalUrl().');
    }

    $path_segments = $this->uri->getPathSegments();
    $args = array();

    // Image styles support
    // Delivers the first request to an image from the private file system
    // otherwise it returns an external URL to an image that has not been
    // created yet.
    if (!empty($path_segments) && $path_segments[0] === 'styles' && !file_exists((string) $this->uri)) {
      return $this->url($this::stylesCallback . '/' . $this->uri->getBucket() . $this->uri->getPath(), array('absolute' => TRUE));
    }

    // UI overrides.

    // Save as.
    $expiry = NULL;
    if ($this->forceDownload()) {
      $args['ResponseContentDisposition'] = $this->getContentDispositionAttachment();
      $expiry = time() + 60 * 60 * 24;
    }

    // Torrent URLs.
    $path = $this->getLocalPath();
    if ($this->useTorrent()) {
      $path .= '?torrent';
    }

    if ($presigned = $this->usePresigned()) {
      $expiry = time() + $presigned->getTimeout();
    }

    // @codeCoverageIgnoreStart
    if ($expiry && $this->config->cloudfront()) {
      $url = $this->getCloudFrontUrl($path, $expiry);
    }
    // @codeCoverageIgnoreEnd
    else {
      // Generate a standard URL.
      $url = $this->getS3Url($path, $expiry, $args);
    }

    return (string) $url;
  }

  /**
   * {@inheritdoc}
   */
  public static function getMimeType($uri, $mapping = NULL) {
    // Load the default file map.
    // @codeCoverageIgnoreStart
    if (!$mapping) {
      $mapping = static::file_mimetype_mapping();
    }
    // @codeCoverageIgnoreEnd

    $extension = '';
    $file_parts = explode('.', basename($uri));

    // Remove the first part: a full filename should not match an extension.
    array_shift($file_parts);

    // Iterate over the file parts, trying to find a match.
    // For my.awesome.image.jpeg, we try:
    // jpeg
    // image.jpeg, and
    // awesome.image.jpeg
    while ($additional_part = array_pop($file_parts)) {
      $extension = strtolower($additional_part . ($extension ? '.' . $extension : ''));
      if (isset($mapping['extensions'][$extension])) {
        return $mapping['mimetypes'][$mapping['extensions'][$extension]];
      }
      // @codeCoverageIgnoreStart
    }
    // @codeCoverageIgnoreEnd

    return 'application/octet-stream';
  }

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public function chmod($mode) {
    // TODO: Implement chmod() method.
    return TRUE;
  }

  /**
   * @return bool
   *   FALSE, as this stream wrapper does not support realpath().
   *
   * @codeCoverageIgnore
   */
  public function realpath() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL) {
    // drupal_dirname() doesn't call setUri() before calling. That lead our URI
    // to be stuck at the default 's3://'' that is set by
    // file_stream_wrapper_get_instance_by_scheme().
    if ($uri) {
      $this->setUri($uri);
    }

    $s3url = S3Url::factory($uri, $this->config);
    $s3url->normalizePath();
    $pathSegments = $s3url->getPathSegments();
    array_pop($pathSegments);
    $s3url->setPath($pathSegments);
    return trim((string) $s3url, '/');
  }

  /**
   * Return the local filesystem path.
   *
   * @return string
   *   The local path.
   */
  protected function getLocalPath() {
    $path = $this->uri->getPath();
    $path = trim($path, '/');
    return $path;
  }

  /**
   * Override register() to force using hook_stream_wrappers().
   *
   * {@inheritdoc}
   */
  public static function register(
    S3Client $client,
    $protocol = 's3',
    CacheInterface $cache = NULL
  ) {
    throw new \LogicException('Drupal handles registration of stream wrappers. Implement hook_stream_wrappers() instead.');
  }

  /**
   * Override getOptions() to default all files to be publicly readable.
   *
   * {@inheritdoc}
   */
  public function getOptions($removeContextData = false) {
    if (!isset($this->uri)) {
      throw new \LogicException('A URI must be set before calling getOptions().');
    }

    $options = parent::getOptions();
    $options['ACL'] = 'public-read';

    if ($this->useRrs()) {
      $options['StorageClass'] = 'REDUCED_REDUNDANCY';
    }

    return $options;
  }

  /**
   * Return the basename for this URI.
   *
   * @return string
   *   The basename of the URI.
   */
  public function getBasename() {
    if (!isset($this->uri)) {
      throw new \LogicException('A URI must be set before calling getBasename().');
    }

    return basename($this->getLocalPath());
  }

  /**
   * Return a string to use as a Content-Disposition header.
   *
   * @return string
   *   The header value.
   */
  protected function getContentDispositionAttachment() {
    // Encode the filename according to RFC2047.
    return 'attachment; filename="' . mb_encode_mimeheader($this->getBasename()) . '"';
  }

  /**
   * Find if this URI should force a download.
   *
   * @return BasicPath|bool
   *   The BasicPath if the local path of the stream URI should force a
   *   download, FALSE otherwise.
   */
  protected function forceDownload() {
    return $this->config->saveas()->match($this->getLocalPath());
  }

  /**
   * Find if the URL should be returned as a torrent.
   *
   * @return BasicPath|bool
   *   The BasicPath if a torrent should be served, FALSE otherwise.
   */
  protected function useTorrent() {
    return $this->config->torrents()->match($this->getLocalPath());
  }

  /**
   * Find if the URL should be presigned.
   *
   * @return PresignedPath|bool
   *   The matching PresignedPath if a presigned URL should be served, FALSE
   *   otherwise.
   */
  protected function usePresigned() {
    return $this->config->presigned_paths()->match($this->getLocalPath());
  }

  /**
   * Find if the URL should be saved to Reduced Redundancy Storage.
   *
   * @return PresignedPath|bool
   *   The matching PresignedPath if a presigned URL should be served, FALSE
   *   otherwise.
   */
  protected function useRrs() {
    return $this->config->rrs()->match($this->getLocalPath());
  }

  /**
   * Replace the host in a URL with the configured domain.
   *
   * @param Url $url
   *   The URL to modify.
   */
  protected function injectCname($url) {
    if (!empty($this->config->domain()) && strpos($url->getHost(), $this->config->domain()) === FALSE) {
      $url->setHost($this->config->domain());
    }
  }

  /**
   * Get a CloudFront URL for an S3 key.
   *
   * @param $key
   *   The S3 object key.
   * @param int $expiry
   *   (optional) Expiry time for the URL, as a Unix timestamp.
   * @return \Guzzle\Http\Url
   *   The CloudFront URL.
   */
  protected function getCloudFrontUrl($key, $expiry = NULL) {
    // Real CloudFront credentials are required to test this, so we ignore
    // testing this.
    // @codeCoverageIgnoreStart
    $cf = $this->config->getCloudFront();
    $url = new Url('https', $this->config->getDomain());
    $url->setPath($key);
    $this->injectCname($url);
    $options = array(
      'url' => (string) $url,
      'expires' => $expiry,
    );
    $url = Url::factory($cf->getSignedUrl($options));
    return $url;
    // @codeCoverageIgnoreEnd
  }

  /**
   * Get a regular S3 URL for a key.
   *
   * @param string $key
   *   The S3 object key.
   * @param int $expiry
   *   (optional) Expiry time for the URL, as a Unix timestamp.
   * @param array $args
   *   (optional) Array of additional arguments to pass to getObjectUrl().
   *
   * @return \Guzzle\Http\Url
   *   An https URL to access the key.
   */
  protected function getS3Url($key, $expiry = NULL, array $args = array()) {
    $url = Url::factory(
      $this->client->getObjectUrl(
        $this->uri->getBucket(),
        $key,
        $expiry,
        $args
      )
    );
    $this->injectCname($url);
    return $url;
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($path) {
    $this->setUri($path);
    return parent::unlink($path);
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($path, $mode, $options) {
    $this->setUri($path);
    $result = parent::mkdir($path, $mode, $options);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path) {
    $this->setUri($path);
    return parent::stream_open($path, $mode, $options, $opened_path);
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags) {
    $this->setUri($path);
    return parent::url_stat($path, $flags);
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($path, $options) {
    $this->setUri($path);
    return parent::rmdir($path, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($path, $options) {
    $this->setUri($path);
    return parent::dir_opendir($path, $options);
  }

  /**
   * Override rename() to handle setting the URI on move.
   *
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to) {
    $this->setUri($path_from);
    $partsFrom = $this->getParams($path_from);

    $this->setUri($path_to);
    $partsTo = $this->getParams($path_to);

    // We ignore testing this block since it is copied directly from the parent
    // method which is covered by the AWS SDK tests.
    // @codeCoverageIgnoreStart
    $this->clearStatInfo($path_from);
    $this->clearStatInfo($path_to);

    if (!$partsFrom['Key'] || !$partsTo['Key']) {
      return $this->triggerError('The Amazon S3 stream wrapper only supports copying objects');
    }

    try {
      // Copy the object and allow overriding default parameters if desired, but by default copy metadata
      static::$client->copyObject($this->getOptions() + array(
          'Bucket' => $partsTo['Bucket'],
          'Key' => $partsTo['Key'],
          'CopySource' => '/' . $partsFrom['Bucket'] . '/' . rawurlencode($partsFrom['Key']),
          'MetadataDirective' => 'COPY'
        ));
      // Delete the original object
      static::$client->deleteObject(array(
          'Bucket' => $partsFrom['Bucket'],
          'Key'    => $partsFrom['Key']
        ) + $this->getOptions());
    } catch (\Exception $e) {
      return $this->triggerError($e->getMessage());
    }
    // @codeCoverageIgnoreEnd

    return true;
  }

  /**
   * Returns the type of stream wrapper.
   *
   * @return int
   */
  public static function getType() {
    return StreamWrapperInterface::LOCAL_NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return t('Amazon S3');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return t('Files stored and served from Amazon Simple Storage Solution.');
  }

  /**
   * Sets metadata on the stream.
   *
   * @param string $path
   *   A string containing the URI to the file to set metadata on.
   * @param int $option
   *   One of:
   *   - STREAM_META_TOUCH: The method was called in response to touch().
   *   - STREAM_META_OWNER_NAME: The method was called in response to chown()
   *     with string parameter.
   *   - STREAM_META_OWNER: The method was called in response to chown().
   *   - STREAM_META_GROUP_NAME: The method was called in response to chgrp().
   *   - STREAM_META_GROUP: The method was called in response to chgrp().
   *   - STREAM_META_ACCESS: The method was called in response to chmod().
   * @param mixed $value
   *   If option is:
   *   - STREAM_META_TOUCH: Array consisting of two arguments of the touch()
   *     function.
   *   - STREAM_META_OWNER_NAME or STREAM_META_GROUP_NAME: The name of the owner
   *     user/group as string.
   *   - STREAM_META_OWNER or STREAM_META_GROUP: The value of the owner
   *     user/group as integer.
   *   - STREAM_META_ACCESS: The argument of the chmod() as integer.
   *
   * @return bool
   *   Returns TRUE on success or FALSE on failure. If $option is not
   *   implemented, FALSE should be returned.
   *
   * @see http://www.php.net/manual/streamwrapper.stream-metadata.php
   */
  public function stream_metadata($path, $option, $value)
  {
    // TODO: Implement stream_metadata() method.
  }

  /**
   * Change stream options.
   *
   * This method is called to set options on the stream.
   *
   * @param int $option
   *   One of:
   *   - STREAM_OPTION_BLOCKING: The method was called in response to
   *     stream_set_blocking().
   *   - STREAM_OPTION_READ_TIMEOUT: The method was called in response to
   *     stream_set_timeout().
   *   - STREAM_OPTION_WRITE_BUFFER: The method was called in response to
   *     stream_set_write_buffer().
   * @param int $arg1
   *   If option is:
   *   - STREAM_OPTION_BLOCKING: The requested blocking mode:
   *     - 1 means blocking.
   *     - 0 means not blocking.
   *   - STREAM_OPTION_READ_TIMEOUT: The timeout in seconds.
   *   - STREAM_OPTION_WRITE_BUFFER: The buffer mode, STREAM_BUFFER_NONE or
   *     STREAM_BUFFER_FULL.
   * @param int $arg2
   *   If option is:
   *   - STREAM_OPTION_BLOCKING: This option is not set.
   *   - STREAM_OPTION_READ_TIMEOUT: The timeout in microseconds.
   *   - STREAM_OPTION_WRITE_BUFFER: The requested buffer size.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise. If $option is not implemented, FALSE
   *   should be returned.
   */
  public function stream_set_option($option, $arg1, $arg2)
  {
    // TODO: Implement stream_set_option() method.
  }

  /**
   * Truncate stream.
   *
   * Will respond to truncation; e.g., through ftruncate().
   *
   * @param int $new_size
   *   The new size.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   */
  public function stream_truncate($new_size)
  {
    // TODO: Implement stream_truncate() method.
  }
}
