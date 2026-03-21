<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-15 -->

# AGENTS.md — .ddev

<!-- AGENTS-GENERATED:START overview -->
## Overview
DDEV local development environment for **EXT:nr_passkeys_fe** (TYPO3 frontend passkey authentication).
Supports TYPO3 v13.4 LTS and v14.x. Requires the sibling BE extension (`t3x-nr-passkeys-be/main`)
mounted as a path repository. Uses **PHP 8.3**, **MariaDB 10.11**, and **NodeJS 20** (for Vitest JS tests).
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `config.yaml` | Main DDEV configuration (PHP 8.3, MariaDB 10.11, NodeJS 20) |
| `docker-compose.web.yaml` | Web service: extension mounts, TYPO3 env vars, named volumes |
| `web-build/Dockerfile` | Custom image: PCOV, landing page, stub version dirs |
| `commands/host/docs` | Host command: render RST documentation via Docker |
| `commands/host/setup` | Host command: full setup (docs + all TYPO3 installs) |
| `commands/web/install-v13` | Container command: install TYPO3 13.4 LTS with both extensions |
| `commands/web/install-v14` | Container command: install TYPO3 14.x with both extensions |
| `commands/web/install-all` | Container command: install v13 + v14 in sequence |
| `data/demo-pages.sql` | SQL fixture: demo pages, FE user (demo/demo), FE group |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START commands -->
## Common Commands
| Task | Command |
|------|---------|
| Full setup (first time) | `ddev setup` or `make up` |
| Start | `ddev start` |
| Stop | `ddev stop` |
| Install TYPO3 v13 | `ddev install-v13` |
| Install TYPO3 v14 | `ddev install-v14` |
| Install all versions | `ddev install-all` |
| Render docs | `ddev docs` |
| SSH into container | `ddev ssh` |
| Run composer | `ddev composer ...` |
| Database export | `ddev export-db > dump.sql.gz` |
| View logs | `ddev logs` |
| Restart | `ddev restart` |
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START patterns -->
## Key Patterns

### Extension Mounts
The FE extension is mounted at `/var/www/nr_passkeys_fe` and the BE extension (dependency) at
`/var/www/nr_passkeys_be`. Both are added as path repositories when installing TYPO3, so composer
resolves `netresearch/nr-passkeys-be:*@dev` from the local checkout.

The BE extension worktree is expected at `../../t3x-nr-passkeys-be/main` relative to this project
(i.e., `/home/cybot/projects/t3x-nr-passkeys-be/main`). Clone it alongside this project if missing.

### TYPO3 Versions
- **v13**: Uses `t3/cms:^13` metapackage
- **v14**: Uses `typo3/minimal:^14` + individual packages (no t3/cms v14 release)
- Both versions install into named Docker volumes (`nr-passkeys-fe-v13-data`, `-v14-data`) so
  TYPO3 instances persist across `ddev stop` / `ddev start`.

### NodeJS 20
NodeJS 20 is configured in `config.yaml` for running Vitest JS tests inside DDEV:
```bash
ddev exec npm run test:js
# or from host:
npm run test:js
```

### TYPO3_CONTEXT
`TYPO3_CONTEXT=Development` is set in `docker-compose.web.yaml` so TYPO3 automatically uses
development error reporting without manual configuration.

### Access URLs (after ddev start + install)
| Resource | URL |
|----------|-----|
| Landing page | https://nr-passkeys-fe.ddev.site/ |
| TYPO3 v13 Frontend | https://v13.nr-passkeys-fe.ddev.site/ |
| TYPO3 v13 Backend | https://v13.nr-passkeys-fe.ddev.site/typo3/ |
| TYPO3 v14 Frontend | https://v14.nr-passkeys-fe.ddev.site/ |
| TYPO3 v14 Backend | https://v14.nr-passkeys-fe.ddev.site/typo3/ |
| Documentation | https://docs.nr-passkeys-fe.ddev.site/ |

Backend credentials: `admin` / `Joh316!!`

### Demo Frontend Login Pages
Created automatically by `install-v13` / `install-v14` from the fixture at `data/demo-pages.sql`.

| Page | URL | Purpose |
|------|-----|---------|
| Login | `/passkey-login` | Combined: felogin password + passkey button via template override |
| Passkey-Only Login | `/passkey-only` | Standalone passkey plugin (no password fallback) |
| My Account | `/my-account` | Passkey management (visible only when logged in) |
| Passkey Setup | `/passkey-setup` | Passkey enrollment |

**Demo FE user:** `demo` / `demo` (stored in SysFolder "FE Users" under root page)

**How to reset demo data:** Re-run `ddev install-v13` or `ddev install-v14` — both drop and recreate the database, then re-import the fixture.
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START code-style -->
## Configuration Style
- Keep `config.yaml` minimal, use `docker-compose.web.yaml` for service overrides
- Document custom commands with `## Description:` / `## Usage:` / `## Example:` headers
- Use `set -e` in web commands to fail fast on errors
- Never hardcode paths that differ between machines — use env vars or relative mounts
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] `ddev start` succeeds after changes
- [ ] Custom commands have Description/Usage/Example headers
- [ ] Both extensions resolve correctly from path repositories
- [ ] Works on macOS, Linux, and Windows (WSL2)
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START skill-reference -->
## Skill Reference
> For DDEV setup, TYPO3 multi-version testing, and custom commands:
> **Invoke skill:** `typo3-ddev`
<!-- AGENTS-GENERATED:END skill-reference -->
