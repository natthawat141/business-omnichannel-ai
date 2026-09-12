# Synthetic Document Fixtures

These source files contain no customer data, personal data, credentials, or production identifiers.

`document-intake-spike.sh` converts the `.txt` fixtures to temporary PDFs with macOS CUPS/Poppler tools,
then removes the temporary directory. Generated PDFs are intentionally not committed.

| Fixture | Purpose |
|---|---|
| `text-property-th.txt` | Thai text-layer PDF baseline with catalog values |
| `text-instruction-th.txt` | Untrusted document text that must remain data, not a system instruction |
| `blank.txt` | Low-text PDF; expected future application status is `ocr_required` |
| `invalid.pdf` | Wrong magic/signature validation case |

This is a Feature 0 parser spike, not an OCR quality suite. Real scanned-PDF fixtures are deferred until
the OCR provider and data-retention decision are approved.
