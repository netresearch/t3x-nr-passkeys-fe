# Passkeys Frontend Authentication

TYPO3 extension for passwordless frontend authentication via WebAuthn/FIDO2 Passkeys.
Enables ``fe_users`` to log in with TouchID, FaceID, YubiKey, or Windows Hello -- no password
required.

## Features

- **Passkey-first login** -- Discoverable (usernameless) and username-first flows via a standalone plugin
- **felogin integration** -- Injects a passkey button into the standard felogin plugin
- **Self-service management** -- Users can enroll, rename, and revoke their own passkeys from the frontend
- **Recovery codes** -- 10 one-time bcrypt-hashed recovery codes as a fallback
- **Per-site RP ID** -- Each TYPO3 site has an independent WebAuthn Relying Party configuration
- **Per-group enforcement** -- Four levels: Off, Encourage, Required, Enforced -- with configurable grace periods
- **Post-login interstitial** -- Enrollment prompt shown to users without a passkey when enforcement is active
- **Backend admin module** -- Adoption statistics, credential management, enforcement settings
- **8 PSR-14 events** -- Before/after authentication, before/after enrollment, enforcement resolved, passkey removed, recovery codes generated, magic link requested
- **Security hardened** -- HMAC-signed challenges, nonce replay protection, per-IP rate limiting, account lockout
- **Vanilla JavaScript** -- Zero runtime npm dependencies; native WebAuthn browser API only

## Requirements

- PHP 8.2+
- TYPO3 v13.4 LTS or v14.1+
- `netresearch/nr-passkeys-be` ^0.6 (installed automatically)
- HTTPS (required by WebAuthn; `localhost` works for development)

## Installation

```bash
composer require netresearch/nr-passkeys-fe
vendor/bin/typo3 extension:activate nr_passkeys_be
vendor/bin/typo3 extension:activate nr_passkeys_fe
vendor/bin/typo3 database:updateschema
```

## Quick Start

1. **Include TypoScript** in your site's root template:
   ```typoscript
   @import 'EXT:nr_passkeys_fe/Configuration/TypoScript/setup.typoscript'
   @import 'EXT:nr_passkeys_fe/Configuration/TypoScript/constants.typoscript'

   plugin.tx_nrpasskeysfe.settings.loginPageUid = 42
   plugin.tx_nrpasskeysfe.settings.managementPageUid = 43
   plugin.tx_nrpasskeysfe.settings.enrollmentPageUid = 44
   ```

2. **Add plugins** to your pages:
   - Login page: *Passkeys Frontend Authentication > Login*
   - Management page: *Passkeys Frontend Authentication > Management*
   - Enrollment page: *Passkeys Frontend Authentication > Enrollment*

3. **Configure the site** in `config/sites/my-site/config.yaml`:
   ```yaml
   passkeys:
     rpId: 'your-domain.example'
     rpName: 'My Site'
     origin: 'https://your-domain.example'
     enforcementLevel: 'encourage'
   ```

Visit the login page and click **Sign in with a passkey**.

## Documentation

Full documentation: [docs.typo3.org/p/netresearch/nr-passkeys-fe/main/en-us/](https://docs.typo3.org/p/netresearch/nr-passkeys-fe/main/en-us/)

- [Installation](Documentation/Installation/Index.rst)
- [Configuration](Documentation/Configuration/Index.rst)
- [Quick Start](Documentation/QuickStart/Index.rst)
- [Developer Guide](Documentation/DeveloperGuide/Index.rst)
- [Security](Documentation/Security/Index.rst)

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Contributing

Issues and pull requests: [github.com/netresearch/t3x-nr-passkeys-fe](https://github.com/netresearch/t3x-nr-passkeys-fe)

See [AGENTS.md](AGENTS.md) for development setup, code style, and test commands.
