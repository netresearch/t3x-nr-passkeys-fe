# nr_passkeys_fe Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a TYPO3 extension providing passkey-first frontend authentication for fe_users, with recovery codes, enforcement, and admin management.

**Architecture:** Depends on `netresearch/nr-passkeys-be` for `ChallengeService` and `RateLimiterService`. Has its own `FrontendWebAuthnService` wrapping `web-auth/webauthn-lib` directly (the BE's `WebAuthnService` is hardcoded to `be_users` and cannot be reused). Own FE auth service at priority 80, eID-based API endpoints, Extbase plugins for UI, PSR-15 middleware for enrollment interstitial. Database stores FE credentials scoped by site+storagePid.

**Tech Stack:** PHP 8.2+, TYPO3 v13.4/v14.1, web-auth/webauthn-lib ^5.2 (via nr-passkeys-be), PHPUnit 11, PHPStan level 10, Infection, PHPat, Eris, Playwright, Vitest

**Spec:** `docs/superpowers/specs/2026-03-14-nr-passkeys-fe-design.md`
**ADRs:** `Documentation/Adr/Adr001-012*.rst`
**Reference extension:** `/home/cybot/projects/t3x-nr-passkeys-be/main/` — mirror patterns from this extension

---

## File Structure

### Root Files
| File | Responsibility |
|------|---------------|
| `composer.json` | Dependencies, autoload, scripts |
| `ext_emconf.php` | TYPO3 extension metadata (NO strict_types) |
| `ext_localconf.php` | Auth service registration, cache config, FormEngine, logging |
| `ext_tables.sql` | Database schema (credentials, recovery codes, fe_groups/fe_users extensions) |
| `ext_tables.php` | Backend module registration (if needed for v13 compat) |

### Build & CI
| File | Responsibility |
|------|---------------|
| `Build/.php-cs-fixer.php` | PER-CS3.0 code style rules |
| `Build/phpstan.neon` | PHPStan level 10 config with architecture tests |
| `Build/phpunit.xml` | Unit + fuzz test config |
| `Build/phpunit.functional.xml` | Functional test config |
| `Build/infection.json5` | Mutation testing config (MSI >= 80%) |
| `Tests/bootstrap.php` | PHPUnit bootstrap with bypass-finals |
| `.github/workflows/ci.yml` | CI pipeline (delegates to netresearch/typo3-ci-workflows) |
| `Makefile` | Developer shortcuts |

### Configuration
| File | Responsibility |
|------|---------------|
| `Configuration/Services.yaml` | DI container, event listeners, cache services |
| `Configuration/RequestMiddlewares.php` | PSR-15 middleware registration |
| `Configuration/TCA/tx_nrpasskeysfe_credential.php` | Credential table TCA |
| `Configuration/TCA/tx_nrpasskeysfe_recovery_code.php` | Recovery code table TCA |
| `Configuration/TCA/Overrides/fe_groups.php` | Add enforcement fields to fe_groups |
| `Configuration/TCA/Overrides/fe_users.php` | Add passkey fields to fe_users |
| `Configuration/TCA/Overrides/tt_content.php` | Plugin registration |
| `Configuration/TypoScript/setup.typoscript` | Plugin TypoScript setup |
| `Configuration/TypoScript/constants.typoscript` | TypoScript constants |
| `Configuration/FlexForms/LoginPlugin.xml` | Login plugin FlexForm |
| `Configuration/FlexForms/ManagementPlugin.xml` | Management plugin FlexForm |
| `Configuration/FlexForms/EnrollmentPlugin.xml` | Enrollment plugin FlexForm |
| `Configuration/Backend/Modules.php` | Admin backend module |
| `Configuration/Backend/AjaxRoutes.php` | Admin AJAX routes |
| `Configuration/Icons.php` | Icon registration |

### Domain Layer (Classes/Domain/)
| File | Responsibility |
|------|---------------|
| `Classes/Domain/Model/FrontendCredential.php` | Credential model (plain PHP, fromArray/toArray) |
| `Classes/Domain/Model/RecoveryCode.php` | Recovery code model |
| `Classes/Domain/Dto/FrontendEnforcementStatus.php` | Enforcement status value object |
| `Classes/Domain/Dto/FrontendAdoptionStats.php` | Adoption statistics DTO |
| `Classes/Domain/Enum/RecoveryMethod.php` | Recovery method enum |

### Events (Classes/Event/)
| File | Responsibility |
|------|---------------|
| `Classes/Event/BeforePasskeyEnrollmentEvent.php` | Pre-enrollment hook |
| `Classes/Event/AfterPasskeyEnrollmentEvent.php` | Post-enrollment hook |
| `Classes/Event/BeforePasskeyAuthenticationEvent.php` | Pre-auth hook |
| `Classes/Event/AfterPasskeyAuthenticationEvent.php` | Post-auth hook |
| `Classes/Event/PasskeyRemovedEvent.php` | Credential removal hook |
| `Classes/Event/RecoveryCodesGeneratedEvent.php` | Recovery code generation hook |
| `Classes/Event/MagicLinkRequestedEvent.php` | Magic link request hook (v0.2) |
| `Classes/Event/EnforcementLevelResolvedEvent.php` | Enforcement override hook |

### Services (Classes/Service/)
| File | Responsibility |
|------|---------------|
| `Classes/Service/FrontendWebAuthnService.php` | WebAuthn ceremonies wrapping `web-auth/webauthn-lib` with FE-specific RP ID/credential resolution |
| `Classes/Service/SiteConfigurationService.php` | Per-site RP ID, origin, enforcement, site resolution from request |
| `Classes/Service/FrontendCredentialRepository.php` | Credential CRUD with site/storage scoping |
| `Classes/Service/FrontendEnforcementService.php` | Dual enforcement (site + groups) |
| `Classes/Service/RecoveryCodeService.php` | Code generation, bcrypt hashing, verification |
| `Classes/Service/PasskeyEnrollmentService.php` | Enrollment orchestration (public API) |
| `Classes/Service/FrontendAdoptionStatsService.php` | Adoption metrics for admin |
| `Classes/Configuration/FrontendConfiguration.php` | Extension settings value object |

### FormEngine (Classes/Form/)
| File | Responsibility |
|------|---------------|
| `Classes/Form/Element/PasskeyFeInfoElement.php` | Read-only passkey info display in fe_users BE form |

### Authentication (Classes/Authentication/)
| File | Responsibility |
|------|---------------|
| `Classes/Authentication/PasskeyFrontendAuthenticationService.php` | FE auth chain, priority 80 |

### Controllers (Classes/Controller/)
| File | Responsibility |
|------|---------------|
| `Classes/Controller/LoginController.php` | Login ceremony eID endpoints |
| `Classes/Controller/ManagementController.php` | Self-service eID endpoints |
| `Classes/Controller/RecoveryController.php` | Recovery code endpoints |
| `Classes/Controller/EnrollmentController.php` | Post-login enrollment endpoints |
| `Classes/Controller/AdminModuleController.php` | Backend admin module |
| `Classes/Controller/AdminController.php` | Admin AJAX endpoints |

### Middleware & Event Listeners
| File | Responsibility |
|------|---------------|
| `Classes/Middleware/PasskeyPublicRouteResolver.php` | Mark eID routes as public |
| `Classes/Middleware/PasskeyEnrollmentInterstitial.php` | Enforce passkey setup |
| `Classes/EventListener/InjectPasskeyLoginFields.php` | felogin integration |
| `Classes/EventListener/InjectPasskeyBanner.php` | Enrollment banner |

### Frontend Resources
| File | Responsibility |
|------|---------------|
| `Resources/Public/JavaScript/PasskeyLogin.js` | Login ceremony |
| `Resources/Public/JavaScript/PasskeyEnrollment.js` | Registration ceremony |
| `Resources/Public/JavaScript/PasskeyManagement.js` | Self-service CRUD |
| `Resources/Public/JavaScript/PasskeyRecovery.js` | Recovery code entry |
| `Resources/Public/JavaScript/PasskeyBanner.js` | Enrollment prompt |
| `Resources/Public/Css/passkey-fe.css` | Default FE styles |
| `Resources/Private/Templates/Login/Index.html` | Login plugin template |
| `Resources/Private/Templates/Management/Index.html` | Management plugin template |
| `Resources/Private/Templates/Enrollment/Index.html` | Enrollment template |
| `Resources/Private/Language/locallang.xlf` | English translations |

---

## Chunk 1: Project Scaffolding & Build Infrastructure

### Task 1.1: Initialize Git Repository

**Files:**
- Create: `.gitignore`
- Create: `.gitattributes`
- Create: `.editorconfig`

- [ ] **Step 1: Initialize bare git repo and main worktree**

```bash
cd /home/cybot/projects/t3x-nr-passkeys-fe
git init --bare .bare
cd .bare && git config remote.origin.fetch "+refs/heads/*:refs/remotes/origin/*" && cd ..
git -C .bare worktree add ../main --orphan main
cd main
```

- [ ] **Step 2: Create .gitignore**

```gitignore
/.Build/
/composer.lock
/node_modules/
/var/
/.ddev/
/.env
/.vscode/
/.idea/
/.php-cs-fixer.cache
/infection.log
/infection-summary.log
```

- [ ] **Step 3: Create .editorconfig**

Mirror from `/home/cybot/projects/t3x-nr-passkeys-be/main/.editorconfig`.

- [ ] **Step 4: Create .gitattributes**

```gitattributes
/.github export-ignore
/Tests export-ignore
/Build export-ignore
/docs export-ignore
/.editorconfig export-ignore
/.gitattributes export-ignore
/.gitignore export-ignore
/Makefile export-ignore
/package.json export-ignore
```

- [ ] **Step 5: Commit**

```bash
git add .gitignore .editorconfig .gitattributes
git commit -S --signoff -m "chore: initialize project structure"
```

---

### Task 1.2: Create composer.json

**Files:**
- Create: `composer.json`

- [ ] **Step 1: Write composer.json**

```json
{
    "name": "netresearch/nr-passkeys-fe",
    "description": "Passkey-first TYPO3 frontend authentication for fe_users (WebAuthn/FIDO2) - by Netresearch",
    "license": "GPL-2.0-or-later",
    "type": "typo3-cms-extension",
    "keywords": ["TYPO3", "passkeys", "webauthn", "fido2", "passwordless", "authentication", "frontend"],
    "authors": [
        {
            "name": "Netresearch DTT GmbH",
            "homepage": "https://www.netresearch.de/",
            "role": "Developer"
        }
    ],
    "homepage": "https://github.com/netresearch/t3x-nr-passkeys-fe",
    "support": {
        "issues": "https://github.com/netresearch/t3x-nr-passkeys-fe/issues",
        "source": "https://github.com/netresearch/t3x-nr-passkeys-fe"
    },
    "require": {
        "php": "^8.2",
        "netresearch/nr-passkeys-be": "^0.6",
        "typo3/cms-core": "^13.4 || ^14.1",
        "typo3/cms-frontend": "^13.4 || ^14.1",
        "typo3/cms-backend": "^13.4 || ^14.1",
        "typo3/cms-extbase": "^13.4 || ^14.1",
        "typo3/cms-fluid": "^13.4 || ^14.1"
    },
    "require-dev": {
        "captainhook/captainhook": "^5.28",
        "captainhook/hook-installer": "^1.0",
        "ergebnis/phpstan-rules": "^2.6",
        "friendsofphp/php-cs-fixer": "^3.68",
        "infection/infection": "^0.32",
        "phpstan/extension-installer": "^1.4",
        "phpstan/phpstan": "^2.1",
        "phpunit/phpunit": "^11.5 || ^12.1",
        "saschaegerer/phpstan-typo3": "^3.0",
        "typo3/testing-framework": "^9.0",
        "phpat/phpat": "^0.12.2",
        "dg/bypass-finals": "^1.9",
        "giorgiosironi/eris": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Netresearch\\NrPasskeysFe\\": "Classes/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Netresearch\\NrPasskeysFe\\Tests\\": "Tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "typo3/class-alias-loader": true,
            "typo3/cms-composer-installers": true,
            "infection/extension-installer": true,
            "phpstan/extension-installer": true,
            "captainhook/hook-installer": true
        },
        "bin-dir": ".Build/bin",
        "vendor-dir": ".Build/vendor"
    },
    "extra": {
        "typo3/cms": {
            "extension-key": "nr_passkeys_fe",
            "web-dir": ".Build/Web"
        }
    },
    "scripts": {
        "ci:cgl": "php-cs-fixer fix --config Build/.php-cs-fixer.php",
        "ci:mutation": "infection --configuration=Build/infection.json5 --min-msi=80 --min-covered-msi=80",
        "ci:test:php:all": ["@ci:test:php:unit", "@ci:test:php:functional"],
        "ci:test:php:cgl": "php-cs-fixer fix --config Build/.php-cs-fixer.php --dry-run --diff",
        "ci:test:php:functional": "phpunit -c Build/phpunit.functional.xml",
        "ci:test:php:fuzz": "phpunit -c Build/phpunit.xml --testsuite fuzz",
        "ci:test:php:phpstan": "phpstan analyse -c Build/phpstan.neon",
        "ci:test:php:unit": "phpunit -c Build/phpunit.xml --testsuite unit"
    }
}
```

- [ ] **Step 2: Run composer install**

```bash
composer install
```

Expected: Dependencies install, .Build/ directory created.

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -S --signoff -m "chore: add composer.json with dependencies"
```

---

### Task 1.3: Create ext_emconf.php and ext_tables.sql

**Files:**
- Create: `ext_emconf.php`
- Create: `ext_tables.sql`

- [ ] **Step 1: Write ext_emconf.php**

```php
<?php

// Do NOT add declare(strict_types=1) — TER cannot parse ext_emconf.php with it.

$EM_CONF[$_EXTKEY] = [
    'title' => 'Passkeys Frontend Authentication',
    'description' => 'Passkey-first TYPO3 frontend authentication for fe_users (WebAuthn/FIDO2). Enables passwordless login with TouchID, FaceID, YubiKey, Windows Hello. By Netresearch.',
    'category' => 'fe',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
            'nr_passkeys_be' => '0.6.0-0.99.99',
            'frontend' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'felogin' => '13.4.0-14.99.99',
        ],
    ],
];
```

- [ ] **Step 2: Write ext_tables.sql**

Copy schema from spec (see spec §Database Schema). Include all 4 CREATE/ALTER statements:
- `tx_nrpasskeysfe_credential` with UNIQUE KEY and indexes
- `tx_nrpasskeysfe_recovery_code` with KEY
- `fe_groups` extension (passkey_enforcement, passkey_grace_period_days)
- `fe_users` extension (passkey_grace_period_start, passkey_nudge_until)

- [ ] **Step 3: Commit**

```bash
git add ext_emconf.php ext_tables.sql
git commit -S --signoff -m "chore: add extension metadata and database schema"
```

---

### Task 1.4: Create Build Configuration

**Files:**
- Create: `Build/.php-cs-fixer.php`
- Create: `Build/phpstan.neon`
- Create: `Build/phpunit.xml`
- Create: `Build/phpunit.functional.xml`
- Create: `Build/infection.json5`
- Create: `Tests/bootstrap.php`

- [ ] **Step 1: Write Build/.php-cs-fixer.php**

Mirror from `/home/cybot/projects/t3x-nr-passkeys-be/main/Build/.php-cs-fixer.php`.
Change paths to point to `../Classes`, `../Tests`, `../Configuration`.

- [ ] **Step 2: Write Build/phpstan.neon**

Mirror from nr-passkeys-be. Change:
- Namespace references from `NrPasskeysBe` to `NrPasskeysFe`
- Architecture test class name
- Remove v12-specific ignores (FE extension is v13+ only)

- [ ] **Step 3: Write Build/phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.5/phpunit.xsd"
         backupGlobals="true"
         bootstrap="../Tests/bootstrap.php"
         cacheResult="false"
         colors="true"
         executionOrder="depends,defects"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>../Tests/Unit</directory>
        </testsuite>
        <testsuite name="fuzz">
            <directory>../Tests/Fuzz</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">../Classes</directory>
        </include>
        <exclude>
            <directory>../Classes/Domain/Model</directory>
        </exclude>
    </source>
    <php>
        <ini name="display_errors" value="1"/>
        <env name="TYPO3_PATH_ROOT" value="../.Build/Web"/>
    </php>
</phpunit>
```

