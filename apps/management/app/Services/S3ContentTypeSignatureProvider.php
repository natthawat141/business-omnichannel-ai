<?php

namespace App\Services;

use Aws\Signature\SignatureProvider;

class S3ContentTypeSignatureProvider
{
    public static function provide($version, $service, $region)
    {
        return match ($version) {
            's3v4', 'v4' => new S3ContentTypeSignature($service, $region),
            'v4-unsigned-body' => new S3ContentTypeSignature($service, $region, ['unsigned-body' => true]),
            default => (SignatureProvider::version())($version, $service, $region),
        };
    }
}
