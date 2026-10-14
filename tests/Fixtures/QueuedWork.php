<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

class QueuedWork extends PlainWork implements ShouldQueue {}
