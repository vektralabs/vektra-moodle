# Implementation Plan: Block edit form + plugin settings — instructor configuration UI for v0.5.0 contract

**ID**: 20260425-083324-instructor-config-ui
**Status**: active
**Branch**: feat/v050-instructor-config-ui (to be created from `develop`)
**Created**: 2026-04-25T08:33:24Z
**Updated**: 2026-04-25T09:15:00Z

## Traceability

**Source**: feat14-moodle-contract-checklist (`../vektra-internal/moodle/feat14-moodle-contract-checklist.md`)
**Source Type**: topic

External dependency:
- vektra-stack PR #69 / branch `feat/v050-feat-014-sources-visibility` — provides `PATCH /api/v1/admin/namespaces/{id}/config` (whitelist: `grounding_mode`, `show_sources`) and `GET /api/v1/admin/namespaces/{id}/config` returning `{namespace_id, config, resolved}`. PR #69 stays open during this milestone; merges only after Moodle E2E proves the contract.

## References

### Requirements
- N/A (no `requirements.md`; requirements derived from the checklist + planning prompt)

### Architecture
- N/A (no `architecture.md`; tech stack and constraints inherited from `.s2s/CONTEXT.md` and `.claude/CLAUDE.md`)

### Decisions
- 2-level configuration model decided 2026-04-22 (plugin-global site settings + per-block instance overrides).
- **Visual brand (primary color, logo, powered-by text/url) is plugin-global ONLY** — no per-course override, no admin flag to opt in. Rationale: brand consistency, fewer settings = fewer failure modes.
- Per-course settings are limited to: course identity (`course_id`, `namespace`), display variants the teacher legitimately owns (`title`, `welcome_message`, `theme`, `language`), and the two backend-bound behavioral keys (`grounding_mode`, `show_sources`).
- vektra-stack adds `GET /admin/namespaces/{id}/config` returning `{config, resolved}` to support form pre-population (checklist § G1, G2).

### Dependencies
- vektra-stack `feat/v050-feat-014-sources-visibility` branch must remain healthy on Kalypso :10800 throughout development. Already verified 2026-04-24.

## Overview

Wires the v0.5.0 + FEAT-014 vektra-stack contract into the Moodle plugin's two configuration surfaces:

1. **Plugin site settings** (`settings.php`, admin once per Moodle install) — visual brand defaults (color, logo, powered-by text/url) and one behavioral default (`default_show_sources`).
2. **Block instance config** (`edit_form.php`, per-course) — adds `welcome_message` (visual) and behavioral fields (`grounding_mode`, `show_sources`) that proxy to the Vektra namespace config via PATCH.

Block render (`block_vektra.php`) gains the new `data-*` attributes with a simple resolution chain (instance > plugin setting > omit). The HTTP client (`vektra_client`) gains `get_namespace_config` + `patch_namespace_config`. i18n is extended (en + it). A `db/upgrade.php` script bumps the plugin version and seeds defaults.

The UX target: teacher opens the form, behavioral fields pre-populate from the live backend (with "Effective: …" helper text), teacher overrides or inherits, save persists (best-effort PATCH + always-save configdata), reopening the form reflects current backend state.

## Design Notes

**Resolution chain (block render)**: instance config > plugin site setting > attribute omitted (widget default applies).

**Save semantics — best-effort PATCH, always save configdata** (revised after review, was PATCH-first abort):
- On form submit, save instance `configdata` first via parent flow.
- Then call PATCH for behavioral keys.
- On PATCH failure: surface localized warning notification via `\core\notification::warning(...)`. Do NOT abort save.
- Rationale: avoids broken UX of throwing inside form save flow. Drift is self-correcting because behavioral fields are NOT cached in configdata — re-opening the form re-fetches via GET, so any stale teacher intent is visible immediately and re-submitted.

**GET resilience and latency**: `vektra_client::get_namespace_config()` uses a short timeout (`2s` curl, `1s` connect) when called from the form, to avoid blocking form render. PATCH on submit uses the standard 5s/3s.

**Configdata schema (instance) — no UI hint duplication** (revised after review):
```
title              (existing, kept)
course_id          (existing, kept)
namespace          (existing, kept)
theme              (existing, kept)
language           (existing, kept)
welcome_message    (NEW, PARAM_TEXT, max 500 chars)
```
Behavioral fields (`grounding_mode`, `show_sources`) are NOT stored in configdata. Backend is the single source of truth; form pre-pops from GET on every open.

