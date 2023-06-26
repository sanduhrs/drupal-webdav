<?php

declare(strict_types = 1);

namespace Drupal\webdav\Backend;

use Drupal\Core\Database\Connection;
use Sabre\DAV\Locks\Backend\AbstractBackend;
use Sabre\DAV\Locks\LockInfo;

/**
 * A Lock backend for Drupal.
 */
final class Lock extends AbstractBackend {

  /**
   * Constructs the object.
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getLocks($uri, $returnChildLocks) {
    $query = $this->connection
      ->select('webdav__locks', 'dl');
    $query
      // phpcs:ignore
      ->fields('dl', ['owner', 'token', 'timeout', 'created', 'scope', 'depth', 'uri'])
      ->condition('created', time() . ' - timeout', '>')
      ->condition('uri', $uri);

    // We need to check locks for every part in the uri.
    $uriParts = explode('/', $uri);

    // We already covered the last part of the uri.
    array_pop($uriParts);

    $currentPath = '';

    foreach ($uriParts as $part) {
      if ($currentPath) {
        $currentPath .= '/';
      }
      $currentPath .= $part;

      $orGroup = $query
        ->orConditionGroup()
        ->condition('depth', 0, '!=')
        ->condition('uri', $currentPath);
      $query
        ->condition($orGroup);
    }

    if ($returnChildLocks) {
      $orGroup = $query->orConditionGroup()
        ->condition('uri', $uri . '/%', 'LIKE');
      $query
        ->condition($orGroup);
    }
    $result = $query
      ->execute();

    $lockList = [];
    while ($row = $result->fetchAssoc()) {
      $lockInfo = new LockInfo();
      $lockInfo->owner = $row['owner'];
      $lockInfo->token = $row['token'];
      $lockInfo->timeout = $row['timeout'];
      $lockInfo->created = $row['created'];
      $lockInfo->scope = $row['scope'];
      $lockInfo->depth = $row['depth'];
      $lockInfo->uri = $row['uri'];
      $lockList[] = $lockInfo;
    }

    return $lockList;
  }

  /**
   * {@inheritdoc}
   */
  public function lock($uri, LockInfo $lockInfo) {
    // We're making the lock timeout 30 minutes.
    $lockInfo->timeout = 30 * 60;
    $lockInfo->created = time();
    $lockInfo->uri = $uri;

    $locks = $this->getLocks($uri, FALSE);

    $exists = FALSE;
    foreach ($locks as $lock) {
      if ($lock->token == $lockInfo->token) {
        $exists = TRUE;
      }
    }

    if ($exists) {
      $query = $this->connection
        ->update('webdav__locks');
      $query
        ->fields([
          'owner' => $lockInfo->owner,
          'timeout' => $lockInfo->timeout,
          'scope' => $lockInfo->scope,
          'depth' => $lockInfo->depth,
          'uri' => $uri,
          'created' => $lockInfo->created,
        ])
        ->condition('token', $lockInfo->token);
    }
    else {
      $query = $this->connection
        ->insert('webdav__locks');
      $query
        ->fields([
          'owner' => $lockInfo->owner,
          'timeout' => $lockInfo->timeout,
          'scope' => $lockInfo->scope,
          'depth' => $lockInfo->depth,
          'uri' => $uri,
          'created' => $lockInfo->created,
          'token' => $lockInfo->token,
        ]);
    }
    $result = $query
      ->execute();

    return (bool) $result;
  }

  /**
   * {@inheritdoc}
   */
  public function unlock($uri, LockInfo $lockInfo) {
    $query = $this->connection
      ->delete('webdav__locks');
    $query
      ->condition('uri', $uri)
      ->condition('token', $lockInfo->token);
    $result = $query
      ->execute();

    return (bool) $result;
  }

}
