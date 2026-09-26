<!-- FOR AI AGENTS - Scoped to .github/workflows/ -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# .github/workflows/ AGENTS.md

## Overview

**Scope:** GitHub Actions CI/CD for `nr_passkeys_fe`. Every workflow is a thin
caller of a central reusable in `netresearch/typo3-ci-workflows` or
`netresearch/.github` — action pinning and hardening are maintained there.
`checks.yml` is byte-identical across all t3x extensions (drift-enforced via
`check-template-drift.yml`); the extension-specific test matrix lives in
`ci.yml` (intentional drift).

## Workflow files

| File | Trigger | Purpose (reusable called) |
|------|---------|---------------------------|
| `ci.yml` | push main, PR, merge_group, weekly | Test matrix via typo3-ci-workflows `ci.yml` |
| `checks.yml` | push main, PR, merge_group, weekly | Security+quality bundle with `All security checks` gate |
| `check-template-drift.yml` | push, PR, merge_group | Enforces checks.yml matches the org template |
| `docs.yml` | push, PR, merge_group, dispatch | Docs render check (typo3-ci-workflows `docs.yml`) |
| `harness-verify.yml` | push main, PR, dispatch | AGENTS.md/docs consistency (`Build/Scripts/verify-harness.sh`) |
| `security.yml` | push, PR, schedule | TYPO3 security checks (typo3-ci-workflows `security.yml`) |
| `codeql.yml` | push, PR, schedule | CodeQL analysis (PHP + JS) |
| `scorecard.yml` | push, schedule | OpenSSF Security Scorecard |
| `dependency-review.yml` | pull_request | Dependency vulnerability review |
| `pr-quality.yml` | pull_request | PR quality gates |
| `labeler.yml` | pull_request_target | Auto-label PRs by path |
| `auto-merge-deps.yml` | pull_request_target | Auto-merge minor/patch dependency PRs |
| `community.yml` | schedule, issues, PR target | Stale/lock/greetings automation |
| `release.yml` | push tag `v*` | GitHub release with attestations |
| `ter-publish.yml` | push tag `v*` | Publish to TER (typo3-ci-workflows `publish-to-ter.yml`) |

## Build & test pipeline (ci.yml)

`ci.yml` calls `netresearch/typo3-ci-workflows/.github/workflows/ci.yml@main` with:
- PHP 8.2 / 8.3 / 8.4 / 8.5 × TYPO3 `^13.4` / `^14.3`
- Functional tests enabled against MySQL
- Coverage upload to Codecov (`CODECOV_TOKEN` secret)

The reusable runs CGL, PHPStan, unit, fuzz, functional, and JS tests; the
PHPat architecture rules run inside PHPStan.

`checks.yml` bundles security/quality jobs behind one `All security checks`
gate job — that gate is the only requirable context (PR-only jobs never
materialize on merge_group refs). Any job added there MUST also be added to
`gate.needs`.

## Workflow conventions

- All workflow files must pin actions to SHA (not tags)
- Use `fail_level: error` for all reviewdog-based linting actions
- Do NOT use `--no-verify` in any workflow step
- Secrets: `TYPO3_TER_ACCESS_TOKEN`, `CODECOV_TOKEN` (set in repo secrets)
- Tag pushes (`v*`) trigger both `release.yml` and `ter-publish.yml`; the TER
  version is the tag without the `v` prefix and must match `ext_emconf.php`

## Security

- Central reusables carry harden-runner and SHA-pinned actions; do not inline
  third-party actions here without pinning to a full commit SHA
- Top-level `permissions:` stays `{}` or `contents: read`; grant per-job only
  what the called reusable's contract requires
- Never echo secrets; `pull_request_target` workflows must not check out PR code

## Checklist (adding a workflow)

1. Prefer extending a central reusable over inline steps
2. Add the file here and a row to the table above
3. If it belongs to the security bundle, add it to `checks.yml` AND its `gate.needs`
4. Test on a feature branch before merging

## Examples

- Thin-caller shape: `ci.yml` (matrix inputs only, logic in the reusable)
- Gate pattern and its pitfalls: read the comments in `checks.yml`

## When stuck

- Reusable inputs/behavior: read the workflow source in
  `netresearch/typo3-ci-workflows` / `netresearch/.github`
- Drift check failing: sync `checks.yml` from the org template, do not hand-edit
- Merge-queue stuck on a required check: see the gate comment in `checks.yml`

## Boundaries
- Do NOT hardcode secrets in workflow files
- Do NOT skip hooks or checks (`--no-verify`, `continue-on-error: true`)
  unless there is a documented reason in a comment
- Workflow changes touching `.github/workflows/` must be merged manually
  (GITHUB_TOKEN lacks the `workflows` scope for auto-merge)
