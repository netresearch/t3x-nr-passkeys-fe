<!-- FOR AI AGENTS - Scoped to .github/workflows/ -->
<!-- Last updated: 2026-03-15 -->

# .github/workflows/ AGENTS.md

**Scope:** GitHub Actions CI/CD workflows for `nr_passkeys_fe`.

## Workflow Table

| File | Trigger | Purpose |
|------|---------|---------|
| `ci.yml` | push, pull_request | Main CI: CGL, PHPStan, Unit tests, Functional tests, JS tests, Mutation |
| `pr-quality.yml` | pull_request | PR Quality Gates: size check, auto-approve Dependabot/Renovate |
| `ter-publish.yml` | release: published | Publish to TYPO3 Extension Repository (TER) |
| `codeql.yml` | push main, schedule | GitHub CodeQL security scanning (PHP + JS) |
| `dependency-review.yml` | pull_request | Dependency vulnerability review |
| `scorecard.yml` | schedule, push main | OpenSSF Security Scorecard |
| `auto-merge-deps.yml` | pull_request | Auto-merge minor/patch Dependabot PRs |
| `release.yml` | push tag `v*` | Create GitHub release with attestations |
| `security.yml` | push main, PR, schedule | TYPO3 security checks |

## CI Workflow Details

### ci.yml
Runs a matrix build across PHP 8.2/8.3/8.4/8.5 × TYPO3 13.4/14.1.

Steps in order:
1. Checkout
2. Composer install
3. CGL check (`composer ci:test:php:cgl`)
4. PHPStan (`composer ci:test:php:phpstan`)
5. Unit tests (`composer ci:test:php:unit`)
6. Fuzz tests (`composer ci:test:php:fuzz`)
7. Functional tests (`composer ci:test:php:functional`) -- MySQL service
8. JS tests (`npx vitest run`)
9. Architecture tests (part of unit suite)

### ter-publish.yml
Triggered on push of tags matching `v*`.

Steps:
1. Validate tag matches `ext_emconf.php` version
2. Strip `v` prefix: `VERSION="${TAG#v}"` (tag is `v1.2.3`, version is `1.2.3`)
3. Build extension archive (exclude dev files)
4. Upload to TER via `typo3/tailor`

### codeql.yml
Runs PHP and JavaScript CodeQL analysis. Results appear in
GitHub Security → Code scanning alerts.

## Rules

- All workflow files must pin actions to SHA (not tags)
- Use `fail_level: error` for all reviewdog-based linting actions
- Do NOT use `--no-verify` in any workflow step
- Secrets: `TYPO3_TER_ACCESS_TOKEN` (set in repo secrets)
- Functional tests require the `mysql` service with the test database

## Adding a New Workflow

1. Create `new-workflow.yml` in `.github/workflows/`
2. Update this AGENTS.md table
3. Pin all external actions to full SHAs:
   ```bash
   gh api repos/OWNER/REPO/tags --jq '.[0] | "\(.name) \(.commit.sha)"'
   ```
4. Test on a feature branch before merging

## Boundaries
- Do NOT hardcode secrets in workflow files
- Do NOT skip hooks or checks (`--no-verify`, `continue-on-error: true`)
  unless there is a documented reason in a comment
- Workflow changes touching `.github/workflows/` must be merged manually
  (GITHUB_TOKEN lacks the `workflows` scope for auto-merge)
