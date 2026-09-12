# R2 Property Image Storage Review

## Scope

- Requirement: `FR-RE-006`, `AC-017`
- Keep the existing `primary_image_url` catalog contract and Cloudflare Images compatibility.
- Add Cloudflare R2 as an opt-in direct-upload provider for one property hero image.

## Implemented

- Added an R2 filesystem disk backed by the S3-compatible API.
- Added short-lived presigned `PUT` generation for allowlisted JPEG, PNG and WebP content types.
- The browser sends the exact signed headers and stores only the configured public HTTPS URL.
- The provider secret remains runtime-only; the access key ID appears only as the standard signer identifier in
  the presigned URL. Unknown drivers and incomplete configuration fail closed.
- Added a bounded production-origin CORS template for the selected R2 bucket.

## Verification completed

- Composer resolution added the S3/Flysystem adapter without updating unrelated packages.
- The production Management image built successfully on GCP `dev-container-1`; no Docker workload ran on
  the local Mac.
- The full Laravel test suite passed: 207 tests and 1,029 assertions. The R2 upload slice passed 9 tests
  and 32 assertions.
- Frontend typecheck and lint passed. Vite production build passed with its existing large-chunk warning.
- A real SDK presign check confirmed `Content-Type` is bound into the signature while browser-forbidden
  `Host` is removed from the client header set.
- The reviewed CORS policy was applied to R2 bucket `monica` and read back successfully for
  `https://mgmt.aionecloud.space` with `GET`, `HEAD` and `PUT`.
- Wrangler remote connectivity was verified by writing and reading one namespaced test object, then
  deleting that exact object. The bucket was read back at zero objects and zero bytes afterward.

## Required before production

- Attach a production custom domain to the bucket; do not use `r2.dev` for production. The current
  authoritative nameservers for `aionecloud.space` are at SpaceShip, not Cloudflare, so DNS migration or
  another reviewed public-delivery route is required before this step.
- Create a bucket-scoped Object Read & Write R2 credential and inject it privately into the Management
  runtime. Never paste it into chat or commit it.
- Exercise a real browser upload, confirm public delivery through the custom domain, then confirm the
  resulting image appears in Catalog API and LINE Flex.

## Known limits

- The 10 MB check is currently enforced by the browser UI. A caller could bypass that check while a
  presigned URL remains valid; a server/Worker upload gate is required if hard byte-size enforcement is
  mandatory.
- Replaced, abandoned and deleted-record objects are not automatically removed.
- This change does not move private PDF source documents to R2.
- No R2 credential, custom domain, production runtime setting or deployment was created by this slice.
