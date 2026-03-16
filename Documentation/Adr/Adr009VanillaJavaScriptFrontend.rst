.. include:: /Includes.rst.txt

====================================================================
ADR-009: Vanilla JavaScript for Frontend (No Framework Dependencies)
====================================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

The frontend JavaScript needs to handle WebAuthn ceremonies (``navigator.credentials``
API), form interactions, and API calls. Unlike the TYPO3 backend, the frontend has
no built-in JavaScript infrastructure (no TYPO3 backend module loader, no Lit, no
RequireJS).

Options considered:

A. **Vanilla ES6 modules** — No framework, plain JavaScript
B. **Lit/Web Components** — Lightweight, standards-based
C. **React/Vue/Svelte** — Full SPA framework
D. **TYPO3 backend JS modules** — Reuse backend patterns

Decision
========

**Option A: Vanilla ES6 modules for frontend, TYPO3 backend JS modules for admin UI.**

**Frontend (5 modules):**

- ``PasskeyLogin.js`` — Login ceremony (``navigator.credentials.get``)
- ``PasskeyEnrollment.js`` — Registration ceremony (``navigator.credentials.create``)
- ``PasskeyManagement.js`` — Self-service CRUD
- ``PasskeyRecovery.js`` — Recovery code entry
- ``PasskeyBanner.js`` — Enrollment prompt banner

All modules:

- Use progressive enhancement (forms work without JS, JS enhances with WebAuthn)
- Use ``data-*`` attributes for configuration (no inline scripts)
- Use ``fetch()`` for API calls
- No build step required (directly loadable as ES modules)

**Backend admin (2 modules):**

- ``PasskeyFeAdmin.js`` — Admin dashboard
- ``PasskeyFeAdminInfo.js`` — User info element

Backend modules use TYPO3's ``@typo3/`` import system, consistent with nr-passkeys-be.

Consequences
============

**Positive:**

- Zero dependencies: no npm install needed for production
- Works in any TYPO3 frontend theme (no framework conflicts)
- Progressive enhancement: accessible without JavaScript
- Small bundle size (~15-20KB total)
- No build step needed
- Easy to override/customize for integrators

**Negative:**

- More verbose than framework-based code
- No reactive state management (manual DOM updates)
- No component model for complex UIs

**Mitigation:**

- Frontend JS is simple (ceremony calls + form handling), doesn't need a framework
- Vitest tests mock ``navigator.credentials`` for unit testing
- CSS classes follow BEM convention for easy styling override

Alternatives Considered
=======================

**Option B (Lit/Web Components):** Adds a dependency and build step for marginal
benefit. WebAuthn ceremonies are procedural, not component-oriented.

**Option C (React/Vue/Svelte):** Massive overkill for form handling + API calls.
Would conflict with the site's existing frontend framework.

**Option D (TYPO3 backend JS):** Not available in frontend context. TYPO3's backend
JS infrastructure (``@typo3/`` modules, Lit elements) is backend-only.
