# CI Tooling Centralization Plan

**Date**: 2026-03-16
**Status**: Assessment complete, ready for implementation
**Goal**: All CI/dev tooling centralized in `netresearch/typo3-ci-workflows`. Extension repos hold only minimal custom config. Skills enforce this.

---

## 1. Current State

### 1.1 netresearch/typo3-ci-workflows (composer package + GitHub repo)

**What exists:**

- **composer.json** provides all dev tools as `require` (not `require-dev`): phpstan, php-cs-fixer, infection, rector, phplint, captainhook, phpat, typo3-rector, typo3-fractor, testing-framework, phpstan-extension-installer + all phpstan plugins (strict-rules, deprecation-rules, phpunit, typo3).
- **config/phpstan/phpstan.neon** -- shared PHPStan config at level 10 with common ignoreErrors, excludePaths, bootstrapFiles. Extensions include it via `%currentWorkingDirectory%/.Build/vendor/netresearch/typo3-ci-workflows/config/phpstan/phpstan.neon`.
- **config/php-cs-fixer/** -- shared rules and config.
- **config/rector/** -- shared rector config.
- **config/captainhook/** -- shared captainhook config.
- **Makefile.include** -- shared Make targets that delegate to `composer ci:*` scripts.
- **`.github/workflows/ci.yml`** -- reusable workflow (776 lines). Uses `composer ci:test:php:*` scripts with auto-detection fallback. Does NOT use `runTests.sh`.
- **No `Build/Scripts/runTests.sh`** in the package.

**What's wrong:**

1. **No runTests.sh shipped.** The composer package does not include a `Build/Scripts/runTests.sh`. Each extension either copies one from another extension or generates one ad-hoc. The typo3-testing skill has a 499-line Docker-based template in `assets/Build/Scripts/runTests.sh`, but extensions don't inherit it from the composer package.

2. **CI workflow uses composer scripts, not runTests.sh.** The reusable `ci.yml` calls `composer ci:test:php:cgl`, `composer ci:test:php:phpstan`, etc. This is intentional (CI runs natively on GitHub runners, not in Docker). But it creates two entrypoints: runTests.sh (local Docker) vs composer scripts (CI native). This is acceptable IF both delegate to the same underlying tool invocations.

3. **captainhook + git worktree conflict unaddressed.** When using `composer install --no-plugins` (workaround for captainhook crashing in git worktrees where `.git` is a file not a directory), `phpstan/extension-installer` also doesn't run. This means phpstan-strict-rules and phpstan-deprecation-rules are not auto-registered locally, creating local/CI baseline mismatch. The package does not document or solve this.

4. **phpunit/phpunit not included.** The package provides `typo3/testing-framework` (which pulls phpunit), but some extensions also add `phpunit/phpunit` explicitly in their own require-dev.

### 1.2 Extension Repos

#### t3x-nr-passkeys-fe (this repo)

| Aspect | Status |
|--------|--------|
| `require-dev` | `netresearch/typo3-ci-workflows ^1.1` + 3 extras (ergebnis/phpstan-rules, dg/bypass-finals, giorgiosironi/eris) |
| `runTests.sh` | Yes, local 80+ line non-Docker wrapper script |
| Composer scripts | Delegate to `Build/Scripts/runTests.sh` (good) |
| PHPStan extension-installer | In allow-plugins (good) |

**Assessment**: Best current state. Uses centralized package, composer scripts delegate to runTests.sh. Extra deps are extension-specific (bypass-finals for mocking final TYPO3 classes, eris for property-based testing, ergebnis for stricter phpstan rules) -- these are legitimate additions.

#### t3x-nr-passkeys-be

| Aspect | Status |
|--------|--------|
| `require-dev` | **Does NOT use** `netresearch/typo3-ci-workflows`. Lists 12 individual deps: captainhook, php-cs-fixer, infection, phpstan, phpunit, phpstan-typo3, testing-framework, phpat, bypass-finals, ergebnis/phpstan-rules |
| `runTests.sh` | **No** (`Build/Scripts/` only has `check-tag-version.sh`) |
| Composer scripts | Call tools directly: `phpunit -c Build/phpunit.xml`, `phpstan analyse -c Build/phpstan.neon` |
| Missing from centralized | phpstan-strict-rules, phpstan-deprecation-rules, phplint, rector, typo3-rector, typo3-fractor |

**Assessment**: Worst case. Fully independent, no centralization. Missing several quality tools. No runTests.sh. Composer scripts call binaries directly.

#### t3x-cowriter

| Aspect | Status |
|--------|--------|
| `require-dev` | `netresearch/typo3-ci-workflows ^1.1` + `phpunit/phpunit` |
| `runTests.sh` | Yes, 328-line script (non-Docker, local wrapper) |
| Composer scripts | Call tools directly: `phpstan analyze --configuration Build/phpstan.neon`, `phpunit -c Build/phpunit/UnitTests.xml` |
| Extra phpunit | Redundant -- testing-framework already pulls phpunit |

**Assessment**: Mixed. Uses centralized package but composer scripts don't delegate to runTests.sh. Has runTests.sh but scripts bypass it.

#### t3x-nr-llm

| Aspect | Status |
|--------|--------|
| `require-dev` | **Does NOT use** `netresearch/typo3-ci-workflows`. Lists 12 individual deps including grumphp (different hook system than captainhook) |
| `runTests.sh` | Yes, 520-line script |
| Composer scripts | Call tools directly |
| Conflicts | Uses `phpro/grumphp` instead of `captainhook` |

**Assessment**: Fully independent. Different hook system (grumphp vs captainhook). Has its own runTests.sh but doesn't use centralized package.

### 1.3 Skills

#### typo3-testing (v5.7.0)

- **SKILL.md** says `Build/Scripts/runTests.sh -s unit` etc. as the primary test runner.
- **references/test-runners.md** states "Extensions MUST have a Docker-based Build/Scripts/runTests.sh" (emphasis in original).
- **references/quality-tools.md** still shows `composer require --dev phpstan/phpstan phpstan/phpstan-strict-rules` as individual installs, NOT `netresearch/typo3-ci-workflows`.
- **assets/Build/Scripts/runTests.sh** -- 499-line Docker-based template exists.
- **Does NOT check** whether extensions use the centralized `netresearch/typo3-ci-workflows` package.
- **Does NOT mention** the captainhook + git worktree + phpstan-extension-installer issue.

#### typo3-conformance (v2.6.0)

- **checkpoints.yaml** validates composer.json structure, TCA, deprecated APIs, ext_emconf, etc.
- **No checkpoint** for: require-dev centralization, runTests.sh existence, or `netresearch/typo3-ci-workflows` usage.
- **No checkpoint** for individual tool dep duplication.

#### typo3-ddev (v1.15.0)

- **checkpoints.yaml** validates DDEV configuration only.
- **No CI tooling checks** (expected, this is DDEV-specific).

---

## 2. Target State

### 2.1 netresearch/typo3-ci-workflows

1. **Ship a runTests.sh template** or a mechanism for extensions to use one. Two options:
   - **Option A**: Include `Build/Scripts/runTests.sh` in the composer package itself (installed to `.Build/vendor/netresearch/typo3-ci-workflows/Build/Scripts/runTests.sh`). Extensions symlink or wrapper-call it.
   - **Option B** (recommended): Keep runTests.sh as a per-extension file (customization needed for NETWORK, E2E URLs, mock services), but provide a generator script in the package that scaffolds it from the template.

2. **Document the two-entrypoint architecture**:
   - `Build/Scripts/runTests.sh` = local development (Docker-based, multi-PHP, multi-DB)
   - `composer ci:test:php:*` = CI (native GitHub runner, single PHP, single DB per matrix cell)
   - Both must invoke the same tool configs (same phpstan.neon, same php-cs-fixer config).

3. **Solve the captainhook + git worktree issue**:
   - Option: Ship a `config/phpstan/includes.neon` that explicitly includes all phpstan plugin neon files, so extensions don't depend on extension-installer running. Extensions include this file instead of relying on auto-discovery.
   - Document in README: when using `--no-plugins`, add explicit includes.

4. **Add phpunit/phpunit to require** (or document that testing-framework provides it).

### 2.2 Extension Repos

All Netresearch TYPO3 extensions should have:

```
require-dev:
  netresearch/typo3-ci-workflows: ^1.x
  # Extension-specific extras only (bypass-finals, eris, etc.)
```

Composer scripts should either:
- Delegate to `Build/Scripts/runTests.sh` (like passkeys-fe), OR
- Call tools via standard `composer ci:test:php:*` names that the CI workflow auto-detects

Each extension's `Build/phpstan.neon` should include the shared config:
```neon
includes:
    - %currentWorkingDirectory%/.Build/vendor/netresearch/typo3-ci-workflows/config/phpstan/phpstan.neon
```

### 2.3 Skills

#### typo3-testing

- `references/quality-tools.md` should recommend `netresearch/typo3-ci-workflows` as the single require-dev, not individual tool installs.
- Add a note about the captainhook + git worktree + extension-installer issue.
- Keep runTests.sh as MUST-have but clarify it's for local Docker-based testing, not CI.

#### typo3-conformance

Add new checkpoints:
- **TC-90**: `require-dev` should contain `netresearch/typo3-ci-workflows` (warning)
- **TC-91**: `require-dev` should NOT individually list tools provided by typo3-ci-workflows (warning)
- **TC-92**: `Build/Scripts/runTests.sh` should exist (info)
- **TC-93**: `Build/phpstan.neon` should include shared config from typo3-ci-workflows (warning)

---

## 3. Changes Needed (Dependency Order)

### Phase 1: Central Package Improvements

These changes go into `netresearch/typo3-ci-workflows` and unblock everything else.

#### Task 1.1: Add explicit phpstan plugin includes file
**Repo**: `netresearch/typo3-ci-workflows`
**File**: `config/phpstan/includes-all-plugins.neon`
**Change**: Create a neon file that explicitly includes all phpstan plugin neon files (strict-rules, deprecation-rules, phpunit, typo3, phpat) without relying on extension-installer. Extensions that use `--no-plugins` can include this instead.
**Why**: Solves the captainhook + git worktree issue where `--no-plugins` prevents extension-installer from registering phpstan plugins.

#### Task 1.2: Add runTests.sh generator/template
**Repo**: `netresearch/typo3-ci-workflows`
**File**: `scripts/scaffold-runTests.sh` (generator) + `assets/runTests.sh.template`
**Change**: Provide a scaffold command that generates a customized `Build/Scripts/runTests.sh` for an extension. Takes extension key, NETWORK name, E2E URL as parameters.
**Alternative**: Document that the typo3-testing skill provides the template and how to use it.

#### Task 1.3: Document the two-entrypoint architecture
**Repo**: `netresearch/typo3-ci-workflows`
**File**: `README.md` (update)
**Change**: Add architecture section explaining: CI workflow uses composer scripts (native runners), local uses runTests.sh (Docker). Both share the same tool configs.

#### Task 1.4: Document captainhook + worktree workaround
**Repo**: `netresearch/typo3-ci-workflows`
**File**: `README.md` or `docs/worktree-setup.md`
**Change**: Document the issue and the explicit-includes workaround.

### Phase 2: Extension Migrations

Each extension migration is independent. Can be parallelized.

#### Task 2.1: Migrate t3x-nr-passkeys-be
**Repo**: `netresearch/t3x-nr-passkeys-be`
**Files**:
- `composer.json` -- Replace 12 individual require-dev entries with `netresearch/typo3-ci-workflows ^1.x` + keep extension-specific extras (ergebnis/phpstan-rules, dg/bypass-finals)
- `Build/phpstan.neon` -- Add include for shared config, remove duplicated settings
- `Build/Scripts/runTests.sh` -- Add (scaffold from template)
- Composer scripts -- Update to either delegate to runTests.sh or use standard `ci:test:php:*` naming
- `config.allow-plugins` -- Add missing plugin entries (a9f/fractor-extension-installer)

**Specific removals from require-dev**:
- `captainhook/captainhook` (provided by ci-workflows)
- `captainhook/hook-installer` (provided)
- `friendsofphp/php-cs-fixer` (provided)
- `infection/infection` (provided)
- `phpstan/extension-installer` (provided)
- `phpstan/phpstan` (provided)
- `saschaegerer/phpstan-typo3` (provided)
- `typo3/testing-framework` (provided)
- `phpat/phpat` (provided)
- `phpunit/phpunit` (provided via testing-framework)

**Keep in require-dev**:
- `ergebnis/phpstan-rules` (not in ci-workflows)
- `dg/bypass-finals` (not in ci-workflows)
- `netresearch/typo3-ci-workflows ^1.x` (add)

#### Task 2.2: Migrate t3x-nr-llm
**Repo**: `netresearch/t3x-nr-llm`
**Files**:
- `composer.json` -- Replace 12 individual require-dev entries with `netresearch/typo3-ci-workflows ^1.x` + keep extension-specific extras
- Switch from `phpro/grumphp` to `captainhook` (aligned with org standard)
- `Build/phpstan/phpstan.neon` -- Add include for shared config
- Existing `Build/Scripts/runTests.sh` (520 lines) -- Evaluate whether to keep or replace with standardized version

**Specific removals from require-dev**:
- `a9f/typo3-fractor` (provided)
- `friendsofphp/php-cs-fixer` (provided)
- `infection/infection` (provided)
- `phpat/phpat` (provided)
- `phpstan/phpstan` (provided)
- `rector/rector` (provided)
- `ssch/typo3-rector` (provided)
- `typo3/testing-framework` (provided)
- `phpro/grumphp` (replace with captainhook from ci-workflows)

**Keep in require-dev**:
- `enlightn/security-checker` (not in ci-workflows -- evaluate if `composer audit` replaces this)
- `ergebnis/composer-normalize` (not in ci-workflows)
- `fakerphp/faker` (test data generation, extension-specific)
- `giorgiosironi/eris` (property-based testing, extension-specific)
- `typo3/cms-install` (needed for functional tests, extension-specific)
- `netresearch/typo3-ci-workflows ^1.x` (add)

**Note**: `enlightn/security-checker` may be redundant since `composer audit` is built-in. Evaluate removal.

#### Task 2.3: Clean up t3x-cowriter
**Repo**: `netresearch/t3x-cowriter`
**Files**:
- `composer.json` -- Remove redundant `phpunit/phpunit` from require-dev (already provided via testing-framework in ci-workflows)
- Composer scripts -- Update to delegate to `Build/Scripts/runTests.sh` or standardize naming
- `Build/Scripts/runTests.sh` -- Evaluate 328-line script vs standardized template

**Change**: Remove `phpunit/phpunit ^11.2.5 || ^12.1.2` from require-dev.

#### Task 2.4: Verify t3x-nr-passkeys-fe
**Repo**: `netresearch/t3x-nr-passkeys-fe` (this repo)
**Status**: Already in good shape. Verify:
- Shared phpstan config is included
- runTests.sh is aligned with template
- No unnecessary deps

### Phase 3: Skill Updates

#### Task 3.1: Update typo3-testing quality-tools.md
**Repo**: `netresearch/typo3-testing-skill`
**File**: `references/quality-tools.md`
**Change**: Replace individual `composer require --dev` examples with `netresearch/typo3-ci-workflows`. Add section explaining what it provides. Keep individual install instructions as "if not using ci-workflows" fallback.

#### Task 3.2: Add captainhook + worktree note to typo3-testing
**Repo**: `netresearch/typo3-testing-skill`
**File**: `references/quality-tools.md` or new `references/git-worktree-setup.md`
**Change**: Document the `--no-plugins` issue and the explicit phpstan includes workaround.

#### Task 3.3: Add centralization checkpoints to typo3-conformance
**Repo**: `netresearch/typo3-conformance-skill`
**File**: `checkpoints.yaml`
**Changes**: Add checkpoints TC-90 through TC-93 as described in section 2.3.

```yaml
  # === CI TOOLING CENTRALIZATION ===
  - id: TC-90
    type: json_path
    target: composer.json
    pattern: '.["require-dev"]["netresearch/typo3-ci-workflows"]'
    severity: warning
    desc: "require-dev should use netresearch/typo3-ci-workflows for centralized CI tooling"

  - id: TC-91
    type: not_contains
    target: composer.json
    pattern: '"friendsofphp/php-cs-fixer"'
    scope: require-dev
    severity: warning
    desc: "require-dev should not individually list php-cs-fixer (provided by typo3-ci-workflows)"

  - id: TC-92
    type: file_exists
    target: Build/Scripts/runTests.sh
    severity: info
    desc: "Build/Scripts/runTests.sh should exist for local Docker-based test execution"

  - id: TC-93
    type: contains
    target: Build/phpstan.neon
    pattern: "typo3-ci-workflows/config/phpstan/phpstan.neon"
    severity: warning
    desc: "PHPStan config should include shared config from typo3-ci-workflows"
```

---

## 4. Implementation Status

### Completed Tasks

| ID | Title | Status | PR/Commit |
|----|-------|--------|-----------|
| CIC-01 | Explicit phpstan plugin includes | **DONE** | [typo3-ci-workflows PR #20](https://github.com/netresearch/typo3-ci-workflows/pull/20) merged |
| CIC-02 | Architecture docs + worktree workaround | **DONE** | Same PR #20 |
| CIC-03 | runTests.sh template | **DONE** | Same PR #20 (`assets/Build/Scripts/runTests.sh.dist`) |
| CIC-04 | Release typo3-ci-workflows | **DONE** | Tagged v1.2.0 |
| CIC-08 | Fix t3x-nr-passkeys-fe | **DONE** | Commit `87851b0` — includes explicit neon, 227-error baseline |
| CIC-09 | Update typo3-testing skill | **DONE** | [PR #29](https://github.com/netresearch/typo3-testing-skill/pull/29) merged |
| CIC-10 | Add conformance checkpoints | **DONE** | [PR #25](https://github.com/netresearch/typo3-conformance-skill/pull/25) merged |

### Remaining Tasks

| ID | Title | Repo | Effort | Details |
|----|-------|------|--------|---------|
| CIC-05 | Migrate t3x-nr-passkeys-be | `netresearch/t3x-nr-passkeys-be` | Medium | Replace 12 require-dev with ci-workflows, add runTests.sh, include explicit phpstan neon, regenerate baseline |
| CIC-06 | Migrate t3x-nr-llm | `netresearch/t3x-nr-llm` | Large | Replace 12 require-dev, migrate grumphp→captainhook, include explicit phpstan neon |
| CIC-07 | Clean up t3x-cowriter | `netresearch/t3x-cowriter` | Small | Remove redundant phpunit, align composer scripts with runTests.sh |

### Key Lesson Learned

The `phpstan/extension-installer` doesn't run when `composer install --no-plugins` is used (workaround for captainhook in git worktrees). This leaves `GeneratedConfig.php` as a stub with `EXTENSIONS = []`, meaning phpstan-strict-rules, deprecation-rules, and ergebnis rules are NOT loaded locally. CI runs `composer install` normally, so all rules ARE loaded → baseline mismatch.

**Solution**: Include `config/phpstan/includes-no-extension-installer.neon` from typo3-ci-workflows, which explicitly loads all plugin neon files without relying on the extension-installer. This makes local and CI analysis identical regardless of `--no-plugins`.

All future extension migrations (CIC-05, CIC-06, CIC-07) MUST include this explicit neon file in their `Build/phpstan.neon`.

---

## 6. Risks and Trade-offs

### Risk: runTests.sh divergence
**Problem**: Each extension will still have its own runTests.sh with customizations.
**Mitigation**: The skill template provides a standardized base. The scaffold script reduces copy-paste. Periodic audits via conformance skill.

### Risk: grumphp to captainhook migration (t3x-nr-llm)
**Problem**: Different hook systems have different config formats and capabilities.
**Mitigation**: captainhook config is provided by ci-workflows. Migration is a one-time cost.

### Risk: Breaking changes in shared phpstan config
**Problem**: Adding shared ignoreErrors or changing level could affect all extensions.
**Mitigation**: Extensions override level in their own phpstan.neon. Shared config uses `reportUnmatched: false` on ignoreErrors.

### Trade-off: Docker-based runTests.sh vs native CI
**Decision**: Keep both. runTests.sh for local (Docker ensures consistent environment), composer scripts for CI (faster, no Docker overhead). This is the TYPO3 core pattern.
**Consequence**: Two entrypoints to maintain, but each serves a different purpose.

### Trade-off: Strict enforcement vs gradual adoption
**Decision**: Conformance checkpoints use `severity: warning` not `severity: error`. Extensions can migrate gradually.
**Consequence**: Some extensions may remain on individual deps for a while. Acceptable during transition.
