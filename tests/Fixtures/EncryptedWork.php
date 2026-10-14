<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;

class EncryptedWork extends RecordWork implements ShouldBeEncrypted {}