- [ ] **Step 4: Write Build/phpunit.functional.xml**

For TYPO3 functional tests with MySQL. Mirror pattern from nr-passkeys-be if it has one, or use TYPO3 testing framework defaults. Testsuite directory: `../Tests/Functional`.

- [ ] **Step 5: Write Build/infection.json5**

```json5
{
    "$schema": "https://raw.githubusercontent.com/infection/infection/0.29.0/resources/schema.json",
    "source": {
        "directories": [
            "../Classes/Service",
            "../Classes/Authentication",
            "../Classes/Controller",
            "../Classes/Domain/Dto",
            "../Classes/Domain/Enum",
            "../Classes/Configuration",
            "../Classes/EventListener",
            "../Classes/Middleware",
            "../Classes/Form"
        ]
    },
    // Note: Domain/Model is excluded from PHPUnit coverage and from Infection
    // (plain data objects with getters/setters, mutation testing adds no value)
    "timeout": 30,
    "testFramework": "phpunit",
    "logs": {
        "text": "../infection.log",
        "summary": "../infection-summary.log"
    },
    "tmpDir": "../.Build/var/infection",
    "phpUnit": {
        "configDir": ".",
        "customPath": "../.Build/bin/phpunit"
    },
    "mutators": {
        "@default": true
    },
    "minMsi": 80,
    "minCoveredMsi": 80
}
```

