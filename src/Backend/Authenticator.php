<?php

declare(strict_types = 1);

namespace Drupal\webdav\Backend;

use Sabre\DAV\Auth\Backend\AbstractBasic;

/**
 * A WebDAV Authenticator.
 */
final class Authenticator extends AbstractBasic {

  /**
   * {@inheritdoc}
   */
  protected function validateUserPass($username, $password) {
    if (!$account = user_load_by_name($username)) {
      return FALSE;
    }

    if (!$account->isActive()) {
      return FALSE;
    }

    // @todo Use dependency injection instead of static service call.
    $password_hasher = \Drupal::service('password');
    if (!$password_hasher->check($password, $account->pass->value)) {
      return FALSE;
    }

    return TRUE;
  }

}
