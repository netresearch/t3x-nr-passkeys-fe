..  include:: ../Includes.rst.txt

..  _developer-guide:

===============
Developer Guide
===============

This chapter is for developers who want to understand, debug, or
extend the extension.

Architecture overview
=====================

The extension is layered as follows:

..  code-block:: text

    Classes/
      Authentication/     PSR-7-based auth service (TYPO3 auth chain)
      Configuration/      Configuration value objects
      Controller/         eID dispatcher + Extbase-less controllers
      Domain/
        Dto/              Request/response DTOs
        Enum/             EnforcementLevel enum (re-exported)
        Model/            FrontendCredential, RecoveryCode
      Event/              PSR-14 event classes (7 events)
      EventListener/      PSR-14 listeners (felogin integration, banner)
      Form/Element/       PasskeyFeInfoElement (TCA read-only display)
      Middleware/         PasskeyPublicRouteResolver + Interstitial
      Service/            Business logic (8 services)

Key services:

- **FrontendWebAuthnService** -- WebAuthn ceremony orchestration
- **SiteConfigurationService** -- Per-site RP ID and origin resolution
- **FrontendCredentialRepository** -- Credential CRUD operations
- **FrontendUserLookupService** -- ``fe_users`` lookup by username/UID
  (separated from credential repository for single-responsibility)
- **FrontendEnforcementService** -- Enforcement level resolution
- **RecoveryCodeService** -- Recovery code generation and verification
- **PasskeyEnrollmentService** -- Enrollment ceremony coordination
- **FrontendAdoptionStatsService** -- Adoption statistics for admin module

All services are wired via Symfony DI (``Configuration/Services.yaml``).
The auth service and eID dispatcher use ``GeneralUtility::makeInstance``
for compatibility with the TYPO3 auth chain.

Token-based login flow
-----------------------

The extension uses a two-phase login flow:

1. **eID verification**: The JavaScript calls the eID endpoint
   (``loginVerify`` or ``recoveryVerify``). The eID controller
   verifies the WebAuthn assertion (or recovery code) and stores the
   authenticated ``fe_user`` UID in a short-lived cache token
   (``nr_passkeys_fe_nonce`` cache, 2-minute TTL).

2. **felogin form submission**: The JavaScript submits a standard
   ``logintype=login`` form to the current page, passing the cache
   token in a hidden ``passkeyLoginToken`` field. TYPO3's normal
   authentication chain picks this up.

3. **Auth service resolution**: ``PasskeyFrontendAuthenticationService``
   (priority 80) reads the ``passkeyLoginToken`` from ``$loginData``,
   looks up the UID in the cache, and returns the user row. No site
   context or WebAuthn libraries are needed at this stage.

This approach ensures the user gets a proper TYPO3 frontend session
with all middleware (enforcement interstitial, session regeneration)
applied, rather than a bare eID-only response.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    Events
    ExtensionPoints
    Api
