# Contributing

Contributions are welcome via GitHub Issues and Pull Requests.

- **Bug reports & feature requests**: Open an issue at https://github.com/netresearch/nr_passkeys_fe/issues
- **Pull requests**: Fork the repository, create a feature branch, and open a PR against `main`
- Follow the [conventional commits](https://www.conventionalcommits.org/) format for commit messages
- All PHP code must pass `composer ci:test` (PHPStan level 9, PHPCS, unit tests)
- JavaScript changes must pass `npm test`
- Please sign your commits (`git commit -S --signoff`)

See [AGENTS.md](AGENTS.md) for developer onboarding and architecture notes.
