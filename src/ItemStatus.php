<?php

namespace Tey\Docket;

/** @api */
enum ItemStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
