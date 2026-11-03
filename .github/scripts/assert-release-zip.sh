#!/usr/bin/env bash
# Assert the content of a release zip (listing, not workflow status).
# Usage: assert-release-zip.sh <zip> <prefix>
set -euo pipefail

zip="$1"
prefix="$2"
list="$(unzip -Z1 "$zip")"

echo "${list}" | head -40

fail=0

# Must-have entries.
if ! grep -qx "${prefix}/version.php" <<<"${list}"; then
  echo "::error::${prefix}/version.php missing from the zip"
  fail=1
fi
if ! grep -q "^${prefix}/tests/" <<<"${list}"; then
  echo "::error::${prefix}/tests/ missing from the zip (Moodle plugins ship their tests)"
  fail=1
fi
# Compiled JS is shipped whenever there is JS source.
if grep -q "^${prefix}/amd/src/" <<<"${list}" && ! grep -q "^${prefix}/amd/build/" <<<"${list}"; then
  echo "::error::${prefix}/amd/build/ missing although amd/src/ is present"
  fail=1
fi

# Allowlist (first layer): the zip may contain ONLY the expected plugin surface. Anything new and
# unanticipated (deploy.sh, secrets.yml, backup.sql, *.tfstate, ...) fails closed; extend the lists
# deliberately when the plugin genuinely grows a new top-level file or directory.
top_files='version\.php|block_vektra\.php|ajax\.php|edit_form\.php|settings\.php|lib\.php|styles\.css|README\.md|LICENSE|CHANGELOG\.md|CONTRIBUTING\.md'
top_dirs='classes|db|lang|tests|amd|pix|templates'
# Inside the allowed directories only plugin source/asset file types are accepted.
file_ext='php|js|map|css|mustache|png|svg|gif|jpg|jpeg|feature|md|txt|xml'
unexpected="$(grep -Ev "^${prefix}/?$|^${prefix}/(${top_files})$|^${prefix}/(${top_dirs})/?$|^${prefix}/(${top_dirs})/.*/$|^${prefix}/(${top_dirs})/.*\.(${file_ext})$" <<<"${list}" || true)"
if [ -n "${unexpected}" ]; then
  echo "::error::Entries outside the allowed plugin surface in the zip:"
  echo "${unexpected}"
  fail=1
fi

# Forbidden entries (second layer, also catches dev artefacts nested in allowed directories): development artefacts and anything that could hold secrets.
forbidden="$(grep -E '(^|/)(\.s2s|\.claude|\.github|\.superpowers|docker[^/]*|n8n|spike)/|(^|/)CTX-MEMORY\.md$|(^|/)\.env' <<<"${list}" || true)"
if [ -n "${forbidden}" ]; then
  echo "::error::Forbidden paths in the zip:"
  echo "${forbidden}"
  fail=1
fi

if [ "${fail}" -ne 0 ]; then
  exit 1
fi
echo "Release zip content OK ($(wc -l <<<"${list}") entries)"
