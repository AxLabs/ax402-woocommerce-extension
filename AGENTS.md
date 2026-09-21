# Agent notes

Use this file as a short map of the repo. Prefer the linked docs over inventing process.

## Product

WordPress / WooCommerce **payment provider** for Ax402 / x402. Catalog stays USD; settlement tokens come from Ax402 platform config. See [docs/context.md](docs/context.md) and [docs/architecture.md](docs/architecture.md).

Optional UCP for buying agents: [docs/ucp.md](docs/ucp.md). x402 on that surface follows [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).

## Releases & versioning

**Required reading before any version bump, GitHub Release, or WordPress.org publish:** [RELEASE.md](RELEASE.md).

- **GitHub** is where we develop. **WordPress.org SVN** is a release snapshot for the Plugin Directory only — not a second development remote.
- SemVer in multiple files must stay in sync (`bash bin/check-version.sh`).
- Bump with `bash bin/bump-version.sh X.Y.Z`.
- GitHub: push annotated tag `vX.Y.Z` → `.github/workflows/release.yml` runs Plugin Check on the zip, then creates the GitHub Release.
- WordPress.org: after that Release exists, copy the **packaged zip** into SVN `trunk/` and `tags/X.Y.Z` (see RELEASE.md). Do not paste SVN passwords into chat.
- Canonical changelog is GitHub Releases. `readme.txt` Changelog is a link stub only (`bump-version.sh` inserts it).

## Local E2E

[docs/e2e.md](docs/e2e.md), [docs/local-development.md](docs/local-development.md). Never commit `.env` or private keys.

## Layout

```text
plugin/          WordPress plugin
bin/             seed, phpunit, package, version helpers
docs/            architecture, e2e, merchant, testing, ucp
docs/prompts/    local Cursor/agent prompts (gitignored; not shipped)
tests/           PHP / JS / Playwright
wporg-assets/    Plugin Directory icon + screenshots (SVN `assets/`, not the zip)
RELEASE.md       GitHub + WordPress.org SVN release process
```