**Plugin-global settings schema** (`mdl_config_plugins` under `block_vektra/`):
```
apiurl                   (existing)
publicurl                (existing)
apikey                   (existing)
default_theme            (existing)
default_primary_color    (NEW, optional hex)
default_logo_url         (NEW, optional URL → data-icon)
powered_by_text          (NEW, optional text)
powered_by_url           (NEW, optional URL)
default_show_sources     (NEW, bool, default 1)
```
**No `default_grounding_mode`** — too risky to flip globally; per-course only.
**No `allow_teacher_color_override` / `allow_teacher_hide_powered_by` flags** — visual brand is plugin-global, period.

**Default title pattern**: `get_string('default_title', 'block_vektra', $coursefullname)` →
- `lang/en`: "Assistant for {$a}"
- `lang/it`: "Assistente di {$a}"

**show_sources tri-state on form**: `select` with options `inherit / yes / no`. On submit:
- `inherit` → send `"show_sources": null` in PATCH (clears stored override → backend falls back to env default).
- `yes`/`no` → send `true`/`false`.
On the data-* attribute, emit `"true"`/`"false"` (per widget contract, verified at `vektra-stack/vektra-learn/widget/src/index.js`); omit attribute when `inherit` (widget asks backend).

**grounding_mode tri-state on form**: `select` with options `inherit / strict / hybrid`. PATCH: `inherit` → `null`; others → string. Not exposed as a `data-*` attr (backend-resolved at query time).