- [ ] **Step 6: Write Tests/bootstrap.php**

Mirror from `/home/cybot/projects/t3x-nr-passkeys-be/main/Tests/bootstrap.php`.

- [ ] **Step 7: Verify build tools work**

```bash
composer ci:test:php:cgl
composer ci:test:php:phpstan
```

Expected: Both pass (no PHP files to lint yet, but config is valid).

- [ ] **Step 8: Commit**

```bash
git add Build/ Tests/bootstrap.php
git commit -S --signoff -m "chore: add build configuration (phpstan, phpunit, cs-fixer, infection)"
```

---

### Task 1.5: Create CI/CD Workflows

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `.github/workflows/pr-quality.yml`
- Create: `.github/workflows/codeql.yml`
- Create: `.github/workflows/scorecard.yml`
- Create: `.github/workflows/ter-publish.yml`
- Create: `.github/workflows/auto-merge-deps.yml`
- Create: `.github/workflows/dependency-review.yml`
- Create: `.github/workflows/e2e.yml`
- Create: `.github/workflows/release.yml`
- Create: `.github/workflows/fuzz.yml`

- [ ] **Step 1: Write ci.yml**

```yaml
name: CI
on:
  push:
  pull_request:
  merge_group:
permissions: {}
jobs:
  ci:
    uses: netresearch/typo3-ci-workflows/.github/workflows/ci.yml@main
    permissions:
      contents: read
    with:
      php-versions: '["8.2", "8.3", "8.4", "8.5"]'
      typo3-versions: '["^13.4", "^14.1"]'
      typo3-packages: '["typo3/cms-core", "typo3/cms-frontend", "typo3/cms-backend", "typo3/cms-extbase", "typo3/cms-fluid"]'
      php-extensions: intl, mbstring, xml, openssl, pdo_mysql
      run-rector: false
      run-functional-tests: true
      functional-test-db: mysql
      remove-dev-deps: '[{"dep":"saschaegerer/phpstan-typo3","only-for":"^14"}]'
```

- [ ] **Step 2: Copy remaining workflow files from nr-passkeys-be**

Mirror pr-quality.yml, codeql.yml, scorecard.yml, ter-publish.yml, auto-merge-deps.yml, dependency-review.yml, e2e.yml, release.yml, fuzz.yml from `/home/cybot/projects/t3x-nr-passkeys-be/main/.github/workflows/`. Adjust extension name references.

- [ ] **Step 3: Commit**

```bash
git add .github/
git commit -S --signoff -m "ci: add GitHub Actions workflows"
```

---

### Task 1.6: Create Makefile

**Files:**
- Create: `Makefile`

- [ ] **Step 1: Write Makefile**

Standard targets: `help`, `ci`, `cgl`, `cgl-fix`, `phpstan`, `test`, `test-unit`, `test-functional`, `test-fuzz`, `mutation`, `clean`. Mirror pattern from nr-passkeys-be.

- [ ] **Step 2: Commit**

```bash
git add Makefile
git commit -S --signoff -m "chore: add Makefile with developer shortcuts"
```

---

## Chunk 2: Domain Layer + Unit Tests

### Task 2.1: RecoveryMethod Enum

**Files:**
- Create: `Classes/Domain/Enum/RecoveryMethod.php`
- Create: `Tests/Unit/Domain/Enum/RecoveryMethodTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Netresearch\NrPasskeysFe\Tests\Unit\Domain\Enum;

use Netresearch\NrPasskeysFe\Domain\Enum\RecoveryMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecoveryMethodTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        self::assertSame('password', RecoveryMethod::Password->value);
        self::assertSame('recovery_code', RecoveryMethod::RecoveryCode->value);
        self::assertSame('magic_link', RecoveryMethod::MagicLink->value);
    }

    #[Test]
    public function fromValidString(): void
    {
        self::assertSame(RecoveryMethod::Password, RecoveryMethod::from('password'));
    }

    #[Test]
    public function fromInvalidStringThrows(): void
    {
        $this->expectException(\ValueError::class);
        RecoveryMethod::from('invalid');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer ci:test:php:unit -- --filter RecoveryMethodTest`
Expected: FAIL (class not found)

- [ ] **Step 3: Write RecoveryMethod enum**

```php
<?php
declare(strict_types=1);
namespace Netresearch\NrPasskeysFe\Domain\Enum;

enum RecoveryMethod: string
{
    case Password = 'password';
    case RecoveryCode = 'recovery_code';
    case MagicLink = 'magic_link';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer ci:test:php:unit -- --filter RecoveryMethodTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add Classes/Domain/Enum/RecoveryMethod.php Tests/Unit/Domain/Enum/RecoveryMethodTest.php
git commit -S --signoff -m "feat: add RecoveryMethod enum"
```

---

### Task 2.2: FrontendCredential Model

**Files:**
- Create: `Classes/Domain/Model/FrontendCredential.php`
- Create: `Tests/Unit/Domain/Model/FrontendCredentialTest.php`

- [ ] **Step 1: Write the failing test**

Test `fromArray()`, `toArray()`, all getters/setters. Test that `site_identifier` and `storage_pid` are included (FE-specific fields). Test label trimming (max 128 chars). Mirror test pattern from `/home/cybot/projects/t3x-nr-passkeys-be/main/Tests/Unit/Domain/Model/CredentialTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer ci:test:php:unit -- --filter FrontendCredentialTest`
Expected: FAIL

- [ ] **Step 3: Write FrontendCredential model**

Plain PHP class with properties matching `tx_nrpasskeysfe_credential` columns. Include `fromArray(array $data): self` and `toArray(): array`. All properties with typed getters/setters. Label setter trims to 128 chars. Include `site_identifier` and `storage_pid` fields.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer ci:test:php:unit -- --filter FrontendCredentialTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Classes/Domain/Model/FrontendCredential.php Tests/Unit/Domain/Model/FrontendCredentialTest.php
git commit -S --signoff -m "feat: add FrontendCredential model"
```

---

### Task 2.3: RecoveryCode Model

**Files:**
- Create: `Classes/Domain/Model/RecoveryCode.php`
- Create: `Tests/Unit/Domain/Model/RecoveryCodeTest.php`

- [ ] **Step 1: Write the failing test**

Test construction, `isUsed()`, `markUsed()`, `fromArray()`, `toArray()`.

- [ ] **Step 2: Run, verify FAIL, implement, verify PASS**

- [ ] **Step 3: Commit**

```bash
git add Classes/Domain/Model/RecoveryCode.php Tests/Unit/Domain/Model/RecoveryCodeTest.php
git commit -S --signoff -m "feat: add RecoveryCode model"
```

---

### Task 2.4: FrontendEnforcementStatus DTO

**Files:**
- Create: `Classes/Domain/Dto/FrontendEnforcementStatus.php`
- Create: `Tests/Unit/Domain/Dto/FrontendEnforcementStatusTest.php`

- [ ] **Step 1: Write the failing test**

Test construction with all fields, immutability (readonly class), edge cases (null graceDeadline, zero counts).

- [ ] **Step 2: Write implementation**

```php
<?php
declare(strict_types=1);
namespace Netresearch\NrPasskeysFe\Domain\Dto;

use DateTimeImmutable;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;

