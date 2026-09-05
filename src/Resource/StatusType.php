<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

/**
 * Which kind of status a `Statuses` resource addresses.
 */
enum StatusType: string
{
    case Lead = 'lead';
    case Opportunity = 'opportunity';
}
