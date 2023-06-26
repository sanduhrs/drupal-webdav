<?php

declare(strict_types = 1);

namespace Drupal\webdav\Backend;

use Drupal\Core\Database\Connection;
use Sabre\DAV\PropertyStorage\Backend\BackendInterface;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Xml\Property\Complex;

/**
 * A WebDAV property backend.
 */
final class Property implements BackendInterface {

  /**
   * Value is stored as string.
   */
  const VT_STRING = 1;

  /**
   * Value is stored as XML fragment.
   */
  const VT_XML = 2;

  /**
   * Value is stored as a property object.
   */
  const VT_OBJECT = 3;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function propFind($path, PropFind $propFind) {
    if (!$propFind->isAllProps() && 0 === count($propFind->get404Properties())) {
      return;
    }

    $query = $this->connection
      ->select('webdav__propertystorage', 'dps');
    $query
      ->fields('dps', ['name', 'value', 'valuetype']);
    $query
      ->condition('path', $path);
    $result = $query
      ->execute();

    while ($row = $result->fetchAssoc()) {
      if ('resource' === gettype($row['value'])) {
        $row['value'] = stream_get_contents($row['value']);
      }
      switch ($row['valuetype']) {
        case NULL:
        case self::VT_STRING:
          $propFind->set($row['name'], $row['value']);
          break;

        case self::VT_XML:
          $propFind->set($row['name'], new Complex($row['value']));
          break;

        case self::VT_OBJECT:
          $propFind->set($row['name'], unserialize($row['value']));
          break;

      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function propPatch($path, PropPatch $propPatch) {
    $propPatch->handleRemaining(function ($properties) use ($path) {
      foreach ($properties as $name => $value) {

        if (is_null($value)) {
          $query = $this->connection
            ->delete('webdav__propertystorage');
          $query
            ->condition('path', $path)
            ->condition('name', $name);
          $query
            ->execute();
          continue;
        }

        if (is_scalar($value)) {
          $valueType = self::VT_STRING;
        }
        elseif ($value instanceof Complex) {
          $valueType = self::VT_XML;
          $value = $value->getXml();
        }
        else {
          $valueType = self::VT_OBJECT;
          $value = serialize($value);
        }

        $query = $this->connection
          ->select('webdav__propertystorage', 'dps')
          ->fields('dps', ['id'])
          ->condition('path', $path)
          ->condition('name', $name);
        if ($query->execute()->fetchField()) {
          $query = $this->connection
            ->update('webdav__propertystorage')
            ->fields([
              'valuetype' => $valueType,
              'value' => $value,
            ])
            ->condition('path', $path)
            ->condition('name', $name);
          $query
            ->execute();
        }
        else {
          $query = $this->connection
            ->insert('webdav__propertystorage')
            ->fields([
              'path' => $path,
              'name' => $name,
              'valuetype' => $valueType,
              'value' => $value,
            ]);
          $query
            ->execute();
        }
      }

      return TRUE;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function delete($path) {
    $childPath = strtr(
      $path,
      [
        '=' => '==',
        '%' => '=%',
        '_' => '=_',
      ]
    ) . '/%';

    $query = $this->connection
      ->delete('webdav__propertystorage');
    $orGroup = $query->orConditionGroup()
      ->condition('path', $path)
      ->condition('path', $childPath . '/%', 'LIKE');
    $query
      ->condition($orGroup);
    $query
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function move($source, $destination) {
    $query = $this->connection
      ->select('webdav__propertystorage', 'dps');
    $query
      ->fields('dps', ['id', 'path']);
    $orGroup = $query
      ->orConditionGroup()
      ->condition('path', $source)
      ->condition('path', $source . '/%', 'LIKE');
    $query
      ->condition($orGroup);

    $result = $query->execute();

    while ($row = $result->fetchAssoc()) {
      // Sanity check. SQL may select too many records, such as records
      // with different cases.
      if ($row['path'] !== $source && 0 !== strpos($row['path'], $source . '/')) {
        continue;
      }

      $trailingPart = substr($row['path'], strlen($source) + 1);
      $newPath = $destination;
      if ($trailingPart) {
        $newPath .= '/' . $trailingPart;
      }
      $query = $this->connection
        ->update('webdav__propertystorage');
      $query
        ->fields([
          'path' => $newPath,
        ])
        ->condition('id', $row['id']);
      $query
        ->execute();
    }
  }

}