final readonly class FrontendEnforcementStatus
{
    public function __construct(
        public EnforcementLevel $effectiveLevel,
        public EnforcementLevel $siteLevel,
        public EnforcementLevel $groupLevel,
        public int $passkeyCount,
        public bool $inGracePeriod,
        public ?DateTimeImmutable $graceDeadline,
        public int $recoveryCodesRemaining,
    ) {}
}
```

Note: Reuses `EnforcementLevel` from nr-passkeys-be (imported dependency).

- [ ] **Step 3: Run test, verify PASS, commit**

---

### Task 2.5: FrontendAdoptionStats DTO

**Files:**
- Create: `Classes/Domain/Dto/FrontendAdoptionStats.php`
- Create: `Tests/Unit/Domain/Dto/FrontendAdoptionStatsTest.php`

- [ ] **Step 1: Write test and implementation**

Readonly DTO with: totalUsers, usersWithPasskeys, adoptionPercentage, perGroupStats (array).

- [ ] **Step 2: Commit**

---

### Task 2.6: PSR-14 Events (all 8)

**Files:**
- Create: `Classes/Event/BeforePasskeyEnrollmentEvent.php`
- Create: `Classes/Event/AfterPasskeyEnrollmentEvent.php`
- Create: `Classes/Event/BeforePasskeyAuthenticationEvent.php`
- Create: `Classes/Event/AfterPasskeyAuthenticationEvent.php`
- Create: `Classes/Event/PasskeyRemovedEvent.php`
- Create: `Classes/Event/RecoveryCodesGeneratedEvent.php`
- Create: `Classes/Event/MagicLinkRequestedEvent.php`
- Create: `Classes/Event/EnforcementLevelResolvedEvent.php`
- Create: `Tests/Unit/Event/EventsTest.php`

- [ ] **Step 1: Write failing tests for all events**

Test construction, getter access, and mutability where applicable (`EnforcementLevelResolvedEvent` has `setEffectiveLevel()`). All other events are immutable. `MagicLinkRequestedEvent` has feUserUid and email but NO token.

- [ ] **Step 2: Implement all 8 event classes**

Each is a simple final (readonly where immutable) class with constructor + getters. Follow patterns from nr-passkeys-be events.

- [ ] **Step 3: Run tests, verify PASS, commit**

```bash
git add Classes/Event/ Tests/Unit/Event/
git commit -S --signoff -m "feat: add PSR-14 events for enrollment, auth, recovery, enforcement"
```

---

### Task 2.7: FrontendConfiguration Value Object

**Files:**
- Create: `Classes/Configuration/FrontendConfiguration.php`
- Create: `Tests/Unit/Configuration/FrontendConfigurationTest.php`

- [ ] **Step 1: Write test and implementation**

Value object reading extension settings: `enableFePasskeys`, `defaultEnforcementLevel`, `maxPasskeysPerUser`, `recoveryCodesEnabled`, `recoveryCodeCount`, `magicLinkEnabled`, `enrollmentBannerEnabled`, `postLoginEnrollmentEnabled`. Mirror pattern from `ExtensionConfigurationService` in nr-passkeys-be.

- [ ] **Step 2: Commit**

---

## Chunk 3: Core Services + Unit Tests

### Task 3.0: Configuration/Services.yaml (moved early — DI needed before services)

**Files:**
- Create: `Configuration/Services.yaml`

- [ ] **Step 1: Write Services.yaml**

Mirror pattern from nr-passkeys-be. Include:
- Autowiring for `Netresearch\NrPasskeysFe\` excluding Domain/Model
- Cache service definition for `nr_passkeys_fe_nonce` (only needed if FE has own challenge service)
- Public services: all controllers, all services
- Event listener tags for felogin events
- FormEngine element public registration

- [ ] **Step 2: Commit**

```bash
git add Configuration/Services.yaml
git commit -S --signoff -m "chore: add Services.yaml for dependency injection"
```

---

### Task 3.1: SiteConfigurationService

**Files:**
- Create: `Classes/Service/SiteConfigurationService.php`
- Create: `Tests/Unit/Service/SiteConfigurationServiceTest.php`

- [ ] **Step 1: Write failing tests**

Test cases:
- `getRpId()` returns domain from site base URL when no override
- `getRpId()` returns override from site settings
- `getOrigin()` returns full base URL including non-standard port
- `getOrigin()` omits port for standard 80/443
- `getEnforcementLevel()` returns Off by default
- `getEnforcementLevel()` reads from site settings
- `getSiteIdentifier()` returns site identifier
- `getCurrentSite()` extracts site from request attribute

Mock `SiteInterface` with `getBase()` returning a `Uri` and `getSettings()` returning site settings.

- [ ] **Step 2: Implement SiteConfigurationService**

```php
<?php
declare(strict_types=1);
namespace Netresearch\NrPasskeysFe\Service;

use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

final class SiteConfigurationService
{
    public function getRpId(SiteInterface $site): string
    {
        $settings = $site->getSettings();
        $override = $settings->get('nr_passkeys_fe.rpId', '');
        if ($override !== '') {
            return $override;
        }
        return $site->getBase()->getHost();
    }

    public function getOrigin(SiteInterface $site): string
    {
        $settings = $site->getSettings();
        $override = $settings->get('nr_passkeys_fe.origin', '');
        if ($override !== '') {
            return $override;
        }
        $base = $site->getBase();
        $origin = $base->getScheme() . '://' . $base->getHost();
        $port = $base->getPort();
        if ($port !== null && $port !== 443 && $port !== 80) {
            $origin .= ':' . $port;
        }
        return $origin;
    }

    public function getCurrentSite(ServerRequestInterface $request): SiteInterface
    {
        return $request->getAttribute('site');
    }

    public function getEnforcementLevel(SiteInterface $site): EnforcementLevel
    {
        $settings = $site->getSettings();
        $value = $settings->get('nr_passkeys_fe.enforcement', 'off');
        return EnforcementLevel::tryFrom($value) ?? EnforcementLevel::Off;
    }

