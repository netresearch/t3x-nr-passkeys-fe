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
      Event/              PSR-14 event classes (8 events)
      EventListener/      PSR-14 listeners (felogin integration, banner)
      Form/Element/       PasskeyFeInfoElement (TCA read-only display)
      Middleware/         PasskeyPublicRouteResolver + Interstitial
      Service/            Business logic (6 services)

All services are wired via Symfony DI (``Configuration/Services.yaml``).
The auth service and eID dispatcher use ``GeneralUtility::makeInstance``
for compatibility with the TYPO3 auth chain.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    Events
    ExtensionPoints
    Api
