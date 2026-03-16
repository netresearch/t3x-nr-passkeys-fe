# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in this extension, please report it
responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please report vulnerabilities through one of these channels:

1. **GitHub Security Advisories** (preferred):
   [Report a vulnerability](https://github.com/netresearch/t3x-nr-passkeys-fe/security/advisories/new)

2. **Email**: Send details to the maintainers via the contact information in
   [composer.json](composer.json).

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

| Version | Supported          |
|---------|--------------------|
| 0.x     | :white_check_mark: |

## Security Best Practices

This extension handles WebAuthn/FIDO2 authentication. When deploying:

- Always use HTTPS (required by WebAuthn specification)
- Keep PHP and TYPO3 updated to their latest stable versions
- Review and configure rate limiting settings appropriately
- Monitor authentication logs for suspicious activity
