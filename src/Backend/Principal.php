<?php

declare(strict_types = 1);

namespace Drupal\webdav\Backend;

use Drupal\Core\Database\Connection;
use Sabre\DAV\Exception;
use Sabre\DAV\MkCol;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

/**
 * A principal backend for Drupal users.
 */
final class Principal extends AbstractBackend {

  /**
   * A list of additional fields to support.
   *
   * @var array
   */
  protected $fieldMap = [
    '{DAV:}displayname' => [
      'dbField' => 'displayname',
    ],
    '{http://sabredav.org/ns}email-address' => [
      'dbField' => 'email',
    ],
  ];

  /**
   * Constructs the object.
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getPrincipalsByPrefix($prefixPath) {
    $fields = [
      'id',
      'uri',
    ];

    foreach ($this->fieldMap as $key => $value) {
      $fields[] = $value['dbField'];
    }

    $query = $this->connection
      ->select('webdav__principals', 'dp');
    $query
      ->fields('dp', $fields);
    $result = $query
      ->execute();

    $principals = [];
    while ($row = $result->fetchAssoc()) {
      // Checking if the principal is in the prefix.
      [$rowPrefix] = \Sabre\Uri\split($row['uri']);
      if ($rowPrefix !== $prefixPath) {
        continue;
      }

      $principal = [
        'id' => $row['id'],
        'uri' => $row['uri'],
      ];
      foreach ($this->fieldMap as $key => $value) {
        if ($row[$value['dbField']]) {
          $principal[$key] = $row[$value['dbField']];
        }
      }
      $principals[] = $principal;
    }

    return $principals;
  }

  /**
   * {@inheritdoc}
   */
  public function getPrincipalByPath($path) {
    [$prefixPath] = \Sabre\Uri\split($path);
    $principals = $this->getPrincipalsByPrefix($prefixPath);
    foreach ($principals as $principal) {
      if ($principal['uri'] === $path) {
        return $principal;
      }
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function updatePrincipal($path, PropPatch $propPatch) {
    $propPatch->handle(array_keys($this->fieldMap), function ($properties) use ($path) {
      $query = $this->connection
        ->update('webdav__principals');

      $values = [];
      foreach ($properties as $key => $value) {
        $dbField = $this->fieldMap[$key]['dbField'];
        $values[$dbField] = $value;
      }
      $query
        ->fields($values);
      $query
        ->condition('uri', $path);
      $result = $query
        ->execute();

      return (bool) $result;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof') {
    if (0 == count($searchProperties)) {
      return [];
    }

    $query = $this->connection
      ->select('webdav__principals', 'dp');

    $conditionGroup = strcmp($test, 'anyof')
      ? $query->orConditionGroup()
      : $query->andConditionGroup();

    foreach ($searchProperties as $property => $value) {
      if (isset($this->fieldMap[$property])) {
        $conditionGroup
          ->condition($this->fieldMap[$property]['dbField'], $value, 'LIKE');
      }
    }
    $query
      ->condition($conditionGroup);
    $result = $query
      ->execute();

    $principals = [];
    while ($row = $result->fetchAssoc()) {
      // Checking if the principal is in the prefix.
      [$rowPrefix] = \Sabre\Uri\split($row['uri']);
      if ($rowPrefix !== $prefixPath) {
        continue;
      }
      $principals[] = $row['uri'];
    }

    return $principals;
  }

  /**
   * {@inheritdoc}
   */
  public function findByUri($uri, $principalPrefix) {
    if ('mailto:' !== substr($uri, 0, 7)) {
      return NULL;
    }

    $result = $this->searchPrincipals(
      $principalPrefix,
      ['{http://sabredav.org/ns}email-address' => substr($uri, 7)]
    );

    if ($result) {
      return $result[0];
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupMemberSet($principal) {
    $principal = $this->getPrincipalByPath($principal);
    if (!$principal) {
      throw new Exception('Principal not found');
    }

    $query = $this->connection
      ->select('webdav__groupmembers', 'dgm');
    $query
      ->innerJoin('webdav__principals', 'dp', 'dgm.member_id = dp.id');
    $query
      ->fields('dp', ['uri'])
      ->condition('dgm.principal_id', $principal['id']);
    $result = $query
      ->execute();

    while ($row = $result->fetchAssoc()) {
      $result[] = $row['uri'];
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupMembership($principal) {
    $principal = $this->getPrincipalByPath($principal);
    if (!$principal) {
      throw new Exception('Principal not found');
    }
    $query = $this->connection
      ->select('webdav__groupmembers', 'dgm');
    $query
      ->leftJoin('webdav__principals', 'dp', 'dgm.id = dp.id');
    $query
      ->fields('dp', ['uri'])
      ->condition('dgm.member_id', $principal['id']);
    $result = $query
      ->execute();

    while ($row = $result->fetchAssoc()) {
      $result[] = $row['uri'];
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function setGroupMemberSet($principal, array $members) {
    // Grabbing the list of principal id's.
    $query = $this->connection
      ->select('webdav__principals', 'dp');
    $query
      ->fields('dp', ['id', 'uri'])
      ->condition('dp.uri', array_merge([$principal], $members), 'IN');
    $result = $query
      ->execute();

    $memberIds = [];
    $principalId = NULL;
    while ($row = $result->fetchAssoc()) {
      if ($row['uri'] == $principal) {
        $principalId = $row['id'];
      }
      else {
        $memberIds[] = $row['id'];
      }
    }
    if (!$principalId) {
      throw new Exception('Principal not found');
    }
    // Wiping out old members.
    $query = $this->connection
      ->delete('webdav__groupmembers');
    $query
      ->condition('dg.principal_id', $principalId);
    $query
      ->execute();

    foreach ($memberIds as $memberId) {
      $query = $this->connection
        ->insert('webdav__groupmembers');
      $query
        ->fields([
          'principal_id' => $principalId,
          'member_id' => $memberId,
        ]);
      $query
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPrincipal($path, MkCol $mkCol) {
    $query = $this->connection
      ->insert('webdav__principals');
    $query
      ->fields(['uri' => $path]);
    $query
      ->execute();

    $this->updatePrincipal($path, $mkCol);
  }

}
