<!-- FOR AI AGENTS - Scoped to Tests/ -->
<!-- Last updated: 2026-08-05 -->

# Tests/ AGENTS.md

**Scope:** Test suite for `nr_passkeys_fe`.

## Structure

```
Tests/
  Unit/                  -> PHPUnit unit tests (fast, no DB, no TYPO3 bootstrap)
  Functional/            -> PHPUnit functional tests (require MySQL, CI only)
  Integration/           -> Multi-service tests against a real database; part of
                            the 'functional' testsuite, not a separate one
  Fuzz/                  -> Property-based fuzz tests (eris/eris, PHPUnit testsuite)
  Architecture/          -> PHPat architecture constraint tests
  JavaScript/            -> Vitest JS unit tests
  E2E/                   -> Playwright end-to-end tests (targets DDEV)
  bootstrap.php          -> PHPUnit bootstrap (loads autoloader)
  Fixtures/              -> Test data fixtures (SQL, JSON)
  AbstractPasskeyFunctionalTestCase.php
                         -> Base class for Functional/ and Integration/ tests;
                            carries the extension lists they all load
```

## How to Run Tests

```bash
# Unit tests only (no database required)
composer ci:test:php:unit

# Fuzz tests (PHPUnit testsuite 'fuzz')
composer ci:test:php:fuzz

# Functional + integration tests (requires MySQL -- run in CI or DDEV)
composer ci:test:php:functional

# All PHP tests (unit + functional)
composer ci:test:php:all

# Static analysis
composer ci:test:php:phpstan

# Code style check
composer ci:test:php:cgl

# JavaScript unit tests (Vitest)
npx vitest run

# E2E tests (requires DDEV running)
npx playwright test

# Mutation testing (Infection, min-MSI 80%)
composer ci:mutation
```

## Test Conventions

### PHPUnit (Unit + Functional)
- Test class extends nothing (plain PHPUnit) or `UnitTestCase`
- Test method naming: `test<Subject>_<condition>_<expected>`
- One assertion per test where possible
- Use `dg/bypass-finals` for mocking final classes
- **Never** share a service/repository instance across tests
  (each test creates its own instance via `setUp()`)
- Auth service tests (and any test using cache-based token flow) must register a
  `CacheManager` stub in `setUp()` before instantiating the service
- `getUser()` and `authUser()` run on **separate** service instances -- tests must
  account for this (create two instances or test each method independently)
- Data providers: use `#[DataProvider]` attribute (PHPUnit 10+)

### Functional and Integration Tests
- Require the TYPO3 testing framework bootstrapped with MySQL
- Run only in CI -- do not assume local MySQL availability
- Use `DatabaseConnectionTrait` for database access
- Isolate each test with fixture loading / teardown
- Extend `AbstractPasskeyFunctionalTestCase`, which carries the extension lists;
  a test needing more appends to `$coreExtensionsToLoad` in its own `setUp()`
  before `parent::setUp()`. The lists belong in a base class, not a trait: a
  trait property whose default differs from `FunctionalTestCase`'s is a fatal
  error on PHP 8.2-8.4, which PHP 8.5 no longer raises -- so a trait passes
  locally on 8.5 and breaks three quarters of the CI matrix
- Double a **site** by stubbing the concrete `Site`, not `SiteInterface`:
  `getSettings()` is declared on `Site` only. `Tests/Integration/SiteStubTrait`
  provides that double
- SQLite is not a substitute for MySQL here: `credential_id` is `varbinary`, and
  SQLite compares a BLOB column against a string parameter as unequal, so the
  credential lookups fail there for reasons the code does not have

### Fuzz Tests
- Use `eris/eris` generator library
- Target boundary conditions: empty strings, large payloads, binary data
- Can be flaky for `random_bytes()` edge cases -- re-run on failure

### Architecture Tests (PHPat)
- Define layer boundaries: Controllers may not depend on Tests, etc.
- Located in `Tests/Architecture/`
- Run as part of the unit testsuite

### JavaScript Tests (Vitest)
- Located in `Tests/JavaScript/`
- Test WebAuthn flow stubs, DOM manipulation, error handling
- Mock `navigator.credentials` and `fetch` globally in setup
- Config: `vitest.config.js` at project root

### E2E Tests (Playwright)
- Located in `Tests/E2E/`
- Target: DDEV installation at `https://nr-passkeys-fe.ddev.site`
- Require a running DDEV environment: `make up`
- Use WebAuthn virtual authenticators (Playwright's built-in)
- Spec files: `*.spec.ts`

## What Needs Tests
| Code | Test type |
|------|-----------|
| Service business logic | Unit |
| DB repository queries | Functional |
| Auth service | Unit + Functional |
| Challenge generation/verification | Unit |
| Recovery code hashing/verification | Unit |
| Enforcement level resolution | Unit |
| eID controller routing | Unit (mocked) |
| Template rendering | Functional |
| Full login flow | E2E |
| WebAuthn JS modules | JS unit |

## Boundaries
- Do NOT add `sleep()` in tests
- Do NOT share state between tests via class properties
- Fuzz tests run in isolation: `phpunit -c Build/phpunit.xml --testsuite fuzz`
- Functional tests need `--testsuite functional` and a running MySQL
