---
name: release
description: Cut a full multi-channel TableCrafter release (GitHub premium + free, Freemius, WP.org SVN, marketing) with every version location enforced. Use when the user asks to "release", "cut a release", "ship a new version", or "push a release everywhere". Drives tools/release.sh, which hard-fails on any stale version location so nothing is silently skipped.
---

# Release

You are the **driver**; `tools/release.sh` is the **enforcer**. Your job is the
judgment (version number, changelog prose) and orchestration (branch, PR, the one
confirm gate). The script refuses to proceed when any version location is stale, so
you cannot skip a step even if context is lost mid-way.

Run everything from the plugin repo (`~/websites/tablecrafter/tc-work`). Never bump a
version by hand — `release.sh bump` does every location. Follow PR-only: version +
changelog land on `main` via a release PR before publishing.

## Steps

1. **Pick the version.** Read current `TC_VERSION`; the merged PRs since the last tag
   (`git log $(git describe --tags --abbrev=0)..main --oneline`) tell you what's
   shipping. Propose the next version (default: bump the patch; per the bump-at-9 rule,
   only roll a digit when it would otherwise hit 10). **Confirm the number with the user.**

2. **Preflight:** `tools/release.sh preflight <ver>` — fails unless on `main`, clean,
   up-to-date, siblings present, and the full `test-all.sh` gate is green.

3. **Branch:** `git checkout -b release/<ver>`.

4. **Bump:** `tools/release.sh bump <ver>` — bumps all version literals (plugin, both
   readmes, JS, bundle, **and marketing**).

5. **Write the changelog** (the prose the script can't). Draft a user-facing entry from
   the merged PRs and add it to ALL of these — the same block, newest-first:
   - `readme.txt` (`= <ver> =` under `== Changelog ==`)
   - `readme-free.txt` (`= <ver> =`)
   - `docs/CHANGELOG.md` (`## [<ver>] — …`)
   - `admin/views/documentation.php` (a `v<ver>` `<h3>` in the What's New section)
   - `admin/views/dashboard.php` (a `'version' => '<ver>'` entry at the top of `$changelog`)

6. **Verify:** `tools/release.sh verify <ver>` — runs `VersionConsistencyTest` +
   the marketing guard. If it fails it names exactly what's stale/missing; fix and re-run.

7. **Land it:** commit (`release: <ver> …`), push, open a release PR, **squash-merge to
   main**, `git checkout main && git pull`.

8. **Build:** `tools/release.sh build <ver>` — prunes dev deps, builds premium + free zips.

9. **Go/no-go gate.** Write release notes to `/tmp/rel-notes.md`. Show the user a single
   summary: version, changelog, what will publish (GitHub ×2, Freemius, WP.org SVN,
   marketing). **Wait for one explicit "yes."** This is the only confirm.

10. **Publish + audit** (after "yes"): source secrets, then
    `source ~/.tablecrafter-secrets && export FS_BEARER_TOKEN SVN_PASSWORD` and run
    `tools/release.sh publish <ver>` then `tools/release.sh audit <ver>`. Publish is
    idempotent — each channel skips if already at the target. The commands that hit the
    network (Freemius, SVN commit, marketing deploy) need the sandbox disabled.

## Recovery

If a channel fails partway, just re-run from step 10: `release.sh audit <ver>` shows
what's done, and `release.sh publish <ver>` resumes only the unfinished channels. Never
roll back — Freemius/SVN/tags can't be cleanly undone; always resume forward.

## Dry run

`TC_RELEASE_DRYRUN=1 tools/release.sh publish <ver>` plans the publish with no side
effects — use it to preview or when unsure.
