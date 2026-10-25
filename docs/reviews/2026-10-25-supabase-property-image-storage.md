# Supabase Storage for property images

## Decision

The product owner selected Supabase Storage instead of R2 for new property-image uploads on 2026-10-25.
The `primary_image_url` catalog contract and Cloudflare Images compatibility remain unchanged. Existing
R2 URLs continue serving their current images; no object copy, deletion, or production deployment is part
of this source change.

## Implementation

- Added the `supabase` property-image driver. It generates a short-lived, single-object S3-compatible
  presigned `PUT` with the exact allowed content type.
- Upload URLs must use HTTPS and the project's Supabase Storage S3 hostname/path. Credentials stay in the
  Management runtime; responses contain only the scoped URL, required upload headers, and public delivery
  URL.
- The `property-images` bucket is public, with a 10 MB limit and JPEG/PNG/WebP MIME restrictions.
- The local Compose root `.env` has the Supabase URL, S3 endpoint, region, bucket, and S3 credentials under
  the variable names consumed by Laravel. The local driver is set to `supabase`; no credential values are
  recorded here or in source control.
- Supabase S3 keys are project-wide storage credentials that bypass RLS, not bucket-scoped credentials.
  Use a separate S3 credential for this backend and never expose it to the browser.
- A Supabase `sb_secret_...` API key is not an S3 key. Rotate any secret API key shared outside its
  approved secret store before production use.

## Verification and remaining work

- Signed `PUT` upload of a temporary 1x1 PNG returned HTTP 200; its public URL returned HTTP 200 with
  matching image bytes and `image/png`; the temporary object was deleted successfully.
- The Supabase S3 endpoint returned a signature-required response, the expected public bucket URL reached
  object lookup, and Management-origin CORS preflight for `PUT` returned HTTP 200.
- A `Content-Type` header array from the Laravel S3 adapter caused URL validation to fail; the service
  now normalizes array values before validating and returning the signed headers.
- No production VM change or deployment was performed. GCP denied read access to the target VM, so its
  current environment, active driver, admin-browser upload, Catalog API output, and LINE rendering remain
  unverified.
- Existing R2 objects remain in place. Migrate them only as a separate reviewed operation.
