<?php

declare(strict_types=1);

namespace Drupal\webdav;

use Sabre\DAV\Server as SabreDavServer;
use Sabre\HTTP\Sapi;
use Symfony\Component\HttpFoundation\Response;

/**
 * Main DAV server class wrapper to convert the response to Symfony.
 */
final class Server extends SabreDavServer {

  /**
   * The constructor.
   */
  // phpcs:ignore
  public function __construct($treeOrNode = NULL, Sapi $sapi = NULL) {
    parent::__construct($treeOrNode, $sapi);
  }

  /**
   * Response converter.
   */
  public function start() {
    ob_start();
    parent::start();
    $output = ob_get_contents();
    ob_end_clean();

    $response = new Response($output, http_response_code(), []);
    foreach (headers_list() as $header) {
      [$name, $value] = explode(':', $header);
      $response->headers->set($name, $value);
    }

    return $response;
  }

}