**`data-powered-by` is never emitted**: widget default is "show". Since this milestone never wants to hide attribution, omit the attribute entirely. `data-powered-by-text` / `data-powered-by-url` ARE emitted when admin set custom values (they override default text/url but don't toggle visibility).

**"Effective: …" helper labels — i18n composition in PHP** (revised after review): build the label by composing localized tokens (`config_grounding_strict`, `config_status_inherited`, etc.) so Italian UI doesn't bleed English values. Example:
```php
$status = $resolved->grounding_mode_overridden
    ? get_string('config_status_override', 'block_vektra')
    : get_string('config_status_default', 'block_vektra');
$value = get_string('config_grounding_' . $resolved->grounding_mode, 'block_vektra');
$effective = get_string('config_effective', 'block_vektra', "$value ($status)");
```

**Whitelist mirror (G6)**: form select options for `grounding_mode` and `show_sources` hardcoded. Backend whitelist is the authoritative validator. Acceptable manual-sync cost.

**Namespace resolution (form + save)**: never use `$COURSE` global. Use `$this->page->course->shortname` from the block context, or compute from `$this->instance->parentcontextid → context_course → course` when `page` is unset. The `$data->namespace` form field overrides if non-empty.

## Tasks

Phase A — Foundations
- [ ] A1. Bump `version.php`: `$plugin->version = 2026042500`, `$plugin->release = '0.4.0'`.
- [ ] A2. Create `db/upgrade.php` with the standard Moodle skeleton (`MOODLE_INTERNAL` check, `xmldb_block_vektra_upgrade($oldversion)`). For `$oldversion < 2026042500`: `set_config('default_show_sources', '1', 'block_vektra')`. End with `upgrade_block_savepoint(true, 2026042500, 'vektra')`. No DB schema change.
- [ ] A3. Extend `classes/vektra_client.php`:
    - `get_namespace_config(string $namespace, int $timeout = 5): ?array` → GETs `/api/v1/admin/namespaces/{ns}/config`, returns `['config' => [...], 'resolved' => [...]]` or `null` on failure. Logs `debugging()` on error with redacted Authorization header. Configurable timeout (form callers pass `2`, default for other callers is `5`).
    - `patch_namespace_config(string $namespace, array $payload): array` → PATCHes the same endpoint. Returns `['ok' => true]` on 2xx, or `['ok' => false, 'error_code' => 'ERR-ADMIN-XXX', 'message' => string]` on 4xx/5xx. Maps error codes from response body.
    - Both methods: Moodle `\curl` wrapper, JSON content type, Bearer auth (same pattern as `generate_token`).
- [ ] A4. PHP syntax check on the modified file via `docker exec vektra-moodle php -l ...`.

Phase B — Plugin site settings (`settings.php`)
- [ ] B1. Add "Branding" heading + fields:
    - `default_primary_color` (`admin_setting_configtext`, `PARAM_TEXT`, hex pattern enforced via help text — Moodle has no native hex param).
    - `default_logo_url` (`admin_setting_configtext`, `PARAM_URL`).
- [ ] B2. Add "Powered-by attribution" heading + fields:
    - `powered_by_text` (`admin_setting_configtext`, `PARAM_TEXT`).
    - `powered_by_url` (`admin_setting_configtext`, `PARAM_URL`).
- [ ] B3. Add "Behavioral defaults" heading + field:
    - `default_show_sources` (`admin_setting_configcheckbox`, default `1`).
- [ ] B4. Add corresponding `lang/en/` + `lang/it/` strings (Phase E).

Phase C — Block instance config form (`edit_form.php`)
- [ ] C1. Extend the existing block-settings header with one new field:
    - `config_welcome_message` — `textarea`, `PARAM_TEXT`, `maxlength=500`, optional. Help: "First assistant bubble shown to students. Empty = widget default."
- [ ] C2. Add a new "Behavioral (Vektra)" header section with two fields:
    - `config_grounding_mode` — `select`: `inherit | strict | hybrid`.
    - `config_show_sources_choice` — `select`: `inherit | yes | no`.
- [ ] C3. Override `data_preprocessing(&$defaults)` to pre-populate behavioral fields:
    - Resolve namespace: `$this->block->config->namespace ?? $this->page->course->shortname`.
    - Call `vektra_client::get_namespace_config($namespace, 2)` (short timeout).
    - On success:
        - `$defaults['config_grounding_mode']` = `$config['grounding_mode']` if set, else `'inherit'`.
        - `$defaults['config_show_sources_choice']` = `$config['show_sources'] === true ? 'yes' : ($config['show_sources'] === false ? 'no' : 'inherit')`.
        - Store `$resolved` on the form instance for use by C4.
    - On failure: `\core\notification::warning(get_string('config_get_failed', 'block_vektra'))`. Defaults remain `'inherit'`. Store `null` for resolved → C4 hides the helper.
- [ ] C4. Add static "Effective: …" labels next to behavioral fields, composed in PHP from localized tokens (see Design Notes "i18n composition"). Hide the labels when `resolved` is null (GET failed).
- [ ] C5. Implement `validation($data, $files)`:
    - `config_welcome_message`: trim + reject if > 500 chars.
    - `config_grounding_mode`: must be in `['inherit', 'strict', 'hybrid']`.
    - `config_show_sources_choice`: must be in `['inherit', 'yes', 'no']`.

Phase D — Save flow (best-effort PATCH)
- [ ] D1. Override `block_vektra::instance_config_save($data, $nolongerused = false)`:
    - Translate behavioral fields to PATCH payload:
      - `grounding_mode`: `'inherit'` → `null`; `'strict'`/`'hybrid'` → string.
      - `show_sources`: `'inherit'` → `null`; `'yes'` → `true`; `'no'` → `false`.
    - Resolve namespace from `$data->namespace` or `$this->page->course->shortname`.
    - **Strip behavioral fields from `$data` before parent save** (so they don't pollute configdata): `unset($data->grounding_mode, $data->show_sources_choice)`.
    - Call `parent::instance_config_save($data, $nolongerused)` — persists configdata first, always.
    - Call `vektra_client::patch_namespace_config($namespace, $payload)`.
    - On `['ok' => false]`: `\core\notification::warning(get_string($errorcode, 'block_vektra'))`. Do NOT abort.
    - On success: silent.
- [ ] D2. Confirm that `instance_config_save` is the correct hook in Moodle 5.1 `block_base` (it is; existing pattern in core blocks).

Phase E — Block render (`block_vektra.php`)
- [ ] E1. Add private resolver methods (kept minimal — visual brand is plugin-only):
    - `resolve_title(): string` — `$this->config->title` if non-empty, else `get_string('default_title', 'block_vektra', $this->page->course->fullname)`.
    - `resolve_welcome_message(): ?string` — `$this->config->welcome_message ?? null`.
    - `resolve_show_sources_attr(): ?string` — instance.show_sources_choice not stored (see Design Notes); read from configdata only if a future migration adds it. For now, return `null` always. **Wait — reconsider**: behavioral fields are NOT in configdata, so the render layer cannot know the per-instance `show_sources` choice without re-fetching from backend on every render. That's a perf cost we don't want.
    - **Decision**: per-instance `show_sources` is enforced backend-side via the namespace config (PATCH on form save). The widget asks the backend at query time. The plugin does NOT need to emit `data-show-sources`. Drop the attribute from the plan entirely.
    - Net resolvers: `resolve_title()`, `resolve_welcome_message()`. Plugin-global values (`default_primary_color`, `default_logo_url`, `powered_by_text`, `powered_by_url`) are read directly via `get_config('block_vektra', ...)` in `get_content()`.
- [ ] E2. In `get_content()`, conditionally add the new `data-*` keys to `$attributes`:
    - `data-title` — always emit (default falls back to localized "Assistente di {fullname}").
    - `data-primary-color` — from `default_primary_color`, only if non-empty.
    - `data-icon` — from `default_logo_url`, only if non-empty.
    - `data-welcome-message` — from instance, only if non-empty.
    - `data-powered-by-text` — from `powered_by_text`, only if non-empty.
    - `data-powered-by-url` — from `powered_by_url`, only if non-empty.
    - **No `data-powered-by`** (always visible — widget default; explicit decision).
    - **No `data-show-sources`** (backend-resolved per namespace; widget reads `show_sources` field from `/learn/query` response).
- [ ] E3. Update `block_vektra::specialization()` to use `resolve_title()` for the block header title (currently reads `$this->config?->title` directly).

Phase F — i18n (en + it)
- [ ] F1. Extend `lang/en/block_vektra.php` with new strings:
    - Render: `default_title` ("Assistant for {\$a}").
    - Settings: `settings_branding`, `settings_branding_desc`, `settings_default_primary_color`, `settings_default_primary_color_desc`, `settings_default_logo_url`, `settings_default_logo_url_desc`, `settings_attribution`, `settings_attribution_desc`, `settings_powered_by_text`, `settings_powered_by_text_desc`, `settings_powered_by_url`, `settings_powered_by_url_desc`, `settings_behavioral`, `settings_behavioral_desc`, `settings_default_show_sources`, `settings_default_show_sources_desc`.
    - Form: `config_behavioral`, `config_welcome_message`, `config_welcome_message_help`, `config_grounding_mode`, `config_grounding_mode_help`, `config_show_sources`, `config_show_sources_help`, `config_inherit`, `config_grounding_strict`, `config_grounding_hybrid`, `config_yes`, `config_no`, `config_effective` ("Effective: {\$a}"), `config_status_default` ("default"), `config_status_override` ("override").
    - Errors / notifications: `config_get_failed`, `err_admin_005`, `err_admin_006`, `err_admin_007`, `err_admin_unknown`.
    - Validation: `error_welcome_too_long`, `error_invalid_grounding`, `error_invalid_show_sources`.
- [ ] F2. Mirror everything in `lang/it/block_vektra.php`.

Phase G — Privacy + cleanup
- [ ] G1. Re-read `classes/privacy/provider.php`. Confirm no PII added (welcome message is teacher-authored config, not user data). Update `privacy:metadata` string only if prose needs adjustment; provider class unchanged.
- [ ] G2. Surgical cleanup of any pre-existing direct `$this->config?->X` reads in `block_vektra.php` that the new resolvers cover. Do NOT touch unrelated lines.

Phase H — Manual end-to-end test
- [ ] H1. Bring up the local stack (SSH tunnel from another machine if needed: `ssh -L 10180:localhost:10180 -L 10800:localhost:10800 fr4@kalypso -N`). Verify Moodle container has `vektra-stack_default` network attached.
- [ ] H2. Run plugin upgrade: `docker exec vektra-moodle php /var/www/html/admin/cli/upgrade.php --non-interactive`. Confirm version `2026042500`.
- [ ] H3. **Backwards compat**: open a course with a pre-existing block instance (no `welcome_message`, no behavioral overrides). Confirm:
    - Block renders without errors.
    - Script tag has new attrs only when admin has set plugin-global brand (otherwise omitted).
    - Default title is "Assistente di {fullname}".
- [ ] H4. Admin: set `default_primary_color`, `default_logo_url`, `powered_by_text`, `powered_by_url`. Reload course → confirm the four new `data-*` attrs in the script tag (DevTools).
- [ ] H5. Teacher: open block edit form. Confirm:
    - "Behavioral (Vektra)" section visible.
    - Behavioral fields show `inherit` initially.
    - "Effective: strict (default)" / "Effective: yes (default)" labels rendered.
    - GET observed in Vektra logs (single call per form open).
- [ ] H6. Teacher: set `welcome_message="Ciao!"`, `grounding_mode=hybrid`, `show_sources=no`. Save. Confirm:
    - Configdata persisted (`welcome_message`, no behavioral keys).
    - PATCH observed with `{"grounding_mode": "hybrid", "show_sources": false}`.
    - Block render emits `data-welcome-message="Ciao!"`. (No `data-show-sources`; backend handles it.)
    - Asking the chatbot returns sources hidden in the response (verify widget behavior).
- [ ] H7. Teacher: reopen form. Confirm fields show `hybrid` / `no`, helper labels show "Effective: hybrid (override)" / "Effective: no (override)".
- [ ] H8. Teacher: set both back to `inherit`. Save. Confirm PATCH with `{"grounding_mode": null, "show_sources": null}`. Reopen → "Effective: strict (default)".
- [ ] H9. Backend down simulation: stop vektra container (`DOCKER_CONTEXT=rootless docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.ports.yml stop vektra` from `vektra-stack/`).
    - Open form → warning notification, behavioral fields default to `inherit`, helper labels hidden.
    - Submit form with welcome_message change → configdata saves, PATCH fails, warning notification shown, NO data loss.
    - Restart: `DOCKER_CONTEXT=rootless docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.ports.yml up -d vektra`. Reconnect Moodle network if needed: `docker --context rootless network connect vektra-stack_default vektra-moodle`.
- [ ] H10. Validation: 600-char welcome → form rejects with `error_welcome_too_long`.

Phase I — Coordination + ship
- [ ] I1. While developing: any contract gaps surfacing → push fix commits to vektra-stack `feat/v050-feat-014-sources-visibility` (PR #69 branch). Add a comment on PR #69 documenting each contract change.
- [ ] I2. Merge order: open Moodle PR → run E2E (Phase H) → confirm contract holds → merge vektra-stack PR #69 → merge Moodle PR.
- [ ] I3. Branch: `feat/v050-instructor-config-ui` from `develop`. Conventional Commits, signed. Use `/github-pr-creation` targeting `develop` of vektra-moodle.
- [ ] I4. Update `README.md`: add "Compatibility" section noting "block_vektra ≥ 0.4.0 requires vektra-stack ≥ v0.5.0 with FEAT-014".

## Acceptance Criteria

- [ ] AC-1. Plugin admin UI exposes (only): `default_primary_color`, `default_logo_url`, `powered_by_text`, `powered_by_url`, `default_show_sources`. Settings persist in `mdl_config_plugins`. NO admin flags for per-course overrides (visual brand is plugin-global).
- [ ] AC-2. Block edit form renders one new "Behavioral (Vektra)" section + the existing block-settings section gains a `welcome_message` field. Behavioral fields populate from `GET /api/v1/admin/namespaces/{id}/config` on form open. "Effective: …" labels reflect the `resolved` block, fully localized.
- [ ] AC-3. On form submit, behavioral fields PATCH `/api/v1/admin/namespaces/{id}/config` with whitelist (`grounding_mode`, `show_sources`). `inherit` → `null`. PATCH happens after `configdata` save (best-effort: PATCH failure shows warning but does NOT abort the save).
- [ ] AC-4. Block render emits `data-title` (always), `data-primary-color`, `data-icon`, `data-welcome-message`, `data-powered-by-text`, `data-powered-by-url` — each only when source is non-empty. NO `data-powered-by`, NO `data-show-sources` (backend-resolved).
- [ ] AC-5. Default title in render is the localized `"Assistente di {fullname}"` / `"Assistant for {fullname}"` when no override is set.
- [ ] AC-6. PATCH errors map to localized `ERR-ADMIN-005/006/007` messages via `\core\notification::warning`. Form save completes regardless (configdata persisted).
- [ ] AC-7. GET failure on form open shows a localized warning notification, behavioral fields default to `inherit`, and the "Effective" helper labels are hidden.
- [ ] AC-8. All new strings present in `lang/en/block_vektra.php` AND `lang/it/block_vektra.php`. No untranslated keys. "Effective: …" composes localized tokens (no English bleed in IT UI).
- [ ] AC-9. Privacy provider unchanged (no new PII). `privacy:metadata` string accurate.
- [ ] AC-10. `db/upgrade.php` exists, bumps to `2026042500`, seeds `default_show_sources=1`; `admin/cli/upgrade.php --non-interactive` runs clean.
- [ ] AC-11. **Backwards compat**: existing block instances (pre-upgrade, no `welcome_message`, no admin brand set) continue to work — block renders, default title applies, no errors.
- [ ] AC-12. Manual E2E (Phase H, all 10 steps) passes against vektra-stack `feat/v050-feat-014-sources-visibility`.
- [ ] AC-13. `php -l` clean on every modified PHP file.
- [ ] AC-14. Branch `feat/v050-instructor-config-ui` created from `develop`. Commits follow Conventional Commits format and are SSH-signed. PR opened against `vektra-moodle:develop`.

## Testing Approach

- **Static**: `php -l` on every modified PHP file via `docker exec vektra-moodle php -l /var/www/html/blocks/vektra/<file>.php`.
- **Unit tests**: this plugin currently has no PHPUnit suite. Out of scope for this milestone (would be a separate technical task). Note as follow-up.
- **Manual E2E**: Phase H tasks H1–H10 on the local Kalypso Docker stack.
- **Backend verification**: `curl -s -H "Authorization: Bearer $VEKTRA_API_KEY" http://localhost:10800/api/v1/admin/namespaces/<ns>/config | jq` after each PATCH.
- **Regression**: existing flows (token generation, AJAX refresh, theme/language overrides) continue to work — verify a baseline interaction (open course → click chat → ask a question → see answer) before and after.

## Integration Notes

- **vektra-stack dependency**: ships against `feat/v050-feat-014-sources-visibility` (PR #69). Moodle PR must NOT merge before PR #69. Coordinate: vektra-stack first, then vektra-moodle.
- **Contract feedback loop**: gaps discovered during Phase H get fixed on PR #69 branch (commit + push), not in a separate PR.
- **README**: add "Compatibility" section noting bidirectional requirement (`block_vektra ≥ 0.4.0` requires `vektra-stack ≥ v0.5.0 + FEAT-014`).
- **Branching**: from `develop`. Per project convention `feat/* → develop → main`; never feat → main directly.
- **Commit signing**: Kalypso SSH signing already configured. Branch protection rejects unsigned commits.
- **PR lifecycle**: `/github-workflow:github-pr-creation` → `/github-workflow:github-pr-review` → `/github-workflow:github-pr-merge`. Target `develop`.
- **Network reset reminder**: after vektra-stack container recreate, reconnect Moodle: `docker --context rootless network connect vektra-stack_default vektra-moodle`.
- **API key safety**: do NOT reset bootstrap or delete the admin API key during testing.

## Notes

- **Simplification vs initial draft** (recorded after first review): removed `allow_teacher_color_override` and `allow_teacher_hide_powered_by` admin flags; removed per-course `primary_color`, `show_powered_by`, `grounding_mode_ui`, `show_sources_choice` from configdata; removed `data-powered-by` emit (always visible); removed `data-show-sources` emit (backend-resolved). Net effect: one fewer admin section, one fewer form section, one fewer configdata key, two fewer render attributes — fewer settings, fewer failure modes.
- **Default for `default_grounding_mode`**: intentionally absent from plugin-global settings. Per-course only.
- **Behavioral fields not stored locally**: backend is the single source of truth. Re-fetched on every form open. Cost: one HTTP call per form open (2s timeout caps the latency).
- **`db/upgrade.php` is NEW**: plugin currently has only `db/access.php`. Use the standard Moodle skeleton with `upgrade_block_savepoint` at the end.
- **BACKLOG cleanup (separate task, not this plan)**: FEAT-002 (AJAX token refresh) is implemented in `ajax.php` but BACKLOG.md still marks it `in_progress`. Move to Completed in a follow-up commit.
- **Follow-up backlog candidates** (NOT in this plan):
  - PHPUnit suite scaffold (TECH-XXX)
  - Moodle Code Checker in CI (TECH-XXX)
