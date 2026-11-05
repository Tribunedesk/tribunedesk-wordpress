<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

enum WriteResult: string
{
    case Created = 'created';
    case Updated = 'updated';
    case SkippedAlreadyPublished = 'skipped_already_published';
}
