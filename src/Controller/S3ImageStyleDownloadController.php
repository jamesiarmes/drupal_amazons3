<?php

/**
 * @file
 * Contains \Drupal\image\Controller\ImageStyleDownloadController.
 */

namespace Drupal\amazons3\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\amazons3\S3Url;
use Drupal\image\ImageStyleInterface;
use Drupal\system\FileDownloadController;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Defines a controller to serve image styles.
 */
class S3ImageStyleDownloadController extends FileDownloadController {

  /**
   * The lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The image factory.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a ImageStyleDownloadController object.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   The image factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(LockBackendInterface $lock, ImageFactory $image_factory, LoggerInterface $logger) {
    $this->lock = $lock;
    $this->imageFactory = $image_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('lock'),
      $container->get('image.factory'),
      $container->get('logger.factory')->get('image')
    );
  }

  /**
   * Generates a derivative, given a style and image path.
   *
   * After generating an image, transfer it to the requesting agent.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $amazons3_bucket
   *   TODO
   * @param \Drupal\image\ImageStyleInterface $image_style
   *   The image style to deliver.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the user does not have access to the file.
   * @throws \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException
   *   Thrown when the file is still being generated.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\Response
   *   The transferred file as response or some error response.
   */
  public function deliver(Request $request, $amazons3_bucket, ImageStyleInterface $image_style) {
    $file = $request->query->get('file');
    $source = new S3Url($amazons3_bucket, $file);
    $image_uri = $destination = $source->getImageStyleUrl($image_style->getName());

    $s = (string) $source;
    $derivative_uri = (string) $destination;
    // Check that the style is defined, the scheme is valid, and the image
    // derivative token is valid. Sites which require image derivatives to be
    // generated without a token can set the
    // 'image.settings:allow_insecure_derivatives' configuration to TRUE to
    // bypass the latter check, but this will increase the site's vulnerability
    // to denial-of-service attacks.
//    $valid = !empty($image_style);
//    if (!$this->config('image.settings')->get('allow_insecure_derivatives')) {
//      $valid &= $request->query->get(IMAGE_DERIVATIVE_TOKEN) === $image_style->getPathToken($image_uri);
//    }
//    if (!$valid) {
//      throw new AccessDeniedHttpException();
//    }

//    $derivative_uri = $image_style->buildUri($image_uri);
    $headers = array();

    // Don't try to generate file if source is missing.
    if (!file_exists($image_uri)) {
      // If the image style converted the extension, it has been added to the
      // original file, resulting in filenames like image.png.jpeg. So to find
      // the actual source image, we remove the extension and check if that
      // image exists.
      $path_info = pathinfo($image_uri);
      $converted_image_uri = $path_info['dirname'] . DIRECTORY_SEPARATOR . $path_info['filename'];
      if (!file_exists($converted_image_uri)) {
        $this->logger->notice('Source image at %source_image_path not found while trying to generate derivative image at %derivative_path.',  array('%source_image_path' => $image_uri, '%derivative_path' => $derivative_uri));
        return new Response($this->t('Error generating image, missing source file.'), 404);
      }
      else {
        // The converted file does exist, use it as the source.
        $image_uri = $converted_image_uri;
      }
    }

    // Don't start generating the image if the derivative already exists or if
    // generation is in progress in another thread.
    $lock_name = 'amazons3_image_style_deliver:' . $image_style->id() . ':' . Crypt::hashBase64($image_uri);
    if (!file_exists($derivative_uri)) {
      $lock_acquired = $this->lock->acquire($lock_name);
      if (!$lock_acquired) {
        // Tell client to retry again in 3 seconds. Currently no browsers are
        // known to support Retry-After.
        // TODO _amazons3_image_wait_transfer
        throw new ServiceUnavailableHttpException(3, $this->t('Image generation in progress. Try again shortly.'));
      }
    }
    else {
      $wrapper = \Drupal::service("stream_wrapper_manager")->getViaUri($destination);
      return new RedirectResponse($wrapper->getExternalUrl(), 301);
    }

    $destination_temp = 'temporary://amazons3/' . $destination->getKey();

    // Try to generate the image, unless another thread just did it while we
    // were acquiring the lock.
    $success = file_exists($derivative_uri) || $image_style->createDerivative($image_uri, $derivative_uri);

    if (!empty($lock_acquired)) {
      $this->lock->release($lock_name);
    }

    if ($success) {
      $image = $this->imageFactory->get($derivative_uri);
      // Register a shutdown function to upload the image to S3.
      register_shutdown_function(function() use ($image, $destination) {
        // We have to call both of these to actually flush the image.
        ob_end_flush();
        flush();

        // file_unmanaged_copy() will not create any nested directories if needed.
        $directory = drupal_dirname($destination);
        if (!file_prepare_directory($directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) {
          \Drupal::logger('amazons3')->error('Failed to create style directory: %directory', array('%directory' => $directory));
        }

        file_unmanaged_copy($image->source, $destination);
      });
      $uri = $image->getSource();
      $headers += array(
        'Content-Type' => $image->getMimeType(),
        'Content-Length' => $image->getFileSize(),
      );
      return new BinaryFileResponse($uri, 200, $headers);
    }
    else {
      $this->logger->notice('Unable to generate the derived image located at %path.', array('%path' => $derivative_uri));
      return new Response($this->t('Error generating image.'), 500);
    }
  }

}
