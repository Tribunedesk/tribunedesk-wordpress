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
    /** An attempt of an older send arrived after a newer one (guide 1, "Order of sends"). Log only. */
    case SkippedOlderSend = 'skipped_older_send';

    /** The `status` of the delivery answer: the contract knows only the first three. */
    public function answer(): string
    {
        return $this === self::SkippedOlderSend ? self::Updated->value : $this->value;
    }
}
