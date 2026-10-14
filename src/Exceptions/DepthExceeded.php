<?php

namespace Tey\Docket\Exceptions;

/** @api */
class DepthExceeded extends RunFailed
{
    public function __construct(int $maxDepth)
    {
        parent::__construct('run.depth_exceeded', ['max_depth' => $maxDepth]);
    }
}