    public function getSiteIdentifier(SiteInterface $site): string
    {
        return $site->getIdentifier();
    }
}
```

- [ ] **Step 3: Run tests, verify PASS, commit**

---

### Task 3.2: FrontendCredentialRepository

**Files:**
- Create: `Classes/Service/FrontendCredentialRepository.php`
- Create: `Tests/Unit/Service/FrontendCredentialRepositoryTest.php`

- [ ] **Step 1: Write failing unit tests**

Mock `ConnectionPool` and `QueryBuilder`. Test:
- `findByCredentialId()` builds correct query (no storage PID filter)
- `findByCredentialIdScoped()` includes storage PID and site_identifier in WHERE
- `findByFeUser()` filters by fe_user and site_identifier, excludes revoked (revoked_at = 0)
- `countByFeUser()` returns count
- `save()` calls insert with correct data
- `updateLastUsed()` calls update
- `revoke()` sets revoked_at and revoked_by
- `revokeAllByFeUser()` updates all for user

- [ ] **Step 2: Implement FrontendCredentialRepository**

Use TYPO3 `ConnectionPool` and `QueryBuilder`. Mirror pattern from `/home/cybot/projects/t3x-nr-passkeys-be/main/Classes/Service/CredentialRepository.php`. Add site_identifier and storage_pid to queries.

- [ ] **Step 3: Run tests, verify PASS, commit**

---

### Task 3.2b: FrontendWebAuthnService

**CRITICAL: The BE `WebAuthnService` is hardcoded to `be_users` and `CredentialRepository` (BE). It cannot be reused for FE authentication. This service wraps `web-auth/webauthn-lib` directly with FE-specific parameters.**

**Files:**
- Create: `Classes/Service/FrontendWebAuthnService.php`
- Create: `Tests/Unit/Service/FrontendWebAuthnServiceTest.php`

- [ ] **Step 1: Write failing tests**

Test:
- `createRegistrationOptions()` generates PublicKeyCredentialCreationOptions with correct RP ID/name from `SiteConfigurationService`
- `createRegistrationOptions()` excludes existing credentials for the fe_user
- `verifyRegistrationResponse()` returns credential data on valid attestation
- `createAssertionOptions()` generates PublicKeyCredentialRequestOptions with correct RP ID
- `createDiscoverableAssertionOptions()` generates options without allowCredentials
- `verifyAssertionResponse()` validates signature, checks sign count
- `findFeUserUidFromAssertion()` resolves credential ID → fe_user UID via `FrontendCredentialRepository`
- All methods use RP ID/origin from `SiteConfigurationService` (not hardcoded)

- [ ] **Step 2: Implement FrontendWebAuthnService**

Mirror the structure of `/home/cybot/projects/t3x-nr-passkeys-be/main/Classes/Service/WebAuthnService.php` but:
- Replace all `CredentialRepository` (BE) references with `FrontendCredentialRepository`
- Replace `beUserUid` with `feUserUid`
- Get RP ID and origin from `SiteConfigurationService` (per-site, not global config)
- Use `web-auth/webauthn-lib` classes directly: `PublicKeyCredentialCreationOptions`, `AuthenticatorAssertionResponse`, etc.
- Use `ChallengeService` from nr-passkeys-be for challenge token generation (this IS reusable since it's not user-type-specific)

Dependencies: `SiteConfigurationService`, `FrontendCredentialRepository`, `ChallengeService` (from nr-passkeys-be), `FrontendConfiguration`.

This is ~400-500 lines. Key ceremony methods:
```php
public function createRegistrationOptions(int $feUserUid, string $username, SiteInterface $site): array
public function verifyRegistrationResponse(string $attestationJson, string $challengeToken, int $feUserUid, SiteInterface $site): FrontendCredential
public function createAssertionOptions(?int $feUserUid, SiteInterface $site): array
public function createDiscoverableAssertionOptions(SiteInterface $site): array
public function verifyAssertionResponse(string $assertionJson, string $challengeToken, SiteInterface $site): VerifiedAssertion
public function findFeUserUidFromAssertion(string $assertionJson): ?int
```

- [ ] **Step 3: Run tests, verify PASS, commit**

```bash
git add Classes/Service/FrontendWebAuthnService.php Tests/Unit/Service/FrontendWebAuthnServiceTest.php
git commit -S --signoff -m "feat: add FrontendWebAuthnService wrapping webauthn-lib for FE ceremonies"
```

---

### Task 3.3: RecoveryCodeService

**Files:**
- Create: `Classes/Service/RecoveryCodeService.php`
- Create: `Tests/Unit/Service/RecoveryCodeServiceTest.php`

- [ ] **Step 1: Write failing tests**

Test:
- `generate()` returns array of plaintext codes in XXXX-XXXX format
- `generate()` stores bcrypt hashes (verify with `password_verify`)
- `generate()` deletes existing codes for user before inserting
- `generate()` respects count parameter
- `verify()` returns true for valid code, false for invalid
- `verify()` marks code as used (sets used_at)
- `verify()` returns false for already-used code
- `countRemaining()` returns count of unused codes

- [ ] **Step 2: Implement RecoveryCodeService**

Key implementation details:
- Alphabet: `23456789ABCDEFGHJKMNPQRSTUVWXYZ` (30 chars, excludes ambiguous 0/O, 1/I/L)
- Code format: 8 random chars from alphabet, displayed as XXXX-XXXX
- Generation: `random_int(0, 29)` per character position
- Hash: `password_hash($code, PASSWORD_BCRYPT, ['cost' => 12])`
- Verify: iterate all unused codes for user, `password_verify()` each (bcrypt salts differ)
- Delete old codes: `DELETE FROM tx_nrpasskeysfe_recovery_code WHERE fe_user = ?` before inserting new set

- [ ] **Step 3: Run tests, verify PASS, commit**

---

### Task 3.4: FrontendEnforcementService

**Files:**
- Create: `Classes/Service/FrontendEnforcementService.php`
- Create: `Tests/Unit/Service/FrontendEnforcementServiceTest.php`

- [ ] **Step 1: Write failing tests**

Test:
- `getStatus()` with no groups → site enforcement only
- `getStatus()` with one group at Encourage, site Off → Encourage
- `getStatus()` with multiple groups, strictest wins
- `getStatus()` with site Required, group Off → Required
- `getStatus()` correct grace period calculation
- `getStatus()` includes passkey count and recovery codes remaining
- `startGracePeriod()` sets fe_users.passkey_grace_period_start
- Grace period expired: `inGracePeriod = false`, `graceDeadline` in past
- `EnforcementLevelResolvedEvent` dispatched and can override level

- [ ] **Step 2: Implement FrontendEnforcementService**

Dependencies: `SiteConfigurationService`, `FrontendCredentialRepository`, `RecoveryCodeService`, `EventDispatcherInterface`, `ConnectionPool`.

Resolution: `max(siteLevel.severity(), strictestGroupLevel.severity())`. Query `fe_groups` for user's groups and their enforcement levels.

- [ ] **Step 3: Run tests, verify PASS, commit**

---

### Task 3.5: PasskeyEnrollmentService

**Files:**
- Create: `Classes/Service/PasskeyEnrollmentService.php`
- Create: `Tests/Unit/Service/PasskeyEnrollmentServiceTest.php`

- [ ] **Step 1: Write failing tests**

Test:
- `startEnrollment()` calls FrontendWebAuthnService.createRegistrationOptions()
- `startEnrollment()` returns options with challenge token
- `completeEnrollment()` verifies attestation via FrontendWebAuthnService
- `completeEnrollment()` stores credential via FrontendCredentialRepository
- `completeEnrollment()` fires BeforePasskeyEnrollmentEvent and AfterPasskeyEnrollmentEvent
- `completeEnrollment()` rejects if maxPasskeysPerUser reached
- Enrollment respects site_identifier and storage_pid

- [ ] **Step 2: Implement PasskeyEnrollmentService**

Dependencies: `FrontendWebAuthnService`, `ChallengeService` (from nr-passkeys-be), `FrontendCredentialRepository`, `FrontendConfiguration`, `EventDispatcherInterface`.

- [ ] **Step 3: Run tests, verify PASS, commit**

---

### Task 3.6: FrontendAdoptionStatsService

**Files:**
- Create: `Classes/Service/FrontendAdoptionStatsService.php`
- Create: `Tests/Unit/Service/FrontendAdoptionStatsServiceTest.php`

- [ ] **Step 1: Write test and implementation**

Queries fe_users and tx_nrpasskeysfe_credential tables. Returns `FrontendAdoptionStats` DTO.

- [ ] **Step 2: Commit**

---

## Chunk 4: Authentication Service + Unit Tests

### Task 4.1: PasskeyFrontendAuthenticationService

**Files:**
- Create: `Classes/Authentication/PasskeyFrontendAuthenticationService.php`
- Create: `Tests/Unit/Authentication/PasskeyFrontendAuthenticationServiceTest.php`

- [ ] **Step 1: Write failing tests**

This is the most critical class. Test all auth paths:

1. `getUser()` with no passkey payload → return false (continue chain)
2. `getUser()` with passkey payload + username → find user by username
3. `getUser()` with passkey payload + no username (discoverable) → resolve from credential ID
4. `getUser()` with passkey payload + locked out user → return false
5. `authUser()` with valid passkey assertion → return 200
6. `authUser()` with invalid assertion → return 0, record failure
7. `authUser()` with non-passkey login + Enforced + user has passkey → return 0 (block password)
8. `authUser()` with non-passkey login + no enforcement → return 100 (continue)
9. Session tagging for enrollment interstitial after password login
10. Rate limit checking on login attempt

- [ ] **Step 2: Implement PasskeyFrontendAuthenticationService**

```php
<?php
declare(strict_types=1);
namespace Netresearch\NrPasskeysFe\Authentication;

use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
// ... imports

final class PasskeyFrontendAuthenticationService extends AbstractAuthenticationService
{
    // Uses GeneralUtility::makeInstance() for deps (TYPO3 auth service limitation)

    public function getUser(): array|false
    {
        // 1. Check for passkey payload in loginData['uident']
        // 2. If no passkey payload, return false (pass to next service)
        // 3. If discoverable: findByCredentialId() → resolve fe_user UID
        // 4. If username-first: load user by username
        // 5. Check lockout via RateLimiterService
        // 6. Return user record array
    }

    public function authUser(array $user): int
    {
        // 1. If passkey payload: verify assertion via WebAuthnService → 200 or 0
        // 2. If no passkey payload: check enforcement
        //    - Enforced + user has passkeys → 0 (block password)
        //    - Otherwise → 100 (continue chain)
        // 3. Tag session for enrollment interstitial
    }
}
```

Key: Use subtype `'authUserFE,getUserFE'` in ext_localconf registration.

- [ ] **Step 3: Run tests, verify PASS, commit**

---

### Task 4.2: ext_localconf.php

**Files:**
- Create: `ext_localconf.php`

- [ ] **Step 1: Write ext_localconf.php**

```php
<?php
declare(strict_types=1);

use Netresearch\NrPasskeysFe\Authentication\PasskeyFrontendAuthenticationService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Register FE passkey authentication service at priority 80
ExtensionManagementUtility::addService(
    'nr_passkeys_fe',
    'auth',
    PasskeyFrontendAuthenticationService::class,
    [
        'title' => 'Passkey Frontend Authentication',
        'description' => 'Authenticates frontend users via WebAuthn/Passkey assertions',
        'subtype' => 'authUserFE,getUserFE',
        'available' => true,
        'priority' => 80,
        'quality' => 80,
        'os' => '',
        'exec' => '',
        'className' => PasskeyFrontendAuthenticationService::class,
    ]
);

// Security audit logging
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Netresearch']['NrPasskeysFe']['writerConfiguration'][\TYPO3\CMS\Core\Log\LogLevel::WARNING] ??= [
    \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
        'logFile' => 'typo3temp/var/log/passkey_fe_auth.log',
    ],
];

// Register cache for FE challenge nonces
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_fe_nonce'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_fe_nonce']['backend'] ??=
    \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_passkeys_fe_nonce']['options'] ??= [
    'defaultLifetime' => 300,
];

// Register custom FormEngine element for passkey info display in fe_users records
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1742000000] = [
    'nodeName' => 'passkeyFeInfo',
    'priority' => 40,
    'class' => \Netresearch\NrPasskeysFe\Form\Element\PasskeyFeInfoElement::class,
];

// Register eID for passkey FE API
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['nr_passkeys_fe'] =
    \Netresearch\NrPasskeysFe\Controller\EidDispatcher::class . '::processRequest';
