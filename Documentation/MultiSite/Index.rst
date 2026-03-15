..  include:: ../Includes.rst.txt

..  _multi-site:

==========
Multi-Site
==========

TYPO3 multi-site installations require each site to have its own
passkey configuration because WebAuthn credentials are bound to a
specific domain (Relying Party ID).

How site-aware RP ID works
---------------------------

When a passkey authentication or enrollment request arrives, the
extension resolves the current TYPO3 site from the request and reads
the ``nr_passkeys_fe.rpId`` value from the site's ``config.yaml``.

If no per-site RP ID is configured, the extension falls back to the
global extension setting (auto-detected from ``HTTP_HOST``).

This means:

- Credentials registered on ``site-a.example`` cannot be used on
  ``site-b.example``.
- Each site maintains its own independent credential store (filtered
  by site RP ID).
- Users who access multiple sites must enroll a passkey separately on
  each site.

Configuration example
----------------------

For a TYPO3 installation with two separate domains:

..  code-block:: yaml
    :caption: config/sites/company-intranet/config.yaml

    base: 'https://intranet.example.com/'
    settings:
      nr_passkeys_fe:
        rpId: 'intranet.example.com'
        origin: 'https://intranet.example.com'
        enforcementLevel: 'enforced'
        enrollmentPageUrl: '/passkey-setup'

..  code-block:: yaml
    :caption: config/sites/public-shop/config.yaml

    base: 'https://shop.example.com/'
    settings:
      nr_passkeys_fe:
        rpId: 'shop.example.com'
        origin: 'https://shop.example.com'
        enforcementLevel: 'encourage'
        enrollmentPageUrl: '/passkey-setup'

Shared RP ID across subdomains
--------------------------------

WebAuthn allows the RP ID to be a registrable domain suffix. For
example, credentials registered with ``rpId: 'example.com'`` can
be used on both ``app.example.com`` and ``api.example.com``.

To enable this, set all sites to the same parent domain:

..  code-block:: yaml

    # site-a/config.yaml
    settings:
      nr_passkeys_fe:
        rpId: 'example.com'
        origin: 'https://app.example.com'

    # site-b/config.yaml
    settings:
      nr_passkeys_fe:
        rpId: 'example.com'
        origin: 'https://api.example.com'

..  warning::

    Sharing an RP ID means a passkey registered on one subdomain can
    be used on all other subdomains sharing the same RP ID. Only share
    RP IDs across subdomains you fully trust.

Database isolation
------------------

Credentials are stored with a ``site_identifier`` column in
``tx_nrpasskeysfe_credential``. The credential lookup in the auth
service always filters by the current site's identifier. There is no
cross-site credential leakage.

Migration from single-site to multi-site
------------------------------------------

If you initially deployed without per-site RP ID configuration (using
auto-detected RP ID) and later add multiple sites:

1. The auto-detected RP ID was ``HTTP_HOST`` from the original request.
2. Existing credentials have a ``site_identifier`` matching the
   original site.
3. When adding a new site with a different domain, users must re-enroll
   (their existing credentials won't match the new RP ID).

Plan migrations carefully and communicate re-enrollment to users in
advance.
