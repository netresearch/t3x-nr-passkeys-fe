<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Contributing

Contributions are welcome via GitHub Issues and Pull Requests.

- **Bug reports & feature requests**: Open an issue at https://github.com/netresearch/t3x-nr-passkeys-fe/issues
- **Pull requests**: Fork the repository, create a feature branch, and open a PR against `main`
- Follow the [conventional commits](https://www.conventionalcommits.org/) format for commit messages
- All PHP code must pass `composer ci:test:php:phpstan` (PHPStan level 10), `composer ci:test:php:cgl` (PHP-CS-Fixer) and `composer ci:test:php:unit`
- JavaScript changes must pass `npx vitest run`
- Please sign your commits (`git commit -S --signoff`)

See [AGENTS.md](AGENTS.md) for developer onboarding and architecture notes.

## Governance and policies

This extension follows the organisation-wide Netresearch policies wherever it
has no file of its own:

- [Governance](https://github.com/netresearch/.github/blob/main/GOVERNANCE.md): ownership, the roles (organisation owner, repository admin, maintainer, contributor) and their responsibilities for reviews, merges, releases and vulnerability reports, how decisions are made and how disagreements are resolved.
- [Roadmap](https://github.com/netresearch/.github/blob/main/ROADMAP.md): the maintenance commitment from October 2026 to September 2027, with the work that is planned and the work that is excluded. This extension has no roadmap of its own.
- [Handling of dependency and code analysis findings](https://github.com/netresearch/.github/blob/main/SECURITY.md#handling-of-dependency-and-code-analysis-findings): which vulnerability, licence and static-analysis findings must be fixed, by when, and how exceptions are recorded.
- [Secret management](https://github.com/netresearch/.github/blob/main/SECURITY.md#secret-management): where CI and release credentials are stored, who may use them, and when they are rotated.
- [Access roster](https://github.com/netresearch/.github/blob/main/docs/access-roster.md): every account with admin, maintain or write access to this repository, by role.

Review assignment in this repository is [.github/CODEOWNERS](.github/CODEOWNERS):
every path is owned by the `@netresearch/typo3` team. A pull request into
`main` needs one approving review, signed commits and the required status
checks. Supported versions and the vulnerability reporting process are in
[SECURITY.md](SECURITY.md).

These dependency and static-security checks run on every pull request
(`.github/workflows/checks.yml` and the reusable workflows it calls):

- **Dependency review** blocks a pull request that adds or changes a dependency with a known vulnerability of severity high or critical.
- **Composer Audit** fails on any known vulnerability in the resolved PHP dependencies.
- **PHP License Audit** fails when a PHP dependency is under SSPL or BSL.
- **Opengrep** (SAST, `--config auto --error --severity WARNING`) fails on findings of severity WARNING or higher.
- **CodeQL** analyses the JavaScript and TypeScript code and the workflow files and reports alerts to code scanning; CodeQL has no PHP analyser, so the PHP code is scanned by Opengrep only.
- **zizmor** audits the workflow files and reports to code scanning.
- **Betterleaks** fails when a secret is committed.

The only CI secrets this repository uses are `TYPO3_TER_ACCESS_TOKEN` (TER
publishing on a release tag), `CODECOV_TOKEN` (coverage upload) and the merge
app's `PROJECT_APP_ID` and `PROJECT_APP_PRIVATE_KEY` (auto-merge of
dependency updates); the secret management policy above applies to them.
