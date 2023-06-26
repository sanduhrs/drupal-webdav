<?php

declare(strict_types = 1);

namespace Drupal\webdav\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Returns responses for DAV routes.
 */
final class WellKnownController extends ControllerBase {

  /**
   * Well-known CalDAV redirect.
   */
  public function caldav() {
    $url = Url::fromRoute('dav.dav', ['path' => 'calendars']);
    return new RedirectResponse($url->toString());
  }

  /**
   * Well-known CardDAV redirect.
   */
  public function carddav() {
    $url = Url::fromRoute('dav.dav', ['path' => 'addressbooks']);
    return new RedirectResponse($url->toString());
  }

}
