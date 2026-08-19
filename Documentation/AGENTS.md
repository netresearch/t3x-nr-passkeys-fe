<!-- FOR AI AGENTS - Scoped to Documentation/ -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# Documentation/ AGENTS.md

## Overview

**Scope:** TYPO3 extension documentation following docs.typo3.org standards
(reStructuredText rendered by TYPO3 render-guides). Includes user guide,
developer guide, security docs, and 12 ADRs under `Adr/`.

## Setup

- Rendering needs Docker (directly or via DDEV — `ddev docs` / `make docs`).
- `guides.xml` is the render config; its version must match `ext_emconf.php`.

## Structure

```
Documentation/
  Index.rst                          -> Main entry point (toctree)
  guides.xml                         -> Render config (version must match ext_emconf.php)
  Includes.rst.txt                   -> Shared substitutions (|extension_key| etc.)
  Introduction/Index.rst             -> What it does, features, authenticators, browser support
  Installation/Index.rst             -> Composer install, activation, system requirements
  Configuration/
    Index.rst                        -> Config overview + toctree
    ExtensionSettings.rst            -> Extension settings (confvals)
    SiteConfiguration.rst            -> Per-site config.yaml passkeys.* keys
    TypoScript.rst                   -> TypoScript constants + setup reference
  QuickStart/Index.rst               -> 5-minute setup walkthrough
  Usage/
    Index.rst                        -> Usage overview + toctree
    Login.rst                        -> Login flows (discoverable + username-first)
    Enrollment.rst                   -> Passkey enrollment process
    Recovery.rst                     -> Recovery codes
    Management.rst                   -> Self-service management panel
  Administration/
    Index.rst                        -> Admin overview + toctree
    Dashboard.rst                    -> Backend module dashboard
    Enforcement.rst                  -> Enforcement levels, grace periods
    UserManagement.rst               -> Admin credential management
  DeveloperGuide/
    Index.rst                        -> Architecture overview + toctree
    Events.rst                       -> PSR-14 events reference (all 7 events)
    ExtensionPoints.rst              -> Templates, services, JS overrides
    Api.rst                          -> eID API reference (all endpoints)
  Security/
    Index.rst                        -> Security overview + toctree
    WebAuthnCompliance.rst           -> Ceremonies, challenges, credential storage
    ThreatModel.rst                  -> Threats and mitigations
  MultiSite/Index.rst                -> Multi-domain RP ID configuration
  Troubleshooting/Index.rst          -> Common issues and solutions
  Adr/
    Index.rst                        -> ADR index (toctree + summary table)
    Adr001-012.rst                   -> 12 Architecture Decision Records (do not edit)
  Changelog/Index.rst                -> Version history
  Images/                            -> Screenshots (PNG only, :alt: required)
```

## Style & standards

- **Format**: reStructuredText (.rst)
- **Encoding**: UTF-8, LF line endings, 4-space indentation
- **Max line length**: 80 characters
- **File naming**: CamelCase directories, `Index.rst` in each
- **Headings**: `=` (h1), `-` (h2), `~` (h3), `^` (h4)
- **Code blocks**: `.. code-block::` with `:caption:` for 5+ lines
- **Cross-references**: `.. _label:` + `:ref:\`label\`` (not file paths)
- **TYPO3 directives**: `.. confval::`, `.. note::`, `.. warning::`,
  `.. tip::`, `.. important::`, `.. versionadded::`

## Examples (ADR format)

```rst
.. include:: /Includes.rst.txt

=========================================
ADR-NNN: Title of Decision
=========================================

:Status: Accepted|Deprecated|Superseded
:Date: YYYY-MM-DD
:Decision-makers: Name

Context
=======
(situation + alternatives)

Decision
========
(what was decided)

Consequences
============
(positive / negative / mitigation)

Alternatives Considered
=======================
(rejected options)
```

ADR files use ``.. include:: /Includes.rst.txt`` (absolute from docs root).
Do NOT modify existing ADR files (Adr001-012). Add new ones as Adr013+.

## Build (render docs)

```bash
# Local rendering via DDEV
ddev docs

# Or directly via Docker
docker run --rm -v $(pwd):/project \
  ghcr.io/typo3-documentation/render-guides:latest \
  --no-progress --output=/project/Documentation-GENERATED-temp \
  /project/Documentation
```

Output goes to `Documentation-GENERATED-temp/` (gitignored).

## Security

- Use placeholder domains (`example.com`) and obviously fake credentials in all examples.
- Screenshots must not contain real user data or real credential identifiers.
- Keep Security/ThreatModel.rst in sync when security-relevant behavior changes.

## PR Checklist
- [ ] Docs render without warnings (CI runs the render check via docs.yml)
- [ ] New config options documented with `.. confval::`
- [ ] Cross-references use `:ref:` labels, not file paths
- [ ] New ADRs numbered sequentially (Adr013+), existing ADRs untouched

## When stuck
- Render errors: run the Docker render locally (see Build above) and read the warnings.
- Directive reference: https://docs.typo3.org/m/typo3/docs-how-to-document/main/en-us/
- Follow existing pages in this directory as formatting references.

## Rules

- Update `guides.xml` version + `ext_emconf.php` version together at release
- Keep RST compatible with TYPO3 render-guides (phpDocumentor-based)
- Screenshots go in `Images/` subdirectories as PNG with `:alt:` text
- Every directory must have an `Index.rst`
- Use `.. versionadded::` for new features, `.. deprecated::` for removed ones
- Keep README.md and Documentation/ in sync (config names, features, API)
- Use `.. confval::` for configuration settings, `:guilabel:` for UI elements
