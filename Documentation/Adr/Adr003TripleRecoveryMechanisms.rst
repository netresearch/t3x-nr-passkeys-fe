.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-003:

===================================================================
ADR-003: Triple Recovery Mechanisms (Password + Codes + Magic Link)
===================================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

Passkey-first authentication creates a lockout risk when users lose access to their
authenticator. The design principle is "recovery first" — no user should be permanently
locked out because their phone broke.

Four approaches were considered, from minimal to maximal:

A. **Password fallback only**
B. **Password + recovery codes**
C. **Password + magic link**
D. **All three: password + recovery codes + magic link**

Decision
========

**Option D: All three recovery mechanisms, phased.**

Password login follows the site's permitted recovery methods and enrollment
policy. Users generate recovery codes explicitly in passkey management after
recent authentication. Rotation replaces the site's previous set. The default
is ten single-use codes in the format ``XXXX-XXXX``. Codes are stored as
bcrypt hashes. Email magic links remain a planned feature.

Configure the permitted methods per site:

.. code-block:: yaml

   nr_passkeys_fe:
     enabledRecoveryMethods:
       - password
       - recovery_code

Consequences
============

**Positive:**

- Maximum flexibility for different deployment scenarios
- Per-site configurability covers diverse requirements
- Phased delivery reduces v0.1 scope while planning for v0.2
- Password fallback is free (felogin already handles it)

**Negative:**

- Magic link adds complexity (email delivery, token management)
- Three auth paths to secure and test
- User confusion about which recovery method to use

**Mitigation:**

- Magic link deferred to v0.2 (see ADR-011)
- Clear recovery UI: show available methods based on site config
- Each mechanism tested independently and in combination

Alternatives Considered
=======================

**Options A-C:** Each individually viable but less flexible. Enterprise deployments
may disable password fallback entirely (compliance), making recovery codes or magic
links essential. Consumer sites may prefer magic links over codes.
