<?php

declare(strict_types = 1);

namespace Drupal\webdav\Backend;

use Drupal\Core\Database\Connection;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SchedulingSupport;
use Sabre\CalDAV\Backend\SharingSupport;
use Sabre\CalDAV\Backend\SubscriptionSupport;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotImplemented;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;
use Sabre\DAV\UUIDUtil;
use Sabre\DAV\Xml\Element\Sharee;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;

/**
 * A CalDav backend for Drupal.
 */
final class CalDav extends AbstractBackend implements SyncSupport, SubscriptionSupport, SchedulingSupport, SharingSupport {

  /**
   * {@inheritdoc}
   */
  const MAX_DATE = '2038-01-01';

  /**
   * {@inheritdoc}
   */
  public $propertyMap = [
    '{DAV:}displayname' => 'displayname',
    '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
    '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'timezone',
    '{http://apple.com/ns/ical/}calendar-order' => 'calendarorder',
    '{http://apple.com/ns/ical/}calendar-color' => 'calendarcolor',
  ];

  /**
   * {@inheritdoc}
   */
  public $subscriptionPropertyMap = [
    '{DAV:}displayname' => 'displayname',
    '{http://apple.com/ns/ical/}refreshrate' => 'refreshrate',
    '{http://apple.com/ns/ical/}calendar-order' => 'calendarorder',
    '{http://apple.com/ns/ical/}calendar-color' => 'calendarcolor',
    '{http://calendarserver.org/ns/}subscribed-strip-todos' => 'striptodos',
    '{http://calendarserver.org/ns/}subscribed-strip-alarms' => 'stripalarms',
    '{http://calendarserver.org/ns/}subscribed-strip-attachments' => 'stripattachments',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCalendarsForUser($principalUri) {
    $fields = array_values($this->propertyMap);
    $fields[] = 'calendarid';
    $fields[] = 'uri';
    $fields[] = 'principaluri';
    $fields[] = 'transparent';
    $fields[] = 'access';

    $query = $this->connection
      ->select('webdav__calendarinstances', 'dci');
    $query
      ->leftJoin('webdav__calendars', 'dc', 'dci.calendarid = dc.id');
    $query
      ->fields('dc', ['synctoken', 'components'])
      ->fields('dci', $fields);
    $query
      ->condition('dci.principaluri', $principalUri)
      ->orderBy('dci.calendarorder', 'ASC');
    $result = $query
      ->execute();

    $calendars = [];
    while ($row = $result->fetchAssoc()) {
      $components = [];
      if ($row['components']) {
        $components = explode(',', $row['components']);
      }

      $calendar = [
        'id' => [(int) $row['calendarid'], (int) $row['id']],
        'uri' => $row['uri'],
        'principaluri' => $row['principaluri'],
        '{' . Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . ($row['synctoken'] ? $row['synctoken'] : '0'),
        '{http://sabredav.org/ns}sync-token' => $row['synctoken'] ? $row['synctoken'] : '0',
        '{' . Plugin::NS_CALDAV . '}supported-calendar-component-set' => new SupportedCalendarComponentSet($components),
        '{' . Plugin::NS_CALDAV . '}schedule-calendar-transp' => new ScheduleCalendarTransp($row['transparent'] ? 'transparent' : 'opaque'),
        'share-resource-uri' => '/ns/share/' . $row['calendarid'],
      ];

      $calendar['share-access'] = (int) $row['access'];
      if ($row['access'] > 1) {
        // We need to find more information about the original owner.
        // phpcs:ignore
        // $stmt2 = $this->pdo->prepare('SELECT principaluri FROM ' . $this->calendarInstancesTableName . ' WHERE access = 1 AND id = ?');
        // $stmt2->execute([$row['id']]);
        // read-only is for backwards compatbility. Might go away in
        // the future.
        $calendar['read-only'] = SharingPlugin::ACCESS_READ === (int) $row['access'];
      }
    }
    return $calendars;
  }

  /**
   * {@inheritdoc}
   */
  public function createCalendar($principalUri, $calendarUri, array $properties) {
    $values = [
      ':principaluri' => $principalUri,
      ':uri' => $calendarUri,
      ':transparent' => 0,
    ];

    $sccs = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
    if (!isset($properties[$sccs])) {
      // Default value.
      $components = 'VEVENT,VTODO';
    }
    else {
      if (!($properties[$sccs] instanceof SupportedCalendarComponentSet)) {
        throw new Exception('The ' . $sccs . ' property must be of type: \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet');
      }
      $components = implode(',', $properties[$sccs]->getValue());
    }
    $transp = '{' . Plugin::NS_CALDAV . '}schedule-calendar-transp';
    if (isset($properties[$transp])) {
      $values[':transparent'] = 'transparent' === $properties[$transp]->getValue() ? 1 : 0;
    }
    $query = $this->connection
      ->insert('webdav__calendars');
    $query
      ->fields([
        'synctoken' => 1,
        'components' => $components,
      ]);
    $result = $query
      ->execute();
    $calendarId = $result;

    $values['calendarid'] = $calendarId;
    foreach ($this->propertyMap as $xmlName => $dbName) {
      if (isset($properties[$xmlName])) {
        $values[$dbName] = $properties[$xmlName];
      }
    }
    $query = $this->connection
      ->insert('webdav__calendarinstances');
    $query
      ->fields($values);
    $result = $query
      ->execute();
    $calendarInstanceId = $result;

    return [
      $calendarId,
      $calendarInstanceId,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function updateCalendar($calendarId, PropPatch $propPatch) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId, $instanceId] = $calendarId;

    $supportedProperties = array_keys($this->propertyMap);
    $supportedProperties[] = '{' . Plugin::NS_CALDAV . '}schedule-calendar-transp';

    $propPatch->handle($supportedProperties, function ($mutations) use ($calendarId, $instanceId) {
      $newValues = [];
      foreach ($mutations as $propertyName => $propertyValue) {
        switch ($propertyName) {
          case '{' . Plugin::NS_CALDAV . '}schedule-calendar-transp':
            $fieldName = 'transparent';
            $newValues[$fieldName] = 'transparent' === $propertyValue->getValue();
            break;

          default:
            $fieldName = $this->propertyMap[$propertyName];
            $newValues[$fieldName] = $propertyValue;
            break;

        }
      }

      $query = $this->connection
        ->update('webdav__calendarinstances');
      $query
        ->fields($newValues)
        ->condition('id', $instanceId);
      $query->execute();

      $this->addChange($calendarId, '', 2);

      return TRUE;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function deleteCalendar($calendarId) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId, $instanceId] = $calendarId;

    $query = $this->connection
      ->select('webdav__calendarinstances', 'dci');
    $query
      ->fields('dci', ['access'])
      ->condition('id', $instanceId);
    $result = $query
      ->execute();
    $access = (int) $result->fetchField();

    if (SharingPlugin::ACCESS_SHAREDOWNER === $access) {
      // If the user is the owner of the calendar, we delete all data and all
      // instances.
      $query = $this->connection
        ->delete('webdav__calendarobjects');
      $query
        ->condition('calendarid', $calendarId);
      $query->execute();

      $query = $this->connection
        ->delete('webdav__calendarchanges');
      $query
        ->condition('calendarid', $calendarId);
      $query->execute();

      $query = $this->connection
        ->delete('webdav__calendarinstances');
      $query
        ->condition('calendarid', $calendarId);
      $query->execute();

      $query = $this->connection
        ->delete('webdav__calendars');
      $query
        ->condition('id', $calendarId);
      $query->execute();
    }
    else {
      // If it was an instance of a shared calendar, we only delete that
      // instance.
      $query = $this->connection
        ->delete('webdav__calendarinstances');
      $query
        ->condition('id', $instanceId);
      $query->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCalendarObjects($calendarId) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $query = $this->connection
      ->select('webdav__calendarobjects', 'dco');
    $query
      ->fields('dco', [
        'id',
        'uri',
        'lastmodified',
        'etag',
        'calendarid',
        'size',
        'componenttype',
      ])
      ->condition('calendarid', $calendarId);
    $result = $query
      ->execute();

    $rows = [];
    while ($row = $result->fetchAssoc()) {
      $rows[] = [
        'id' => $row['id'],
        'uri' => $row['uri'],
        'lastmodified' => (int) $row['lastmodified'],
        'etag' => '"' . $row['etag'] . '"',
        'size' => (int) $row['size'],
        'component' => strtolower($row['componenttype']),
      ];
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getCalendarObject($calendarId, $objectUri) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $query = $this->connection
      ->select('webdav__calendarobjects', 'dco');
    $query
      ->fields('dco', [
        'id',
        'uri',
        'lastmodified',
        'etag',
        'calendarid',
        'size',
        'calendardata',
        'componenttype',
      ])
      ->condition('calendarid', $calendarId)
      ->condition('uri', $objectUri);
    $result = $query
      ->execute();

    if ($row = $result->fetchAssoc()) {
      return [
        'id' => $row['id'],
        'uri' => $row['uri'],
        'lastmodified' => (int) $row['lastmodified'],
        'etag' => '"' . $row['etag'] . '"',
        'size' => (int) $row['size'],
        'calendardata' => $row['calendardata'],
        'component' => strtolower($row['componenttype']),
      ];
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultipleCalendarObjects($calendarId, array $uris) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $rows = [];
    foreach (array_chunk($uris, 900) as $chunk) {
      $query = $this->connection
        ->select('webdav__calendarobjects', 'dco');
      $query
        ->fields('dco', [
          'id',
          'uri',
          'lastmodified',
          'etag',
          'calendarid',
          'size',
          'calendardata',
          'componenttype',
        ])
        ->condition('calendarid', $calendarId)
        ->condition('uri', $chunk, 'IN');
      $result = $query
        ->execute();

      while ($row = $result->fetchAssoc()) {
        $rows[] = [
          'id' => $row['id'],
          'uri' => $row['uri'],
          'lastmodified' => (int) $row['lastmodified'],
          'etag' => '"' . $row['etag'] . '"',
          'size' => (int) $row['size'],
          'calendardata' => $row['calendardata'],
          'component' => strtolower($row['componenttype']),
        ];
      }
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function createCalendarObject($calendarId, $objectUri, $calendarData) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $extraData = $this->getDenormalizedData($calendarData);

    $query = $this->connection
      ->insert('webdav__calendarobjects');
    $query
      ->fields([
        'calendarid' => $calendarId,
        'uri' => $objectUri,
        'calendardata' => $calendarData,
        'lastmodified' => time(),
        'etag' => $extraData['etag'],
        'size' => $extraData['size'],
        'componenttype' => $extraData['componentType'],
        'firstoccurence' => $extraData['firstOccurence'],
        'lastoccurence' => $extraData['lastOccurence'],
        'uid' => $extraData['uid'],
      ]);
    $query
      ->execute();

    $this->addChange($calendarId, $objectUri, 1);

    return '"' . $extraData['etag'] . '"';
  }

  /**
   * {@inheritdoc}
   */
  public function updateCalendarObject($calendarId, $objectUri, $calendarData) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $extraData = $this->getDenormalizedData($calendarData);

    $query = $this->connection
      ->update('webdav__calendarobjects');
    $query
      ->fields([
        'calendardata' => $calendarData,
        'lastmodified' => time(),
        'etag' => $extraData['etag'],
        'size' => $extraData['size'],
        'componenttype' => $extraData['componentType'],
        'firstoccurence' => $extraData['firstOccurence'],
        'lastoccurence' => $extraData['lastOccurence'],
        'uid' => $extraData['uid'],
      ])
      ->condition('calendarid', $calendarId)
      ->condition('uri', $objectUri);
    $query
      ->execute();

    $this->addChange($calendarId, $objectUri, 2);

    return '"' . $extraData['etag'] . '"';
  }

  /**
   * {@inheritdoc}
   */
  public function deleteCalendarObject($calendarId, $objectUri) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $query = $this->connection
      ->delete('webdav__calendarobjects');
    $query
      ->condition('calendarid', $calendarId)
      ->condition('uri', $objectUri);
    $query
      ->execute();

    $this->addChange($calendarId, $objectUri, 3);
  }

  /**
   * {@inheritdoc}
   */
  public function calendarQuery($calendarId, array $filters) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $componentType = NULL;
    $requirePostFilter = TRUE;
    $timeRange = NULL;

    // If no filters were specified, we don't need to filter after a query.
    if (!$filters['prop-filters'] && !$filters['comp-filters']) {
      $requirePostFilter = FALSE;
    }

    // Figuring out if there's a component filter.
    if (count($filters['comp-filters']) > 0 && !$filters['comp-filters'][0]['is-not-defined']) {
      $componentType = $filters['comp-filters'][0]['name'];

      // Checking if we need post-filters.
      $has_time_range = array_key_exists('time-range', $filters['comp-filters'][0]) && $filters['comp-filters'][0]['time-range'];
      if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$has_time_range && !$filters['comp-filters'][0]['prop-filters']) {
        $requirePostFilter = FALSE;
      }
      // There was a time-range filter.
      if ('VEVENT' == $componentType && $has_time_range) {
        $timeRange = $filters['comp-filters'][0]['time-range'];

        // If start time OR the end time is not specified, we can do a
        // 100% accurate mysql query.
        if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['prop-filters'] && $timeRange) {
          if ((array_key_exists('start', $timeRange) && !$timeRange['start']) || (array_key_exists('end', $timeRange) && !$timeRange['end'])) {
            $requirePostFilter = FALSE;
          }
        }
      }
    }

    $query = $this->connection
      ->select('webdav__calendarobjects', 'dco');
    if ($requirePostFilter) {
      $query
        ->fields('dco', ['uri', 'calendardata'])
        ->condition('calendarid', $calendarId);
    }
    else {
      $query
        ->fields('dco', ['uri'])
        ->condition('calendarid', $calendarId);
    }

    if ($componentType) {
      $query
        ->condition('componenttype', $componentType);
    }

    if ($timeRange && array_key_exists('start', $timeRange) && $timeRange['start']) {
      $query
        ->condition('lastoccurence', $timeRange['start']->getTimeStamp());
    }
    if ($timeRange && array_key_exists('end', $timeRange) && $timeRange['end']) {
      $query
        ->condition('firstoccurence', $timeRange['end']->getTimeStamp());
    }
    $result = $query->execute();

    $rows = [];
    while ($row = $result->fetchAssoc()) {
      if ($requirePostFilter) {
        if (!$this->validateFilterForObject($row, $filters)) {
          continue;
        }
      }
      $rows[] = $row['uri'];
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore
  public function getCalendarObjectByUID($principalUri, $uid) {
    $query = $this->connection
      ->select('webdav__calendarobjects', 'dco');
    $query
      ->leftJoin('webdav__calendarinstances', 'dci', 'dco.calendarid = dci.calendarid');
    $query
      ->fields('dci', ['uri as calendaruri'])
      ->fields('dco', ['uri as objecturi'])
      ->condition('dci.principaluri', $principalUri)
      ->condition('dco.uid', $uid)
      ->condition('dci.access', 1);
    $result = $query
      ->execute();

    if ($row = $result->fetchAssoc()) {
      return $row['calendaruri'] . '/' . $row['objecturi'];
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = NULL) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $return = [
      'added' => [],
      'modified' => [],
      'deleted' => [],
    ];

    if ($syncToken) {
      $query = $this->connection
        ->select('webdav__calendarchanges', 'dco');
      $query
        ->fields('dco', ['uri', 'operation', 'synctoken'])
        ->condition('synctoken', $syncToken, '>=')
        ->condition('calendarid', $calendarId)
        ->orderBy('synctoken');

      if ($limit > 0) {
        // Fetch one more raw to detect result truncation.
        $query->range(0, (int) $limit + 1);
      }

      // Fetching all changes.
      $result = $query
        ->execute();

      $changes = [];

      // This loop ensures that any duplicates are overwritten, only the
      // last change on a node is relevant.
      while ($row = $result->fetchAssoc()) {
        $changes[$row['uri']] = $row;
      }
      $currentToken = NULL;

      $result_count = 0;
      foreach ($changes as $uri => $operation) {
        if (!is_null($limit) && $result_count >= $limit) {
          $return['result_truncated'] = TRUE;
          break;
        }

        if (NULL === $currentToken || $currentToken < $operation['synctoken'] + 1) {
          // SyncToken in CalDAV perspective is consistently the next number of
          // the last synced change event in this class.
          $currentToken = $operation['synctoken'] + 1;
        }

        ++$result_count;
        switch ($operation['operation']) {
          case 1:
            $return['added'][] = $uri;
            break;

          case 2:
            $return['modified'][] = $uri;
            break;

          case 3:
            $return['deleted'][] = $uri;
            break;

        }
      }

      if (!is_null($currentToken)) {
        $return['syncToken'] = $currentToken;
      }
      else {
        // This means returned value is equivalent to syncToken.
        $return['syncToken'] = $syncToken;
      }
    }
    else {
      // Current synctoken.
      $query = $this->connection
        ->select('webdav__calendars', 'dc');
      $query
        ->fields('dc', ['synctoken'])
        ->condition('id', $calendarId);
      $result = $query->execute();

      $currentToken = $result->fetchField();

      if (is_null($currentToken)) {
        return NULL;
      }

      $return['syncToken'] = $currentToken;

      // No synctoken supplied, this is the initial sync.
      $query = $this->connection
        ->select('webdav__calendarobjects', 'dco');
      $query
        ->fields('dco', ['uri'])
        ->condition('calendarid', $calendarId);
      $result = $query->execute();

      $return['added'] = $result->fetchAllAssoc('uri');
    }

    return $return;
  }

  /**
   * Adds a change record to the calendarchanges table.
   *
   * @param mixed $calendarId
   *   The calendar id.
   * @param string $objectUri
   *   The obkect URI.
   * @param int $operation
   *   The operation value: 1 = add, 2 = modify, 3 = delete.
   */
  protected function addChange($calendarId, $objectUri, $operation) {
    $query = $this->connection
      ->select('webdav__calendars', 'dc');
    $query
      ->fields('dc', ['synctoken'])
      ->condition('id', $calendarId);
    $synctoken = $query->execute()->fetchField();

    $query = $this->connection
      ->insert('webdav__calendarchanges');
    $query
      ->fields([
        'uri' => $objectUri,
        'synctoken' => $synctoken,
        'calendarid' => $calendarId,
        'operation' => $operation,
      ]);
    $query->execute();

    $query = $this->connection
      ->update('webdav__calendars');
    $query
      ->fields([
        'synctoken' => $synctoken = 1,
      ])
      ->condition('id', $calendarId);
    $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getSubscriptionsForUser($principalUri) {
    $fields = array_values($this->subscriptionPropertyMap);
    $fields[] = 'id';
    $fields[] = 'uri';
    $fields[] = 'source';
    $fields[] = 'principaluri';
    $fields[] = 'lastmodified';

    $query = $this->connection
      ->select('webdav__calendarsubscriptions', 'dcs');
    $query
      ->fields('dcs', $fields);
    $result = $query
      ->execute();

    $subscriptions = [];
    while ($row = $result->fetchAssoc()) {
      $subscription = [
        'id' => $row['id'],
        'uri' => $row['uri'],
        'principaluri' => $row['principaluri'],
        'source' => $row['source'],
        'lastmodified' => $row['lastmodified'],
        // phpcs:ignore
        '{' . Plugin::NS_CALDAV . '}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VTODO', 'VEVENT']),
      ];

      foreach ($this->subscriptionPropertyMap as $xmlName => $dbName) {
        if (!is_null($row[$dbName])) {
          $subscription[$xmlName] = $row[$dbName];
        }
      }

      $subscriptions[] = $subscription;
    }
    return $subscriptions;
  }

  /**
   * {@inheritdoc}
   */
  public function createSubscription($principalUri, $uri, array $properties) {
    if (!isset($properties['{http://calendarserver.org/ns/}source'])) {
      throw new Forbidden('The {http://calendarserver.org/ns/}source property is required when creating subscriptions');
    }

    $values = [
      'principaluri' => $principalUri,
      'uri' => $uri,
      'source' => $properties['{http://calendarserver.org/ns/}source']->getHref(),
      'lastmodified' => time(),
    ];

    foreach ($this->subscriptionPropertyMap as $xmlName => $dbName) {
      if (isset($properties[$xmlName])) {
        $values[$dbName] = $properties[$xmlName];
      }
    }

    $query = $this->connection
      ->insert('webdav__calendarsubscriptions');
    $query
      ->fields($values);
    $result = $query
      ->execute();

    return (int) $result;
  }

  /**
   * {@inheritdoc}
   */
  public function updateSubscription($subscriptionId, PropPatch $propPatch) {
    $supportedProperties = array_keys($this->subscriptionPropertyMap);
    $supportedProperties[] = '{http://calendarserver.org/ns/}source';

    $propPatch->handle($supportedProperties, function ($mutations) use ($subscriptionId) {
      $newValues = [];

      foreach ($mutations as $propertyName => $propertyValue) {
        if ('{http://calendarserver.org/ns/}source' === $propertyName) {
          $newValues['source'] = $propertyValue->getHref();
        }
        else {
          $fieldName = $this->subscriptionPropertyMap[$propertyName];
          $newValues[$fieldName] = $propertyValue;
        }
      }

      $newValues['lastmodified'] = time();
      $newValues['id'] = $subscriptionId;

      $query = $this->connection
        ->insert('webdav__calendarsubscriptions');
      $query
        ->fields($newValues);
      $query
        ->execute();

      return TRUE;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function deleteSubscription($subscriptionId) {
    $query = $this->connection
      ->delete('webdav__calendarsubscriptions');
    $query->condition('id', $subscriptionId);
    $query
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getSchedulingObject($principalUri, $objectUri) {
    $query = $this->connection
      ->select('webdav__calendarsubscriptions', 'dcs');
    $query
      ->fields('dcs', ['uri', 'calendardata', 'lastmodified', 'etag', 'size'])
      ->condition('principaluri', $principalUri)
      ->condition('uri', $objectUri);
    $result = $query
      ->execute();

    if (!$row = $result->fetchAssoc()) {
      return NULL;
    }

    return [
      'uri' => $row['uri'],
      'calendardata' => $row['calendardata'],
      'lastmodified' => $row['lastmodified'],
      'etag' => '"' . $row['etag'] . '"',
      'size' => (int) $row['size'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSchedulingObjects($principalUri) {
    $query = $this->connection
      ->select('webdav__schedulingobjects', 'dso');
    $query
    // phpcs:ignore
      ->fields('dso', ['id', 'calendardata', 'uri', 'lastmodified', 'etag', 'size'])
      ->condition('principaluri', $principalUri);
    $result = $query
      ->execute();

    while ($row = $result->fetchAssoc()) {
      $result[] = [
        'calendardata' => $row['calendardata'],
        'uri' => $row['uri'],
        'lastmodified' => $row['lastmodified'],
        'etag' => '"' . $row['etag'] . '"',
        'size' => (int) $row['size'],
      ];
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteSchedulingObject($principalUri, $objectUri) {
    $query = $this->connection
      ->delete('webdav__schedulingobjects');
    $query
      ->condition('principaluri', $principalUri)
      ->condition('uri', $objectUri);
    $query
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function createSchedulingObject($principalUri, $objectUri, $objectData) {
    if (is_resource($objectData)) {
      $objectData = stream_get_contents($objectData);
    }

    $query = $this->connection
      ->insert('webdav__schedulingobjects')
      ->fields([
        'principaluri' => $principalUri,
        'calendardata' => $objectData,
        'uri' => $objectUri,
        'lastmodified' => time(),
        'etag' => md5($objectData),
        'size' => strlen($objectData),
      ]);
    $query
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function updateInvites($calendarId, array $sharees) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
    }
    $currentInvites = $this->getInvites($calendarId);
    [$calendarId, $instanceId] = $calendarId;

    foreach ($sharees as $sharee) {
      if (SharingPlugin::ACCESS_NOACCESS === $sharee->access) {
        // If access was set no NOACCESS, it means access for an
        // existing sharee was removed.
        $query = $this->connection
          ->delete('webdav__calendarinstances')
          ->condition('calendarid', $calendarId)
          ->condition('share_href', $sharee->href)
          ->condition('access', [2, 3], 'IN');
        $result = $query
          ->execute();
        continue;
      }

      if (is_null($sharee->principal)) {
        // If the server could not determine the principal automatically,
        // we will mark the invite status as invalid.
        $sharee->inviteStatus = SharingPlugin::INVITE_INVALID;
      }
      else {
        // Because sabre/dav does not yet have an invitation system,
        // every invite is automatically accepted for now.
        $sharee->inviteStatus = SharingPlugin::INVITE_ACCEPTED;
      }

      foreach ($currentInvites as $oldSharee) {
        if ($oldSharee->href === $sharee->href) {
          // This is an update.
          $sharee->properties = array_merge(
            $oldSharee->properties,
            $sharee->properties
          );
          $query = $this->connection
            ->update('webdav__calendarinstances')
            ->fields([
              'access' => $sharee->access,
              'share_displayname' => $sharee->properties['{DAV:}displayname'] ?: NULL,
              'share_invitestatus' => $sharee->inviteStatus ?: $oldSharee->inviteStatus,
            ])
            ->condition('calendarid', $calendarId)
            ->condition('share_href', $sharee->href);
          $result = $query
            ->execute();
          continue 2;
        }
      }
      // If we got here, it means it was a new sharee.
      $query = $this->connection
        ->select('webdav__calendarinstances', 'dci')
        ->fields('dci', [
          'displayname',
          'description',
          'calendarorder',
          'calendarcolor',
          'timezone',
        ])
        ->condition('id', $instanceId);
      $result = $query->execute();
      $row = $result->fetchAssoc();

      $query = $this->connection
        ->insert('webdav__calendarinstances');
      $query->fields([
        'calendarid' => $calendarId,
        'principaluri' => $sharee->principal,
        'access' => $sharee->access,
        'displayname' => $row['displayname'],
        'uri' => UUIDUtil::getUUID(),
        'description' => $row['description'],
        'calendarorder' => $row['calendarorder'],
        'calendarcolor' => $row['calendarcolor'],
        'timezone' => $row['timezone'],
        'transparent' => 1,
        'share_href' => $sharee->href,
        'share_displayname' => $sharee->properties['{DAV:}displayname'] ?: NULL,
        'share_invitestatus' => $sharee->inviteStatus ?: SharingPlugin::INVITE_NORESPONSE,
      ]);
      $query
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getInvites($calendarId) {
    if (!is_array($calendarId)) {
      throw new \InvalidArgumentException('The value passed to getInvites() is expected to be an array with a calendarId and an instanceId');
    }
    [$calendarId] = $calendarId;

    $query = $this->connection
      ->select('webdav__calendarinstances', 'dci')
      ->fields('dci', [
        'principaluri',
        'access',
        'share_href',
        'share_displayname',
        'share_invitestatus',
      ])
      ->condition('calendarid', $calendarId);
    $result = $query->execute();

    $rows = [];
    while ($row = $result->fetchAssoc()) {
      $rows[] = new Sharee([
        'href' => $row['share_href'] ?: \Sabre\HTTP\encodePath($row['principaluri']),
        'access' => (int) $row['access'],
        // Everyone is always immediately accepted, for now.
        'inviteStatus' => (int) $row['share_invitestatus'],
        'properties' => !empty($row['share_displayname'])
          ? ['{DAV:}displayname' => $row['share_displayname']]
          : [],
        'principal' => $row['principaluri'],
      ]);
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function setPublishStatus($calendarId, $value) {
    throw new NotImplemented('Not implemented');
  }

  /**
   * Parses some information from calendar objects.
   *
   * Used for optimized calendar-queries.
   *
   * Returns an array with the following keys:
   *   * etag - An md5 checksum of the object without the quotes.
   *   * size - Size of the object in bytes
   *   * componentType - VEVENT, VTODO or VJOURNAL
   *   * firstOccurence
   *   * lastOccurence
   *   * uid - value of the UID property
   *
   * @param string $calendarData
   *   The calendar data string.
   *
   * @return array
   *   Denormalized calendar data string.
   */
  protected function getDenormalizedData($calendarData) {
    $vObject = Reader::read($calendarData);
    $componentType = NULL;
    $component = NULL;
    $firstOccurence = NULL;
    $lastOccurence = NULL;
    $uid = NULL;
    foreach ($vObject->getComponents() as $component) {
      if ('VTIMEZONE' !== $component->name) {
        $componentType = $component->name;
        $uid = (string) $component->UID;
        break;
      }
    }

    if (!$componentType) {
      throw new BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
    }

    if ('VEVENT' === $componentType) {
      $firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
      // Finding the last occurrence is a bit harder.
      if (!isset($component->RRULE)) {
        if (isset($component->DTEND)) {
          $lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
        }
        elseif (isset($component->DURATION)) {
          $endDate = clone $component->DTSTART->getDateTime();
          $endDate = $endDate->add(DateTimeParser::parse($component->DURATION->getValue()));
          $lastOccurence = $endDate->getTimeStamp();
        }
        elseif (!$component->DTSTART->hasTime()) {
          $endDate = clone $component->DTSTART->getDateTime();
          $endDate = $endDate->modify('+1 day');
          $lastOccurence = $endDate->getTimeStamp();
        }
        else {
          $lastOccurence = $firstOccurence;
        }
      }
      else {
        $it = new EventIterator($vObject, (string) $component->UID);
        $maxDate = new \DateTime(self::MAX_DATE);
        if ($it->isInfinite()) {
          $lastOccurence = $maxDate->getTimeStamp();
        }
        else {
          $end = $it->getDtEnd();
          while ($it->valid() && $end < $maxDate) {
            $end = $it->getDtEnd();
            $it->next();
          }
          $lastOccurence = $end->getTimeStamp();
        }
      }

      // Ensure Occurrence values are positive.
      if ($firstOccurence < 0) {
        $firstOccurence = 0;
      }
      if ($lastOccurence < 0) {
        $lastOccurence = 0;
      }
    }

    // Destroy circular references to PHP will GC the object.
    $vObject->destroy();

    return [
      'etag' => md5($calendarData),
      'size' => strlen($calendarData),
      'componentType' => $componentType,
      'firstOccurence' => $firstOccurence,
      'lastOccurence' => $lastOccurence,
      'uid' => $uid,
    ];
  }

}
