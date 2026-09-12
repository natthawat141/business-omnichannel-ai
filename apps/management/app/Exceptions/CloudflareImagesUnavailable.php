<?php

namespace App\Exceptions;

use RuntimeException;

class CloudflareImagesUnavailable extends RuntimeException
{
    // Domain exception intentionally carries no upstream response body or credential.
}