```

- [ ] **Step 2: Commit**

---

## Chunk 5: Controllers + eID Dispatcher

### Task 5.1: EidDispatcher

**Files:**
- Create: `Classes/Controller/EidDispatcher.php`
- Create: `Tests/Unit/Controller/EidDispatcherTest.php`

- [ ] **Step 1: Write test and implementation**

The EidDispatcher routes `?eID=nr_passkeys_fe&action=<action>` to the correct controller method. Actions: `loginOptions`, `loginVerify`, `registrationOptions`, `registrationVerify`, `manageList`, `manageRename`, `manageRemove`, `recoveryCodes`, `recoveryVerify`, `enrollmentStatus`, `enrollmentSkip`.

Returns JSON responses. Handles errors with proper HTTP status codes.

- [ ] **Step 2: Commit**

---

### Task 5.2: LoginController

**Files:**
- Create: `Classes/Controller/LoginController.php`
- Create: `Tests/Unit/Controller/LoginControllerTest.php`

- [ ] **Step 1: Write failing tests**

Test:
- `optionsAction()` generates challenge via WebAuthnService, returns JSON
- `optionsAction()` with username: scoped assertion options
- `optionsAction()` without username: discoverable assertion options
- `optionsAction()` rate limited: returns 429
- `verifyAction()` with valid assertion: returns success + sets login cookie
- `verifyAction()` with invalid assertion: returns 401
- `verifyAction()` records attempt via RateLimiterService
- User enumeration prevention: timing normalization on optionsAction

- [ ] **Step 2: Implement LoginController**

Uses: `FrontendWebAuthnService`, `ChallengeService` (from nr-passkeys-be), `RateLimiterService` (from nr-passkeys-be), `FrontendCredentialRepository`, `SiteConfigurationService`.

- [ ] **Step 3: Run tests, verify PASS, commit**

---

### Task 5.3: ManagementController

**Files:**
- Create: `Classes/Controller/ManagementController.php`
- Create: `Tests/Unit/Controller/ManagementControllerTest.php`

- [ ] **Step 1: Write tests and implementation**

Authenticated endpoints. Test:
- `registrationOptionsAction()` generates registration challenge
- `registrationVerifyAction()` completes enrollment via PasskeyEnrollmentService
- `listAction()` returns user's credentials
- `renameAction()` renames credential (validates label)
- `removeAction()` revokes credential
- All actions require authenticated FE user (401 otherwise)
- CSRF protection via TYPO3 FormProtection

- [ ] **Step 2: Commit**

---

### Task 5.4: RecoveryController

**Files:**
- Create: `Classes/Controller/RecoveryController.php`
- Create: `Tests/Unit/Controller/RecoveryControllerTest.php`

- [ ] **Step 1: Write tests and implementation**

Test:
- `generateAction()` creates recovery codes via RecoveryCodeService
- `generateAction()` requires authenticated FE user
- `verifyAction()` verifies recovery code for login (unauthenticated, like login)
- `verifyAction()` rate limited
- CSRF on generateAction (authenticated), challenge binding on verifyAction

- [ ] **Step 2: Commit**

---

### Task 5.5: EnrollmentController

**Files:**
- Create: `Classes/Controller/EnrollmentController.php`
- Create: `Tests/Unit/Controller/EnrollmentControllerTest.php`

- [ ] **Step 1: Write tests and implementation**

Test:
- `statusAction()` returns FrontendEnforcementStatus JSON
- `skipAction()` sets session flag, returns success
- `skipAction()` validates CSRF nonce
- `skipAction()` only works for Encourage/Required (not Enforced)

- [ ] **Step 2: Commit**

---

## Chunk 6: Middleware, Event Listeners, Configuration

(Services.yaml was moved to Task 3.0 — DI must be available before services are created.)

### Task 6.2: PasskeyPublicRouteResolver Middleware

**Files:**
- Create: `Classes/Middleware/PasskeyPublicRouteResolver.php`
- Create: `Tests/Unit/Middleware/PasskeyPublicRouteResolverTest.php`
- Create: `Configuration/RequestMiddlewares.php`

- [ ] **Step 1: Write failing tests**

Test:
- eID requests with `nr_passkeys_fe` + public actions (loginOptions, loginVerify) are passed through
- Non-eID requests are passed through unchanged
- Authenticated-only actions are NOT marked as public

- [ ] **Step 2: Implement middleware**

Checks for eID parameter, marks public actions by setting a request attribute that bypasses authentication.

- [ ] **Step 3: Write RequestMiddlewares.php**

Register both middleware in the frontend chain:
- `nr-passkeys-fe/public-route-resolver`: after `typo3/cms-frontend/site`, before `typo3/cms-frontend/authentication`
- `nr-passkeys-fe/enrollment-interstitial`: after `typo3/cms-frontend/authentication`

- [ ] **Step 4: Commit**

---

### Task 6.3: PasskeyEnrollmentInterstitial Middleware

**Files:**
- Create: `Classes/Middleware/PasskeyEnrollmentInterstitial.php`
- Create: `Tests/Unit/Middleware/PasskeyEnrollmentInterstitialTest.php`

- [ ] **Step 1: Write failing tests**

Test:
- Unauthenticated user: pass through
- User with passkeys: pass through
- User without passkeys + Off: pass through
- User without passkeys + Encourage: pass through (banner handles it)
- User without passkeys + Required + in grace period: redirect with skip option
- User without passkeys + Required + grace expired: redirect, no skip
- User without passkeys + Enforced: redirect, no skip
- eID requests: exempt (pass through)
- Enrollment page request: exempt (no redirect loop)
- Session skip flag set: pass through

- [ ] **Step 2: Implement middleware**

Check `$GLOBALS['TSFE']->fe_user->user` for authentication. Use `FrontendEnforcementService.getStatus()`. Redirect to enrollment page (from TypoScript settings).

- [ ] **Step 3: Commit**

---

### Task 6.4: InjectPasskeyLoginFields Event Listener

**Files:**
- Create: `Classes/EventListener/InjectPasskeyLoginFields.php`
- Create: `Tests/Unit/EventListener/InjectPasskeyLoginFieldsTest.php`

- [ ] **Step 1: Write tests and implementation**

Listens to `ModifyLoginFormViewEvent` from `ext:felogin`. Injects:
- Hidden field with passkey config (rpId, origin, loginOptionsUrl)
- Passkey login button HTML
- JavaScript module include via `AssetCollector`

Depends on: felogin being installed (check class_exists before registering).

- [ ] **Step 2: Commit**

---

### Task 6.5: InjectPasskeyBanner Event Listener

**Files:**
- Create: `Classes/EventListener/InjectPasskeyBanner.php`
- Create: `Tests/Unit/EventListener/InjectPasskeyBannerTest.php`

- [ ] **Step 1: Write tests and implementation**

Listens to `AfterCacheableContentIsGeneratedEvent` or similar FE event. For authenticated users without passkeys where enforcement >= Encourage, injects a banner HTML snippet. Dismissible for Encourage, non-dismissible for Enforced.

- [ ] **Step 2: Commit**

---

### Task 6.5b: PasskeyFeInfoElement FormEngine

**Files:**
- Create: `Classes/Form/Element/PasskeyFeInfoElement.php`
- Create: `Tests/Unit/Form/Element/PasskeyFeInfoElementTest.php`

- [ ] **Step 1: Write failing test**

Test that the element renders a read-only list of passkey credentials for the given fe_user. Mock `FrontendCredentialRepository` to return test credentials.

- [ ] **Step 2: Implement PasskeyFeInfoElement**

Mirror `/home/cybot/projects/t3x-nr-passkeys-be/main/Classes/Form/Element/PasskeyInfoElement.php`. Extend `AbstractFormElement`. Display credentials table (label, created, last used, aaguid).

- [ ] **Step 3: Run test, verify PASS, commit**

```bash
git add Classes/Form/Element/ Tests/Unit/Form/Element/
git commit -S --signoff -m "feat: add PasskeyFeInfoElement for fe_users TCA display"
```

---

### Task 6.6: TCA Configuration

**Files:**
- Create: `Configuration/TCA/tx_nrpasskeysfe_credential.php`
- Create: `Configuration/TCA/tx_nrpasskeysfe_recovery_code.php`
- Create: `Configuration/TCA/Overrides/fe_groups.php`
- Create: `Configuration/TCA/Overrides/fe_users.php`
- Create: `Configuration/TCA/Overrides/tt_content.php`

- [ ] **Step 1: Write TCA files**

- `tx_nrpasskeysfe_credential.php`: Read-only display in backend (admin module handles CRUD)
- `tx_nrpasskeysfe_recovery_code.php`: Hidden from backend (internal table)
- `fe_groups.php`: Add "Passkey Enforcement" tab with select (Off/Encourage/Required/Enforced) and grace period days input
- `fe_users.php`: Add "Passkeys" tab with custom FormEngine element showing registered passkeys
- `tt_content.php`: Register Extbase plugins (PasskeyLogin, PasskeyManagement, PasskeyEnrollment)

Mirror TCA patterns from nr-passkeys-be's `Configuration/TCA/`.

- [ ] **Step 2: Commit**

---

## Chunk 7: Plugins, Templates, JavaScript

### Task 7.1: Extbase Plugin Registration

**Files:**
- Create: `Configuration/TypoScript/setup.typoscript`
- Create: `Configuration/TypoScript/constants.typoscript`
- Create: `Configuration/FlexForms/LoginPlugin.xml`
- Create: `Configuration/FlexForms/ManagementPlugin.xml`
- Create: `Configuration/FlexForms/EnrollmentPlugin.xml`

- [ ] **Step 1: Write TypoScript setup and constants**

Register three Extbase plugins. TypoScript constants for loginPage, managementPage, enrollmentPage UIDs.

- [ ] **Step 2: Write FlexForms**

LoginPlugin: discoverable login toggle, password fallback link toggle, redirect page.
ManagementPlugin: (minimal settings).
EnrollmentPlugin: (minimal settings).

- [ ] **Step 3: Commit**

---

### Task 7.2: Fluid Templates

**Files:**
- Create: `Resources/Private/Templates/Login/Index.html`
- Create: `Resources/Private/Templates/Login/Recovery.html`
- Create: `Resources/Private/Templates/Management/Index.html`
- Create: `Resources/Private/Templates/Management/Enrollment.html`
- Create: `Resources/Private/Templates/Management/RecoveryCodes.html`
- Create: `Resources/Private/Templates/Enrollment/Index.html`
- Create: `Resources/Private/Templates/Enrollment/Success.html`
- Create: `Resources/Private/Partials/Login/PasskeyButton.html`
- Create: `Resources/Private/Layouts/Default.html`

- [ ] **Step 1: Write templates**

All templates use `data-*` attributes for JavaScript configuration (CSP-friendly). Include BEM-style CSS classes. Progressive enhancement: forms work without JS.

Login/Index.html: Passkey button + optional username field + password fallback link + recovery link.
Management/Index.html: Credential list + register new + recovery codes section.
Enrollment/Index.html: Enrollment prompt with "Register Passkey" button + skip option (if allowed).

- [ ] **Step 2: Commit**

---

### Task 7.3: Frontend JavaScript Modules

**Files:**
- Create: `Resources/Public/JavaScript/PasskeyLogin.js`
- Create: `Resources/Public/JavaScript/PasskeyEnrollment.js`
- Create: `Resources/Public/JavaScript/PasskeyManagement.js`
- Create: `Resources/Public/JavaScript/PasskeyRecovery.js`
- Create: `Resources/Public/JavaScript/PasskeyBanner.js`
- Create: `Resources/Public/Css/passkey-fe.css`

- [ ] **Step 1: Write PasskeyLogin.js**

Vanilla ES6 module. Feature detection → `navigator.credentials.get()` → base64url encoding → fetch API → handle response. Support both discoverable and username-first flows. Check `PublicKeyCredential.isConditionalMediationAvailable()`.

Mirror patterns from `/home/cybot/projects/t3x-nr-passkeys-be/main/Resources/Public/JavaScript/PasskeyLogin.js` but adapt for frontend context (no TYPO3 backend imports).

- [ ] **Step 2: Write remaining JS modules**

PasskeyEnrollment.js: `navigator.credentials.create()` flow.
PasskeyManagement.js: CRUD operations via fetch API.
PasskeyRecovery.js: Recovery code input form.
PasskeyBanner.js: Show/dismiss banner, cookie tracking.

- [ ] **Step 3: Write CSS**

Minimal default styles with BEM classes. Easily overridable by integrators.

- [ ] **Step 4: Commit**

---

### Task 7.4: Language Files

**Files:**
- Create: `Resources/Private/Language/locallang.xlf`
- Create: `Resources/Private/Language/locallang_db.xlf`

- [ ] **Step 1: Write XLIFF translations**

`locallang.xlf`: Plugin labels, button text, error messages, enrollment prompts, recovery text.
`locallang_db.xlf`: TCA field labels, tab labels, FlexForm labels.

- [ ] **Step 2: Commit**

---

## Chunk 8: Backend Admin Module

### Task 8.1: Admin Module Registration

**Files:**
- Create: `Configuration/Backend/Modules.php`
- Create: `Configuration/Backend/AjaxRoutes.php`
- Create: `Configuration/Icons.php`
- Create: `Resources/Public/Icons/Extension.svg`

- [ ] **Step 1: Write Modules.php**

Register backend module. Mirror pattern from nr-passkeys-be's `Configuration/Backend/Modules.php`. Place under "Web" module group.

- [ ] **Step 2: Write AjaxRoutes.php**

Admin AJAX routes: list FE user passkeys, revoke, revoke all, unlock lockout, update enforcement.

- [ ] **Step 3: Commit**

---

### Task 8.2: AdminModuleController

**Files:**
- Create: `Classes/Controller/AdminModuleController.php`
- Create: `Tests/Unit/Controller/AdminModuleControllerTest.php`

- [ ] **Step 1: Write tests and implementation**

Renders dashboard view (adoption stats), user management view, enforcement view. Uses Fluid templates in `Resources/Private/Templates/AdminModule/`.

- [ ] **Step 2: Commit**

---

### Task 8.3: AdminController (AJAX)

**Files:**
- Create: `Classes/Controller/AdminController.php`
- Create: `Tests/Unit/Controller/AdminControllerTest.php`

- [ ] **Step 1: Write tests and implementation**

JSON endpoints: `listAction`, `removeAction`, `revokeAllAction`, `unlockAction`.
All require admin BE user. Sudo mode for write operations.

- [ ] **Step 2: Commit**

---

### Task 8.4: Admin Templates & JavaScript

**Files:**
- Create: `Resources/Private/Templates/AdminModule/Dashboard.html`
- Create: `Resources/Private/Templates/AdminModule/Help.html`
- Create: `Resources/Public/JavaScript/PasskeyFeAdmin.js`

- [ ] **Step 1: Write admin templates and JS**

Dashboard: Adoption stats display, user search, enforcement controls.
JS: TYPO3 backend module imports (`@typo3/backend/modal`, `@typo3/backend/notification`).

- [ ] **Step 2: Commit**

---

## Chunk 9: Functional Tests

### Task 9.1: FrontendCredentialRepository Functional Test

**Files:**
- Create: `Tests/Functional/Service/FrontendCredentialRepositoryTest.php`
- Create: `Tests/Functional/Fixtures/fe_users.csv`

- [ ] **Step 1: Write functional tests**

Test against real database (MySQL in CI, SQLite locally). Test:
- Insert credential, retrieve by ID
- Retrieve by fe_user + site_identifier
- Revoke credential
- Storage PID scoping works (credentials from different pools isolated)
- UNIQUE constraint on credential_id enforced

- [ ] **Step 2: Commit**

---

### Task 9.2: TCA Functional Tests

**Files:**
- Create: `Tests/Functional/Configuration/TcaTest.php`

- [ ] **Step 1: Test TCA loads correctly**

Verify fe_groups has passkey_enforcement field, fe_users has passkey fields, plugins are registered in tt_content.

- [ ] **Step 2: Commit**

---

### Task 9.3: Auth Service Registration Functional Test

**Files:**
- Create: `Tests/Functional/Authentication/AuthServiceRegistrationTest.php`

- [ ] **Step 1: Test auth service is registered**

Verify PasskeyFrontendAuthenticationService is in the service chain at priority 80 with subtype `authUserFE,getUserFE`.

- [ ] **Step 2: Commit**

---

## Chunk 10: Fuzz, Architecture & Mutation Tests

### Task 10.1: Fuzz Tests

**Files:**
- Create: `Tests/Fuzz/RecoveryCodeFuzzTest.php`
- Create: `Tests/Fuzz/CredentialIdFuzzTest.php`
- Create: `Tests/Fuzz/EnforcementFuzzTest.php`
- Create: `Tests/Fuzz/RequestPayloadFuzzTest.php`

- [ ] **Step 1: Write fuzz tests**

Use `giorgiosironi/eris` for property-based testing.

RecoveryCodeFuzzTest: Random inputs to `RecoveryCodeService.verify()` never cause exceptions (only return bool).
CredentialIdFuzzTest: Oversized, empty, and special character credential IDs handled gracefully.
EnforcementFuzzTest: All combinations of site+group enforcement produce valid EnforcementLevel.
RequestPayloadFuzzTest: Malformed JSON payloads to controllers return proper error responses, never crash.

- [ ] **Step 2: Run fuzz tests**

```bash
composer ci:test:php:fuzz
```

Expected: PASS (property-based tests may need multiple runs to be confident)

- [ ] **Step 3: Commit**

---

### Task 10.2: Architecture Tests (PHPat)

**Files:**
- Create: `Tests/Architecture/ArchitectureTest.php`

- [ ] **Step 1: Write architecture tests**

```php
<?php
declare(strict_types=1);
namespace Netresearch\NrPasskeysFe\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\PHPat;

