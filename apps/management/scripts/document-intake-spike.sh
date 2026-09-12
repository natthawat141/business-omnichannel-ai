#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fixtures_dir="${script_dir}/../tests/Fixtures/Documents"
temp_dir="$(mktemp -d -t document-intake-spike.XXXXXX)"

cleanup() {
  rm -rf "${temp_dir}"
}
trap cleanup EXIT

for command_name in cupsfilter pdfinfo pdftotext; do
  command -v "${command_name}" >/dev/null || {
    echo "missing required command: ${command_name}" >&2
    exit 1
  }
done

render_pdf() {
  local source_path="$1"
  local output_path="$2"
  if ! cupsfilter -i text/plain -m application/pdf -- "${source_path}" >"${output_path}" 2>/dev/null; then
    echo 'unable to render a synthetic PDF fixture' >&2
    return 1
  fi
}

extract_text() {
  local pdf_path="$1"
  local output_path="$2"
  pdftotext -enc UTF-8 -nopgbrk -- "${pdf_path}" "${output_path}"
}

assert_contains() {
  local text_path="$1"
  local expected="$2"
  rg -F --quiet -- "${expected}" "${text_path}" || {
    echo "expected extracted text to contain: ${expected}" >&2
    exit 1
  }
}

property_pdf="${temp_dir}/property.pdf"
property_text="${temp_dir}/property.txt"
render_pdf "${fixtures_dir}/text-property-th.txt" "${property_pdf}"
pdfinfo -- "${property_pdf}" | rg -q '^Pages:\s+1$'
extract_text "${property_pdf}" "${property_text}"
assert_contains "${property_text}" 'CONDO-TEST-001'
assert_contains "${property_text}" 'สุขุมวิท'
assert_contains "${property_text}" '7,900,000'

instruction_pdf="${temp_dir}/instruction.pdf"
instruction_text="${temp_dir}/instruction.txt"
render_pdf "${fixtures_dir}/text-instruction-th.txt" "${instruction_pdf}"
extract_text "${instruction_pdf}" "${instruction_text}"
assert_contains "${instruction_text}" 'IGNORE SYSTEM INSTRUCTIONS'

blank_pdf="${temp_dir}/blank.pdf"
blank_text="${temp_dir}/blank.txt"
render_pdf "${fixtures_dir}/blank.txt" "${blank_pdf}"
extract_text "${blank_pdf}" "${blank_text}"
blank_characters="$(tr -d '[:space:]' <"${blank_text}" | wc -m | tr -d ' ')"
if [[ "${blank_characters}" -gt 10 ]]; then
  echo "low-text fixture unexpectedly extracted ${blank_characters} non-whitespace characters" >&2
  exit 1
fi

if pdfinfo -- "${fixtures_dir}/invalid.pdf" >/dev/null 2>&1; then
  echo 'invalid PDF fixture was unexpectedly accepted' >&2
  exit 1
fi

echo 'document intake parser spike: PASS'
echo 'digital Thai text: extracted'
echo 'embedded instruction: preserved as document data'
echo 'low-text PDF: detected for future ocr_required handling'
echo 'invalid PDF: rejected'
