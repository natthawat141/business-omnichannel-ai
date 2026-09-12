<?php

namespace App\Services;

use Aws\Signature\S3SignatureV4;

class R2ContentTypeSignature extends S3SignatureV4
{
    /**
     * Browsers can reproduce Content-Type for a direct upload, so keep it in
     * the signature instead of using the AWS SDK's general presign deny list.
     *
     * @return array<string, bool>
     */
    protected function getPresignHeaderDenyList(): array
    {
        return [
            'x-amz-user-agent' => true,
        ];
    }
}
