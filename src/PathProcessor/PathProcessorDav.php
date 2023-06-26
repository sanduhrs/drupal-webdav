<?php

declare(strict_types = 1);

namespace Drupal\webdav\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Path processor to allow for slashes in route parameter.
 */
final class PathProcessorDav implements InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request): string {
    if (strpos($path, '/webdav/') === 0) {
      $subpath = preg_replace('|^\/webdav\/|', '', $path);
      return "/webdav/" . urlencode($subpath);
    }
    return $path;
  }

}
