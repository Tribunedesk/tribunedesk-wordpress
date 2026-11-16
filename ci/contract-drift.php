<?php
declare(strict_types=1);

// php ci/contract-drift.php --body-out FILE [--open-pr-manifest FILE]; see ci/ContractDrift.php.

require __DIR__ . '/GuideAnchors.php';
require __DIR__ . '/ContractDrift.php';

use TribuneDesk\Ci\ContractDrift;

exit(ContractDrift::run($argv, dirname(__DIR__), [ContractDrift::class, 'git']));
