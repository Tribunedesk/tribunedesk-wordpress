<?php
declare(strict_types=1);

// php ci/release-contract.php <version>; see ci/ReleaseContract.php. Run by release.yml.

require __DIR__ . '/GuideAnchors.php';
require __DIR__ . '/ReleaseContract.php';

use TribuneDesk\Ci\ReleaseContract;

if (($argv[1] ?? '') === '') {
    fwrite(STDERR, "usage: php ci/release-contract.php <version>\n");
    exit(2);
}
chdir(dirname(__DIR__));
exit(ReleaseContract::run($argv[1], dirname(__DIR__), [ReleaseContract::class, 'git']));
