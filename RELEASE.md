# Releases & versioning

This document is the **source of truth** for cutting releases of the Ax402 WooCommerce plugin. Humans and coding agents should follow it whenever changing version numbers, shipping builds, or creating GitHub Releases.

Current version lives in multiple files that **must stay identical** (see [Canonical version sources](#canonical-version-sources)).

## SemVer policy

We use [Semantic Versioning](https://semver.org/) `MAJOR.MINOR.PATCH` (optional pre-release suffix like `0.2.0-rc.1`).

While on **`0.x`**:

| Bump | When |
|---|---|
| **PATCH** (`0.1.0` → `0.1.1`) | Bug fixes, docs, CI, no merchant-facing behavior change |
| **MINOR** (`0.1.0` → `0.2.0`) | New features / settings that stay backward compatible for existing stores |
| **MAJOR** (`0.x` → `1.0.0` or `1.x` → `2.x`) | Breaking changes to gateway contracts, REST, settings migration, or install path |

After `1.0.0`, treat MAJOR as any incompatible change for merchants or agents.

**Git tags** are always prefixed: `v0.1.0`, `v0.2.0`, …

## Canonical version sources

All of these must match before tagging:

| Location | Field |
|---|---|
| `plugin/ax402-for-woocommerce.php` | Header `* Version:` |
| `plugin/ax402-for-woocommerce.php` | `AX402_WC_VERSION` constant |
| `plugin/readme.txt` | `Stable tag:` |
| `package.json` | `"version"` |
| `plugin/package.json` | `"version"` |

Helper scripts:

```bash
bash bin/check-version.sh           # assert all sources agree
bash bin/check-version.sh v0.2.0    # also assert they equal the tag / SemVer
bash bin/bump-version.sh 0.2.0      # rewrite all sources to 0.2.0, then check
```

Do **not** hand-edit only one file. Prefer `bump-version.sh`.

`plugin/readme.txt` **Changelog** section should gain an entry for every release (agents: update it in the same PR as the bump).

## Release artifact

```bash
npm run package
# → dist/ax402-for-woocommerce-<version>.zip
```

The zip is the installable WordPress plugin (built JS included; `node_modules` / `src` excluded). See `bin/package-plugin.sh`.

`dist/` is gitignored; CI attaches the zip to the GitHub Release.

## How to cut a release

### 1. Prepare `main`

- CI on `main` is green (unit + live-cp as applicable).
- Changelog notes drafted under `== Changelog ==` in `plugin/readme.txt` for the new version.

### 2. Bump + commit

```bash
git checkout main
git pull

# Example: next minor
bash bin/bump-version.sh 0.2.0

# Edit plugin/readme.txt Changelog for = 0.2.0 = if bump did not (Stable tag only).

git add plugin/ax402-for-woocommerce.php plugin/readme.txt package.json plugin/package.json
git commit -m "Release v0.2.0"
git push origin main
```

### 3. Tag and push the tag

```bash
git tag -a v0.2.0 -m "v0.2.0"
git push origin v0.2.0
```

Tag name **must** be `v` + the SemVer in the version sources (`v0.2.0`).

### 4. GitHub Actions creates the Release

Workflow: [`.github/workflows/release.yml`](.github/workflows/release.yml)

On `push` of tags `v*.*.*` it will:

1. Reclaim self-hosted workspace ownership (same as CI)
2. `bash bin/check-version.sh <tag>`
3. Install deps, `npm run package`
4. `gh release create` with `dist/ax402-for-woocommerce-<version>.zip` attached

Inspect: **GitHub → Releases** (or `gh release view v0.2.0`).

### 5. Verify

- Release page shows the zip
- Download and smoke-install on a staging WP / `wp-env` if the change is merchant-critical

## Agent checklist (do not skip)

When asked to “release”, “bump version”, or “cut a GitHub release”:

1. Read this file and run `bash bin/check-version.sh` first.
2. Confirm SemVer bump type with the user if ambiguous.
3. Update `plugin/readme.txt` changelog for the new version.
4. Use `bash bin/bump-version.sh <version>` — never partial edits.
5. Commit with message `Release vX.Y.Z`.
6. Create **annotated** tag `vX.Y.Z` and push **the tag** (pushing `main` alone does not publish).
7. Do not force-push tags; do not delete published releases without explicit user approval.
8. Do not put secrets in release notes.
9. Leave `bin/repro-*.py` and `.env` out of commits (see `.gitignore`).

## Manual / emergency release

If Actions is unavailable:

```bash
bash bin/check-version.sh v0.2.0
npm ci && npm --prefix plugin ci
npm run package
gh release create v0.2.0 "dist/ax402-for-woocommerce-0.2.0.zip" \
  --title "v0.2.0" \
  --generate-notes \
  --verify-tag
```

Only after the matching annotated tag exists locally and on `origin`.

## Related docs

- [README.md](README.md) — project overview
- [docs/context.md](docs/context.md) — product framing
- [docs/architecture.md](docs/architecture.md) — payment / fulfill flow
- [docs/testing.md](docs/testing.md) — test commands
- [AGENTS.md](AGENTS.md) — short agent entrypoint
