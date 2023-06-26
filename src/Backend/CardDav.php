<?php

declare(strict_types = 1);

namespace Drupal\webdav\Backend;

use Drupal\Core\Database\Connection;
use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\CardDAV\Backend\SyncSupport;
use Sabre\CardDAV\Plugin;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\PropPatch;

/**
 * A CardDav backend for Drupal.
 */
final class CardDav extends AbstractBackend implements SyncSupport {

  /**
   * Constructs the object.
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getAddressBooksForUser($principalUri) {
    $query = $this->connection
      ->select('webdav__addressbooks', 'da');
    $query
      ->fields('da', ['id', 'uri', 'displayname', 'principaluri', 'description'])
      ->condition('principaluri', $principalUri);
    $result = $query
      ->execute();

    $addressBooks = [];
    while ($row = $result->fetchAssoc()) {
      $addressBooks[] = [
        'id' => $row['id'],
        'uri' => $row['uri'],
        '{DAV:}displayname' => $row['displayname'],
        'principaluri' => $row['principaluri'],
        '{' . Plugin::NS_CARDDAV . '}addressbook-description' => $row['description'],
        // @todo Where does the synctoken come from?
        // '{http://calendarserver.org/ns/}getctag' => $row['synctoken'],
        '{http://sabredav.org/ns}sync-token' => $row['synctoken'] ?: '0',
      ];
    }

    return $addressBooks;
  }

  /**
   * {@inheritdoc}
   */
  public function updateAddressBook($addressBookId, PropPatch $propPatch) {
    $supportedProperties = [
      '{DAV:}displayname',
      '{' . Plugin::NS_CARDDAV . '}addressbook-description',
    ];

    $propPatch->handle($supportedProperties, function ($mutations) use ($addressBookId) {
      $updates = [];
      foreach ($mutations as $property => $newValue) {
        switch ($property) {
          case '{DAV:}displayname':
            $updates['displayname'] = $newValue;
            break;

          case '{' . Plugin::NS_CARDDAV . '}addressbook-description':
            $updates['description'] = $newValue;
            break;

        }
      }

      $query = $this->connection
        ->update('webdav__addressbooks');
      $query
        ->fields($updates)
        ->condition('id', $addressBookId);
      $query
        ->execute();

      $this->addChange($addressBookId, '', 2);

      return TRUE;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function createAddressBook($principalUri, $url, array $properties) {
    $values = [
      'uri' => $url,
      'displayname' => NULL,
      'description' => NULL,
      'principaluri' => $principalUri,
      'synctoken' => 1,
    ];

    foreach ($properties as $property => $newValue) {
      switch ($property) {
        case '{DAV:}displayname':
          $values['displayname'] = $newValue;
          break;

        case '{' . Plugin::NS_CARDDAV . '}addressbook-description':
          $values['description'] = $newValue;
          break;

        default:
          throw new BadRequest('Unknown property: ' . $property);
      }
    }

    $query = $this->connection
      ->insert('webdav__addressbooks');
    $query
      ->fields($values);
    $result = $query
      ->execute();

    return (int) $result;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAddressBook($addressBookId) {
    $query = $this->connection
      ->delete('webdav__cards');
    $query
      ->condition('addressbookid', $addressBookId);
    $query
      ->execute();

    $query = $this->connection
      ->delete('webdav__addressbooks');
    $query
      ->condition('id', $addressBookId);
    $query
      ->execute();

    $query = $this->connection
      ->delete('webdav__addressbookchanges');
    $query
      ->condition('addressbookid', $addressBookId);
    $query
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getCards($addressbookId) {
    $query = $this->connection
      ->select('webdav__cards', 'dc');
    $query
      ->fields('dc', ['id', 'uri', 'lastmodified', 'etag', 'size'])
      ->condition('addressbookid', $addressbookId);
    $result = $query
      ->execute();

    $cards = [];
    while ($row = $result->fetchAssoc()) {
      $row['etag'] = '"' . $row['etag'] . '"';
      $row['lastmodified'] = (int) $row['lastmodified'];
      $cards[] = $row;
    }
    return $cards;
  }

  /**
   * {@inheritdoc}
   */
  public function getCard($addressBookId, $cardUri) {
    $query = $this->connection
      ->select('webdav__cards', 'dc');
    $query
      ->fields('dc', ['id', 'carddata', 'uri', 'lastmodified', 'etag', 'size'])
      ->condition('addressbookid', $addressBookId)
      ->condition('uri', $cardUri);
    $result = $query
      ->execute();

    $row = $result->fetchAssoc();
    if (!$row) {
      return FALSE;
    }

    $row['etag'] = '"' . $row['etag'] . '"';
    $row['lastmodified'] = (int) $row['lastmodified'];
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultipleCards($addressBookId, array $uris) {
    $query = $this->connection
      ->select('webdav__cards', 'dc');
    $query
      ->fields('dc', ['id', 'uri', 'lastmodified', 'etag', 'size', 'carddata'])
      ->condition('addressbookid', $addressBookId)
      ->condition('uri', $uris, 'IN');
    $result = $query
      ->execute();

    $rows = [];
    while ($row = $result->fetchAssoc()) {
      $row['etag'] = '"' . $row['etag'] . '"';
      $row['lastmodified'] = (int) $row['lastmodified'];
      $rows[] = $row;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function createCard($addressBookId, $cardUri, $cardData) {
    $etag = md5($cardData);

    $query = $this->connection
      ->insert('webdav__cards');
    $query
      ->fields([
        'carddata' => $cardData,
        'uri' => $cardUri,
        'lastmodified' => time(),
        'addressbookid' => $addressBookId,
        'size' => strlen($cardData),
        'etag' => $etag,
      ]);
    $query
      ->execute();

    $this->addChange($addressBookId, $cardUri, 1);

    return '"' . $etag . '"';
  }

  /**
   * {@inheritdoc}
   */
  public function updateCard($addressBookId, $cardUri, $cardData) {
    $etag = md5($cardData);

    $query = $this->connection
      ->update('webdav__cards');
    $query
      ->fields([
        'carddata' => $cardData,
        'lastmodified' => time(),
        'size' => strlen($cardData),
        'etag' => $etag,
      ])
      ->condition('uri', $cardUri)
      ->condition('addressbookid', $addressBookId);
    $query
      ->execute();

    $this->addChange($addressBookId, $cardUri, 2);

    return '"' . $etag . '"';
  }

  /**
   * {@inheritdoc}
   */
  public function deleteCard($addressBookId, $cardUri) {
    $query = $this->connection
      ->delete('webdav__cards')
      ->condition('addressbookid', $addressBookId)
      ->condition('uri', $cardUri);
    $result = $query
      ->execute();

    $this->addChange($addressBookId, $cardUri, 3);

    return (bool) $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = NULL) {
    // Current synctoken.
    $query = $this->connection
      ->select('webdav__addressbooks', 'da');
    $query
      ->fields('da', ['synctoken'])
      ->condition('id', $addressBookId);
    $result = $query
      ->execute();
    $currentToken = $result->fetchAssoc()['synctoken'];

    if (is_null($currentToken)) {
      return NULL;
    }

    $result = [
      'syncToken' => $currentToken,
      'added' => [],
      'modified' => [],
      'deleted' => [],
    ];

    if ($syncToken) {
      // Fetching all changes.
      $query = $this->connection
        ->select('webdav__addressbookchanges', 'dac');
      $query
        ->fields('dac', ['uri', 'operation'])
        ->condition('synctoken', $syncToken, '>=')
        ->condition('synctoken', $currentToken, '<')
        ->condition('addressbookid', $addressBookId);
      if ($limit > 0) {
        $query->range(0, 1);
      }
      $result = $query
        ->execute();

      $changes = [];
      // This loop ensures that any duplicates are overwritten, only the last
      // change on a node is relevant.
      while ($row = $result->fetchAssoc()) {
        $changes[$row['uri']] = $row['operation'];
      }

      foreach ($changes as $uri => $operation) {
        switch ($operation) {
          case 1:
            $result['added'][] = $uri;
            break;

          case 2:
            $result['modified'][] = $uri;
            break;

          case 3:
            $result['deleted'][] = $uri;
            break;

        }
      }
    }
    else {
      // No synctoken supplied, this is the initial sync.
      $query = $this->connection
        ->select('webdav__cards', 'dc');
      $query
        ->fields('dc', ['uri'])
        ->condition('addressbookid', $addressBookId);
      $result = $query
        ->execute();
      $result['added'] = $result->fetchAllAssoc('uri');
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function addChange($addressBookId, $objectUri, $operation) {
    $query = $this->connection
      ->select('webdav__addressbooks', 'da')
      ->fields('da', ['synctoken'])
      ->condition('id', $addressBookId);
    $result = $query
      ->execute();
    $row = $result->fetchAssoc();

    $query = $this->connection
      ->insert('webdav__addressbookchanges');
    $query
      ->fields([
        'uri' => $objectUri,
        'synctoken' => $row['synctoken'],
        'addressbookid' => $addressBookId,
        'operation' => $operation,
      ]);
    $query
      ->execute();

    $query = $this->connection
      ->update('webdav__addressbooks');
    $query
      ->fields([
        'synctoken' => $row['synctoken'] + 1,
      ])
      ->condition('id', $addressBookId);
    $query
      ->execute();
  }

}
