<?php

namespace App\Services;

use Aws\Signature\SignatureProvider;

class R2SignatureProvider
{
    public static function provide($version, $service, $region)
    {
        return match ($version) {
            's3v4', 'v4' => new R2ContentTypeSignature($service, $region),
            'v4-unsigned-body' => new R2ContentTypeSignature($service, $region, ['unsigned-body' => true]),
            default => (SignatureProvider::version())($version, $service, $region),
        };
    }
}
