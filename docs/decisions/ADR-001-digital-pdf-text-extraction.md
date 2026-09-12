# ADR-001: Digital PDF Text Extraction for the Document Intake Spike

## Status

**PROPOSED for Feature 0 review.** It is a local-development decision only; it does not change a
Docker image, production package, queue, provider credential, or deployed runtime.

## Context

The proposed Document Intake workflow needs a deterministic way to identify whether a PDF has a usable
text layer, count its pages, and extract bounded page text before any LLM work. The developer machine has
the Poppler commands `pdfinfo` and `pdftotext` installed. The Management Composer lock contains no PDF/OCR
library today.

The MVP must be small enough for a development machine with 8 GB RAM and must not require Docker.

## Decision

Use Poppler's `pdfinfo` and `pdftotext` as the **Feature 0 local spike** for text-based PDFs:

1. `pdfinfo` verifies a readable PDF and obtains page count.
2. `pdftotext` extracts only text content under explicit process timeout, page, and character limits.
3. The extraction adapter must expose an application interface; no controller or domain model may invoke a
   shell command directly.
4. A document with absent/insufficient text returns `ocr_required`; no OCR is installed or invoked in this
   decision.
5. Raw extracted content remains out of logs. Later Feature 2 must define private artifact retention and
   page-evidence authorization before storing extraction results.

## Alternatives considered

### Add a pure-PHP PDF library

Not selected for the spike. It would add Composer dependencies before proving Thai text/page extraction
quality. It remains a future option if a packaged Linux runtime is required and measured tests show it meets
the same quality and resource limits.

### Send a whole PDF directly to the LLM

Not selected. It would couple parser behavior to a provider/model capability, makes deterministic page
provenance harder, and sends more unbounded source data upstream. The approved conversation model/provider
must not be changed without a separate decision.

### Implement OCR now

Not selected. Thai OCR quality, cost, dependency size, privacy, and operating limits require a separate
Feature and Product Owner decision.

### Start Docker/Compose or a queue worker

Not selected. The Feature 0 spike is intentionally local and synchronous. No container, service, Redis, or
worker process is needed or permitted for this decision.

## Consequences

- The first supported documents are digital PDFs with a text layer.
- The future Linux image must install and pin the chosen Poppler package before Feature 2 can be deployed;
  that is a separate reviewed packaging change.
- The application must map command failure, timeout, malformed file, encrypted file, and low-text result to
  sanitized reason categories rather than exposing command stderr to admins.
- The implementation must use a safe argument list and a private file path, never interpolate filenames into
  shell strings.

## Evidence required to accept this ADR

- The local spike passes a synthetic Thai text fixture and an embedded-instruction fixture.
- A low-text fixture is categorized as `ocr_required`.
- A pseudo-PDF is rejected as invalid.
- The Review Packet records command output summaries, tool versions, and the fact that no Docker command ran.
