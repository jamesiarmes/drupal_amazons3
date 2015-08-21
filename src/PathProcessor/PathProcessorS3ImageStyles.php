<?php

/**
 * @file
 * Contains \Drupal\image\PathProcessor\PathProcessorImageStyles.
 */

namespace Drupal\amazons3\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines a path processor to rewrite image styles URLs.
 *
 * As the route system does not allow arbitrary amount of parameters convert
 * the file path to a query parameter on the request.
 *
 * This processor handles two different cases:
 * - public image styles: In order to allow the webserver to serve these files
 *   directly, the route is registered under the same path as the image style so
 *   it took over the first generation. Therefore the path processor converts
 *   the file path to a query parameter.
 * - private image styles: In contrast to public image styles, private
 *   derivatives are already using system/files/styles. Similar to public image
 *   styles, it also converts the file path to a query parameter.
 */
class PathProcessorS3ImageStyles implements InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    $path_prefix = '/amazons3/';
    if (strpos($path, $path_prefix) !== 0) {
      return $path;
    }

    // Strip out path prefix.
    $rest = substr($path, strlen($path_prefix));

    // Get the image style, scheme and path.
    $count = substr_count($rest, '/');
    if (substr_count($rest, '/') >= 3) {
      list($bucket, $_, $image_style, $file) = explode('/', $rest, 4);

      // Set the file as query parameter.
      $request->query->set('file', $file);

      $path = "{$path_prefix}{$bucket}/styles/{$image_style}";
    }

    return $path;
  }
}
