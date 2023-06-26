<?php

declare(strict_types = 1);

namespace Drupal\webdav\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webdav\Server as Server;
use Sabre\CalDAV\CalendarRoot as CalDAVCalendarRoot;
use Sabre\CalDAV\ICSExportPlugin as CalDAVICSExportPlugin;
use Sabre\CalDAV\Plugin as CaldAVPlugin;
use Sabre\CalDAV\Principal\Collection as CalDAVPrincipalCollection;
use Sabre\CalDAV\Schedule\IMipPlugin as CalDAVScheduleIMipPlugin;
use Sabre\CalDAV\Schedule\Plugin as CalDAVSchedulePlugin;
use Sabre\CalDAV\SharingPlugin as CalDAVSharingPlugin;
use Sabre\CardDAV\AddressBookRoot as CardDAVAddressBookRoot;
use Sabre\CardDAV\Plugin as CardDAVPlugin;
use Sabre\CardDAV\VCFExportPlugin as CardDAVVCFExportPlugin;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Browser\Plugin as BrowserPlugin;
use Sabre\DAV\FS\Directory as FsDirectory;
use Sabre\DAV\Locks\Plugin as LocksPlugin;
use Sabre\DAV\PropertyStorage\Plugin as PropertyStoragePlugin;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;
use Sabre\DAV\Sync\Plugin as SyncPlugin;
use Sabre\DAVACL\Plugin as AclPlugin;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for DAV routes.
 */
final class DavController extends ControllerBase {

  /**
   * The base uri.
   */
  const BASE_URI = '/webdav';

  /**
   * The timezone.
   */
  const TIMEZONE = 'UTC';

  /**
   * Builds the response.
   */
  public function __invoke(): Response {
    // @todo Decide on timezone handling.
    date_default_timezone_set(static::TIMEZONE);

    // @todo Use dependency injection instead of static service call.
    $authBackend = \Drupal::service('webdav.backend.authenticator');
    $caldavBackend = \Drupal::service('webdav.backend.caldav');
    $carddavBackend = \Drupal::service('webdav.backend.carddav');
    $locksBackend = \Drupal::service('webdav.backend.lock');
    $principalBackend = \Drupal::service('webdav.backend.principal');
    $propertyBackend = \Drupal::service('webdav.backend.property');
    $privateFileSystem = \Drupal::service('file_system')
      ->realpath("private://webdav/files");

    $nodes = [
      new CalDAVPrincipalCollection($principalBackend),
      new CalDAVCalendarRoot($principalBackend, $caldavBackend),
      new CardDAVAddressBookRoot($principalBackend, $carddavBackend),
      new FsDirectory($privateFileSystem),
    ];

    /** @var \Drupal\Core\Logger\LoggerChannelFactory $logger_factory */
    $logger_factory = \Drupal::service('logger.factory');
    $logger = $logger_factory->get('webdav');

    $server = new Server($nodes);
    $server->setLogger($logger);

    $server->setBaseUri(static::BASE_URI);

    // DAV plugins.
    $server->addPlugin(new AuthPlugin($authBackend));
    $server->addPlugin(new AclPlugin());
    $server->addPlugin(new BrowserPlugin());
    $server->addPlugin(new LocksPlugin($locksBackend));
    $server->addPlugin(new SharingPlugin());
    $server->addPlugin(new SyncPlugin());

    // CalDAV plugins.
    $server->addPlugin(new CalDAVPlugin());
    $server->addPlugin(new CalDAVICSExportPlugin());
    $server->addPlugin(new CalDAVSchedulePlugin());
    $server->addPlugin(new CalDAVSharingPlugin());

    // CardDAV plugins.
    $server->addPlugin(new CardDAVPlugin());
    $server->addPlugin(new CardDAVVCFExportPlugin());

    // Property storage plugin.
    $server->addPlugin(new PropertyStoragePlugin($propertyBackend));

    // Property storage plugin.
    $site_mail = \Drupal::config('system.site')->get('mail');
    $server->addPlugin(new CalDAVScheduleIMipPlugin($site_mail));

    return $server->start();
  }

}
