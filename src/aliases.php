<?php
declare(strict_types=1);

/**
 * 1.x class names (namespace HeadlinesForge) => 2.0 names. The autoloader turns each old name
 * into an alias of the new class when something asks for it, so themes and site code that
 * call the old names keep working. Remove in 3.0.0 (plan E4b, Ruling 1).
 *
 * @return array<string, string>
 */
return [
    'HeadlinesForge\\Activator' => 'TribuneDesk\\WordPress\\Activator',
    'HeadlinesForge\\AuthorResolver' => 'TribuneDesk\\WordPress\\AuthorResolver',
    'HeadlinesForge\\Backfill' => 'TribuneDesk\\WordPress\\Backfill',
    'HeadlinesForge\\Backlinks' => 'TribuneDesk\\WordPress\\Backlinks',
    'HeadlinesForge\\CmsApiClient' => 'TribuneDesk\\WordPress\\CmsApiClient',
    'HeadlinesForge\\CmsBackfill' => 'TribuneDesk\\WordPress\\CmsBackfill',
    'HeadlinesForge\\CmsDeletions' => 'TribuneDesk\\WordPress\\CmsDeletions',
    'HeadlinesForge\\CmsResync' => 'TribuneDesk\\WordPress\\CmsResync',
    'HeadlinesForge\\CmsSettingsScreen' => 'TribuneDesk\\WordPress\\CmsSettingsScreen',
    'HeadlinesForge\\CmsSync' => 'TribuneDesk\\WordPress\\CmsSync',
    'HeadlinesForge\\DeliveriesLog' => 'TribuneDesk\\WordPress\\DeliveriesLog',
    'HeadlinesForge\\ImageHosts' => 'TribuneDesk\\WordPress\\ImageHosts',
    'HeadlinesForge\\Language' => 'TribuneDesk\\WordPress\\Language',
    'HeadlinesForge\\LinkResolver' => 'TribuneDesk\\WordPress\\LinkResolver',
    'HeadlinesForge\\MediaHandler' => 'TribuneDesk\\WordPress\\MediaHandler',
    'HeadlinesForge\\MediaMetaSync' => 'TribuneDesk\\WordPress\\MediaMetaSync',
    'HeadlinesForge\\MediaPush' => 'TribuneDesk\\WordPress\\MediaPush',
    'HeadlinesForge\\PostWriter' => 'TribuneDesk\\WordPress\\PostWriter',
    'HeadlinesForge\\ProvenanceMeta' => 'TribuneDesk\\WordPress\\ProvenanceMeta',
    'HeadlinesForge\\PublishNotifier' => 'TribuneDesk\\WordPress\\PublishNotifier',
    'HeadlinesForge\\PublishedUrlsClient' => 'TribuneDesk\\WordPress\\PublishedUrlsClient',
    'HeadlinesForge\\ReadMore' => 'TribuneDesk\\WordPress\\ReadMore',
    'HeadlinesForge\\RestEndpoint' => 'TribuneDesk\\WordPress\\RestEndpoint',
    'HeadlinesForge\\Sanitizer' => 'TribuneDesk\\WordPress\\Sanitizer',
    'HeadlinesForge\\SeoMeta' => 'TribuneDesk\\WordPress\\SeoMeta',
    'HeadlinesForge\\Settings' => 'TribuneDesk\\WordPress\\Settings',
    'HeadlinesForge\\UpdateManifest' => 'TribuneDesk\\WordPress\\UpdateManifest',
    'HeadlinesForge\\Updater' => 'TribuneDesk\\WordPress\\Updater',
    'HeadlinesForge\\WebhookSignature' => 'TribuneDesk\\Protocol\\WebhookSignature',
    'HeadlinesForge\\WriteResult' => 'TribuneDesk\\WordPress\\WriteResult',
];
