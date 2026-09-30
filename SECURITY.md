<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in this extension, please report it
responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Report it through GitHub's private vulnerability reporting instead:
[Report a vulnerability](https://github.com/netresearch/t3x-nr-passkeys-fe/security/advisories/new).
The report is visible only to you and the maintainers of this repository.

## What to Include

- A description of the vulnerability
- Steps to reproduce the issue
- Affected versions
- Any potential impact assessment

## Response Timeline

- **Acknowledgment**: Within 3 business days
- **Initial assessment**: Within 7 business days
- **Fix timeline**: Depends on severity; critical issues are prioritized

## Supported Versions

Only the latest major release line receives bug fixes and security fixes. A
fix ships as the next patch or minor release from `main`; no fix is backported
to an older release line.

| Version | Bug fixes          | Security fixes     |
|---------|--------------------|--------------------|
| 2.x     | :white_check_mark: | :white_check_mark: |
| < 2.0   | :x:                | :x:                |

- **End of support:** a major release line `N.x` stops receiving bug fixes and
  security fixes on the day `(N+1).0.0` is released. From then on, the fix for
  a vulnerability is in the newest release only, and users of an older line
  upgrade to it. The 1.x line ended with the release of 2.0.0 on 2026-09-30.
- **Upgrading:** the [changelog](Documentation/Changelog/Index.rst) lists the
  breaking changes of each release with the upgrade step they need.
- **Getting support:** questions and bug reports go to the
  [issue tracker](https://github.com/netresearch/t3x-nr-passkeys-fe/issues);
  vulnerabilities are reported privately as described above.

The table is updated by the release commit of every major release;
`Tests/Unit/SecurityPolicyTest.php` fails when it names a line other than the
one `ext_emconf.php` is on.

## Security Best Practices

This extension handles WebAuthn/FIDO2 authentication. When deploying:

- Always use HTTPS (required by WebAuthn specification)
- Keep PHP and TYPO3 updated to their latest stable versions
- Review and configure rate limiting settings appropriately
- Monitor authentication logs for suspicious activity
