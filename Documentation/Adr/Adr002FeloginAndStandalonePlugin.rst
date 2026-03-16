.. include:: /Includes.rst.txt

=====================================================
ADR-002: Both felogin Extension and Standalone Plugin
=====================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

TYPO3 frontend login is typically handled by ``ext:felogin``. The question is how
``nr_passkeys_fe`` integrates its passkey login UI with the existing login flow.

Three approaches were considered:

A. **Extend felogin only** — Inject via ``ModifyLoginFormViewEvent``
B. **Own Extbase plugin only** — Independent login plugin
C. **Both** — felogin integration AND standalone plugin

Decision
========

**Option C: Both felogin extension and standalone plugin.**

- **felogin integration:** PSR-14 listener on ``ModifyLoginFormViewEvent`` injects
  a passkey login button into the existing felogin form. ~50 lines of code.
- **Standalone plugin:** ``PasskeyLoginPlugin`` (Extbase) that can be placed on any
  page independently of felogin.

Consequences
============

**Positive:**

- Sites using felogin get seamless integration with zero template changes
- New sites can use the standalone plugin without felogin dependency
- Mirrors nr-passkeys-be pattern (injects into BE login + provides own UI)
- Minimal code overhead (~50 lines for felogin hook)

**Negative:**

- Two login UI paths to maintain and test
- Potential confusion for integrators ("which one do I use?")

**Mitigation:**

- Clear documentation: "Using felogin? It just works. Custom login? Use the plugin."
- Shared Fluid partials between both paths to minimize template duplication

Alternatives Considered
=======================

**Option A (felogin only):** Would exclude sites not using felogin. Some TYPO3
installations use custom login forms or third-party login extensions.

**Option B (Standalone only):** Would force felogin users to add a second plugin
alongside felogin, creating an awkward dual-form UX.
