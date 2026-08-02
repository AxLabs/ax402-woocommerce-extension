# Agent notes

Use this file as a short map of the repo. Prefer the linked docs over inventing process.

## Product

WordPress / WooCommerce **payment provider** for Ax402 / x402. Catalog stays USD; settlement tokens come from Ax402 platform config. See [docs/context.md](docs/context.md) and [docs/architecture.md](docs/architecture.md).

## Releases & versioning

**Required reading before any version bump or GitHub Release:** [RELEASE.md](RELEASE.md).

- SemVer in multiple files must stay in sync (`bash bin/check-version.sh`).
- Bump with `bash bin/bump-version.sh X.Y.Z`.
- Publish by pushing annotated tag `vX.Y.Z` → `.github/workflows/release.yml` builds the zip and creates the GitHub Release.

## Local E2E

[docs/e2e.md](docs/e2e.md), [docs/local-development.md](docs/local-development.md). Never commit `.env` or private keys.

## Layout

```text
plugin/     WordPress plugin
bin/        seed, phpunit, package, version helpers
docs/       architecture, e2e, merchant, testing
tests/      PHP / JS / Playwright
RELEASE.md  release process (humans + agents)
```
