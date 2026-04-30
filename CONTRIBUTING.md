# Contributing to vektra-moodle

Thanks for taking the time to contribute. This document captures the
conventions specific to this repo. For project-wide context (architecture,
how the plugin fits into the Vektra RAG platform), see [README.md](README.md).

## Branch flow

| Branch prefix | Target | Use |
|---------------|--------|-----|
| `feat/*`      | `develop` | new features |
| `fix/*`       | `develop` | bug fixes |
| `docs/*`      | `develop` | documentation only |
| `chore/*`     | `develop` | dependency / tooling changes |
| `develop`     | `main`    | release PRs |

`main` is the release branch. Feature/fix branches are never merged
directly into `main`. After a release merge to `main`, back-merge `main`
into `develop` only if `main` has commits that `develop` doesn't (e.g.
hotfixes applied directly to `main`).

## Commit conventions

- Use [Conventional Commits](https://www.conventionalcommits.org/) for the
  subject line: `feat(scope):`, `fix(scope):`, `docs(scope):`,
  `chore(scope):`, etc.
- Keep the subject under ~70 characters; put the rationale and any breaking
  change details in the body.
- Sign your commits. Branch protection on `develop` and `main` requires
  signed commits and rejects unsigned ones from any merge type. Use SSH
  signing (recommended on contributor machines) or GPG; verify locally with
  `git log --show-signature -1`.

## Changelog

We follow [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/). The
file must always have an `[Unreleased]` section at the top, even if empty.

**During development** — every PR with user-visible impact adds an entry
under `[Unreleased]` in `CHANGELOG.md`, using one of the six standard
sections:

- **Added** — new features
- **Changed** — changes in existing functionality
- **Deprecated** — features that will be removed in upcoming releases
- **Removed** — features removed in this release
- **Fixed** — bug fixes
- **Security** — vulnerability fixes

Internal-only changes (refactors with no behavior change, CI tweaks,
test-only edits, dev-environment fixes) do not require a changelog entry.

**At release time** — in the release-prep PR (`docs/vX.Y.Z-release-prep`):

1. Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`
2. Add a fresh empty `## [Unreleased]` block above the new release entry
3. Bump `$plugin->release` in `version.php` from `'X.Y.(Z-1)'` to `'X.Y.Z'`
4. Update `$plugin->version` (the numeric `YYYYMMDDXX` Moodle stamp) if the
   release is on a different day than the last commit that touched it

After merging to `main`, tag `vX.Y.Z` to trigger the release workflow
(`.github/workflows/release.yml`), which builds the plugin zip and creates
the GitHub release with auto-generated notes.

## PR checklist

Before opening a PR:

- [ ] PHP syntax check passes (`find . -name '*.php' -print0 | xargs -0 -n1 php -l`)
- [ ] Moodle Code Style passes (CI runs `phpcs --standard=moodle` on every
      PR)
- [ ] New behavior has been manually verified on Moodle 5.1
- [ ] Commit messages follow Conventional Commits and are signed
- [ ] `CHANGELOG.md` has an `[Unreleased]` entry for any user-visible change
- [ ] PR description explains the change and motivation

The `.github/PULL_REQUEST_TEMPLATE.md` mirrors this list — check the boxes
as you go.

## Local development environment

See [`docker/README.md`](docker/README.md) for the bind-mounted Moodle 5.1
container that runs the plugin against a live vektra-stack on the same
host. Source changes are reflected immediately; after any
`version.php` or lang-string change run:

```bash
docker exec vektra-moodle php /var/www/html/admin/cli/upgrade.php --non-interactive
docker exec vektra-moodle php /var/www/html/admin/cli/purge_caches.php
```

## Reporting issues

Open a GitHub issue with: Moodle version, PHP version, Vektra RAG version,
steps to reproduce, expected vs. actual behaviour. Logs from
`docker logs vektra-moodle` and the browser console (if widget-related)
help a lot.