final class ArchitectureTest
{
    public function testControllersOnlyDependOnServices(): PHPat
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrPasskeysFe\Controller'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('TYPO3\CMS\Core\Database\ConnectionPool'),
                Selector::classname('TYPO3\CMS\Core\Database\Query\QueryBuilder'),
            );
    }

    public function testDomainHasNoFrameworkDependencies(): PHPat
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrPasskeysFe\Domain'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('TYPO3\CMS\Core\Database'),
                Selector::inNamespace('TYPO3\CMS\Extbase'),
            );
    }

    public function testEventsAreIndependent(): PHPat
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrPasskeysFe\Event'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrPasskeysFe\Service'),
                Selector::inNamespace('Netresearch\NrPasskeysFe\Controller'),
            );
    }
}
```

- [ ] **Step 2: Run via PHPStan**

```bash
composer ci:test:php:phpstan
```

Expected: PASS (architecture rules enforced)

- [ ] **Step 3: Commit**

---

### Task 10.3: Verify Mutation Testing

- [ ] **Step 1: Run Infection**

```bash
composer ci:mutation
```

Expected: MSI >= 80%. If below, add tests for uncovered mutants.

- [ ] **Step 2: Iterate until MSI >= 80%**

---

## Chunk 11: JavaScript Tests

### Task 11.1: Vitest Setup

**Files:**
- Create: `package.json`
- Create: `vitest.config.js`
- Create: `Tests/JavaScript/PasskeyLogin.test.js`
- Create: `Tests/JavaScript/PasskeyEnrollment.test.js`
- Create: `Tests/JavaScript/PasskeyManagement.test.js`
- Create: `Tests/JavaScript/PasskeyBanner.test.js`

- [ ] **Step 1: Write package.json**

```json
{
    "name": "nr-passkeys-fe",
    "private": true,
    "scripts": {
        "test": "vitest run",
        "test:watch": "vitest"
    },
    "devDependencies": {
        "vitest": "^3.0",
        "jsdom": "^26.0"
    }
}
```

- [ ] **Step 2: Write Vitest tests**

Mock `navigator.credentials` API. Test:
- PasskeyLogin: `get()` called with correct options, base64url encoding, fetch calls
- PasskeyEnrollment: `create()` called, attestation encoded, success/error handling
- PasskeyManagement: List rendering, rename/remove API calls
- PasskeyBanner: Show/dismiss, cookie tracking

- [ ] **Step 3: Run tests**

```bash
npm install && npm test
```

Expected: PASS

- [ ] **Step 4: Commit**

---

## Chunk 12: E2E Tests

### Task 12.1: Playwright Setup

**Files:**
- Create: `Tests/E2E/playwright.config.ts`
- Create: `Tests/E2E/login.spec.ts`
- Create: `Tests/E2E/enrollment.spec.ts`
- Create: `Tests/E2E/management.spec.ts`
- Create: `Tests/E2E/recovery.spec.ts`
- Create: `Tests/E2E/admin.spec.ts`

- [ ] **Step 1: Write Playwright config**

Configure virtual WebAuthn authenticator via CDP (Chrome DevTools Protocol). Reference how nr-passkeys-be handles E2E with virtual authenticators.

- [ ] **Step 2: Write E2E tests**

login.spec.ts: Full passkey login flow, discoverable login, password fallback.
enrollment.spec.ts: Post-login enrollment prompt, passkey registration.
management.spec.ts: List, rename, remove passkeys.
recovery.spec.ts: Recovery code generation and use.
admin.spec.ts: Backend module: view users, revoke credentials.

- [ ] **Step 3: Commit**

---

## Chunk 13: Documentation

### Task 13.1: RST Documentation Structure

**Files:**
- Create: All files in `Documentation/` as listed in spec §Documentation Plan

- [ ] **Step 1: Write guides.xml**

Navigation structure for docs.typo3.org.

- [ ] **Step 2: Write core documentation files**

Index.rst, Introduction, Installation, Configuration, QuickStart, Usage (Login, Enrollment, Recovery, Management), Administration (Dashboard, Enforcement, UserManagement), DeveloperGuide (Events, ExtensionPoints, Api), Security, MultiSite, Troubleshooting, Changelog.

Each RST file follows TYPO3 documentation standards. Mirror quality from nr-passkeys-be's Documentation/.

- [ ] **Step 3: Write ADR index**

Create `Documentation/Adr/Index.rst` listing all 12 ADRs (001-012). ADR-012 (`Adr012AuthServicePriority80.rst`) already exists from brainstorming.

- [ ] **Step 4: Commit**

---

### Task 13.2: AGENTS.md Files

**Files:**
- Create: `AGENTS.md` (root)
- Create: `Classes/AGENTS.md`
- Create: `Tests/AGENTS.md`
- Create: `Configuration/AGENTS.md`
- Create: `Resources/AGENTS.md`
- Create: `Documentation/AGENTS.md`
- Create: `.github/workflows/AGENTS.md`

- [ ] **Step 1: Write scoped AGENTS.md files**

Mirror pattern from nr-passkeys-be. Include project overview, setup instructions, file map, code style, golden samples, heuristics.

- [ ] **Step 2: Commit**

---

### Task 13.3: README.md

**Files:**
- Create: `README.md`

- [ ] **Step 1: Write README**

Project overview, features, installation, quick start, link to full documentation.

- [ ] **Step 2: Commit**

---

## Chunk 14: Integration Tests

### Task 14.1: Full Login Flow Integration Test

**Files:**
- Create: `Tests/Integration/LoginFlowTest.php`

- [ ] **Step 1: Write integration test**

Full TYPO3 bootstrap. Test the complete flow:
1. Password login → session created
2. Enrollment prompt appears (middleware)
3. Register passkey (via service)
4. Logout
5. Passkey login → session created
6. Verify passkey is used (check credential last_used_at)

- [ ] **Step 2: Commit**

---

### Task 14.2: Recovery Flow Integration Test

**Files:**
- Create: `Tests/Integration/RecoveryFlowTest.php`

- [ ] **Step 1: Write integration test**

1. Create fe_user with passkey
2. Generate recovery codes
3. Simulate passkey failure
4. Login with recovery code
5. Verify recovery code consumed

- [ ] **Step 2: Commit**

---

### Task 14.3: Multi-Site Isolation Integration Test

**Files:**
- Create: `Tests/Integration/MultiSiteIsolationTest.php`

- [ ] **Step 1: Write integration test**

1. Create credentials on site A (storage PID 42)
2. Verify credentials NOT accessible from site B (storage PID 99)
3. Verify discoverable login resolves to correct site

- [ ] **Step 2: Commit**

---

### Task 14.4: Enforcement Escalation Integration Test

**Files:**
- Create: `Tests/Integration/EnforcementEscalationTest.php`

- [ ] **Step 1: Write integration test**

1. Set site enforcement Off, group Off → no prompt
2. Set group to Encourage → banner shown
3. Set group to Required → interstitial redirect
4. Verify grace period works
5. Set group to Enforced → password blocked

- [ ] **Step 2: Commit**

---

## Chunk 15: Final Polish

### Task 15.1: Run Full CI Locally

- [ ] **Step 1: Run all checks**

```bash
make ci
```

This runs: cgl, phpstan, unit tests, functional tests, fuzz tests.

- [ ] **Step 2: Run mutation testing**

```bash
make mutation
```

Target: MSI >= 80%

- [ ] **Step 3: Run JavaScript tests**

```bash
npm test
```

- [ ] **Step 4: Fix any issues**

---

### Task 15.2: Final Commit and Tag

- [ ] **Step 1: Verify git status is clean**

```bash
git status
```

- [ ] **Step 2: Create initial release tag**

```bash
git tag -s v0.1.0 -m "v0.1.0: Initial release - Passkey-first frontend authentication"
```

---

## Summary

| Chunk | Tasks | Focus |
|-------|-------|-------|
| 1 | 1.1-1.6 | Project scaffolding, composer, build tools, CI |
| 2 | 2.1-2.7 | Domain layer: models, DTOs, enums, events + unit tests |
| 3 | 3.0-3.6 | Services.yaml, FrontendWebAuthnService, core services + unit tests |
| 4 | 4.1-4.2 | Auth service + ext_localconf |
| 5 | 5.1-5.5 | Controllers + eID dispatcher |
| 6 | 6.2-6.6 | Middleware, event listeners, FormEngine, TCA |
| 7 | 7.1-7.4 | Plugins, templates, JavaScript, translations |
| 8 | 8.1-8.4 | Backend admin module |
| 9 | 9.1-9.3 | Functional tests |
| 10 | 10.1-10.3 | Fuzz, architecture, mutation tests |
| 11 | 11.1 | JavaScript tests (Vitest) |
| 12 | 12.1 | E2E tests (Playwright) |
| 13 | 13.1-13.3 | Documentation (RST, AGENTS.md, README) |
| 14 | 14.1-14.4 | Integration tests |
| 15 | 15.1-15.2 | Final polish, CI verification, tag |

**Dependencies:** Chunks 1→2→3→4→5→6 are sequential (each builds on previous). Chunks 7-8 can run in parallel after chunk 6. Chunks 9-14 can run in parallel after BOTH chunks 7 and 8 complete. Chunk 15 is last.

**Note on architecture tests:** PHPat tests (Task 10.2) run via `composer ci:test:php:phpstan`, not via PHPUnit. They are PHPStan rules, not PHPUnit test cases.
