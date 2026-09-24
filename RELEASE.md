# Releases & versioning

This document is the **source of truth** for cutting releases of the Ax402 WooCommerce plugin. Humans and coding agents should follow it whenever changing version numbers, shipping builds, creating GitHub Releases, or publishing to WordPress.org.

Current version lives in multiple files that **must stay identical** (see [Canonical version sources](#canonical-version-sources)).

## Two places, two jobs

| Place | Role |
|---|---|
| **This GitHub repo** ([AxLabs/ax402-woocommerce-extension](https://github.com/AxLabs/ax402-woocommerce-extension)) | **Development.** PRs, CI, history, annotated git tags, GitHub Releases with the installable zip. |
| **WordPress.org SVN** (`https://plugins.svn.wordpress.org/ax402-for-woocommerce`) | **Directory publish only.** A release snapshot of the packaged plugin so [wordpress.org/plugins/ax402-for-woocommerce](https://wordpress.org/plugins/ax402-for-woocommerce/) can list and serve it. |

We do **not** develop against SVN. Do not mirror every git commit. After a GitHub Release exists for `vX.Y.Z`, copy that packaged build into SVN `trunk/` and tag `tags/X.Y.Z` (no `v` prefix on the SVN tag).

Keep the SVN working copy **outside** this git repo (for example `~/svn/ax402-for-woocommerce`). Never commit `.svn/` here.

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
**SVN tags** use the same SemVer **without** the `v`: `tags/0.1.0`.

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

## Changelog (GitHub only)

**Canonical notes live on [GitHub Releases](https://github.com/AxLabs/ax402-woocommerce-extension/releases).** Do not maintain a second bullet list in `plugin/readme.txt`.

| Place | What to put there |
|---|---|
| GitHub Release `vX.Y.Z` | The real changelog (CI uses `gh … generate-notes` from PRs/commits; edit that Release if merchants need a clearer summary) |
| `plugin/readme.txt` `== Changelog ==` | One heading per version and a markdown link to that GitHub Release |

`bash bin/bump-version.sh X.Y.Z` updates `Stable tag:` **and** prepends the GitHub link stub. Do not paste commit bullets into `readme.txt`.

Write PR titles and commit messages as if they *are* the changelog. After the Release exists, optional polish happens **only** on GitHub (`gh release edit vX.Y.Z`).

## Release artifact

```bash
npm run package
# → dist/ax402-for-woocommerce-<version>.zip
```

The zip is the installable WordPress plugin (built JS included; `node_modules` / `src` excluded). See `bin/package-plugin.sh`.

`dist/` is gitignored; CI attaches the zip to the GitHub Release.

## How to cut a release

### 1. Prepare `main`

- CI on `main` is green (unit + Plugin Check + live-cp as applicable).
- Do **not** draft a second changelog in `plugin/readme.txt` (see [Changelog](#changelog-github-only)).

### 2. Bump + commit

```bash
git checkout main
git pull

bash bin/bump-version.sh 0.2.0

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

On `push` of tags `v*.*.*` (GitHub-hosted Ubuntu, so `gh` is available) it will:

1. `bash bin/check-version.sh <tag>`
2. Install deps, `npm run package`
3. WordPress [Plugin Check](https://wordpress.org/plugins/plugin-check/) on that zip (stable checks; **errors** fail the job and skip creating the GitHub Release; **warnings** are annotated but do not fail)
4. `gh release create` (or upload/edit if the release already exists) with `dist/ax402-for-woocommerce-<version>.zip` attached. **Release notes are generated from git history**, not copied from `readme.txt`.

Inspect: **GitHub → Releases** (or `gh release view v0.2.0`).

If Plugin Check fails after the tag is pushed, **do not force-push the tag**. Fix on `main`, bump patch, and tag the new version.

Local equivalent (needs `npm run env:start`): `npm run plugin-check`. CI/release check the **packaged zip**; the local command checks the wp-env mount with the same excludes.

### 5. Verify the GitHub Release

- Release page shows the zip
- Download and smoke-install on a staging WP / `wp-env` if the change is merchant-critical

### 6. Publish to WordPress.org SVN

Only after the GitHub Release for this version exists (Plugin Check already ran on the zip). See [WordPress.org SVN](#wordpressorg-svn).

## WordPress.org SVN

Official handbook: [Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/), [Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).

SVN username is the WordPress.org account that owns the plugin (`axlabs`). **Checkout is public** and usually does **not** ask for a password. Credentials are required on `svn ci` only. Do not put that password in this repo, in chat, or in CI logs. Type it in a local terminal.

### Layout (SVN, not this git tree)

```text
ax402-for-woocommerce/
  assets/          Directory banners, icons, screenshots (not shipped in the plugin zip)
  trunk/           Latest directory snapshot of the *packaged* plugin (files at trunk root)
  tags/0.4.3/      Immutable copy of that snapshot (folder name = SemVer, no "v")
```

`trunk/assets/` inside the plugin (admin CSS, pay-page shell, licenses) is **not** the same as the top-level SVN `assets/` folder. Directory artwork goes only in SVN `assets/`.

`Stable tag:` in `trunk/readme.txt` must match a folder under `tags/`. Do not set it to `trunk`.

### What to copy

Copy the **packaged zip**, not the git `plugin/` directory:

- Include `build/` (compiled JS/CSS)
- Exclude `src/`, `node_modules/`, tests, this git repo’s docs/CI

```bash
npm run package
# → dist/ax402-for-woocommerce-<version>.zip
# zip root is ax402-for-woocommerce/ … unzip *contents* into SVN trunk/
```

Files must sit at **`trunk/ax402-for-woocommerce.php`**. A nested `trunk/ax402-for-woocommerce/` folder will break the directory.

### First checkout

```bash
mkdir -p ~/svn
cd ~/svn
svn checkout https://plugins.svn.wordpress.org/ax402-for-woocommerce \
  --username axlabs
```

### Publish version X.Y.Z

Working copy: `~/svn/ax402-for-woocommerce`. Example `0.4.3`:

```bash
cd /path/to/ax402-woocommerce-extension
source ~/.nvm/nvm.sh && nvm use
bash bin/check-version.sh 0.4.3
npm run package

STAGE=$(mktemp -d)
unzip -q dist/ax402-for-woocommerce-0.4.3.zip -d "$STAGE"
# --delete is safe: SVN 1.7+ stores .svn at the working-copy root, not under trunk/
rsync -a --delete "$STAGE/ax402-for-woocommerce/" ~/svn/ax402-for-woocommerce/trunk/
rm -rf "$STAGE"

test -f ~/svn/ax402-for-woocommerce/trunk/ax402-for-woocommerce.php
test ! -d ~/svn/ax402-for-woocommerce/trunk/ax402-for-woocommerce

cd ~/svn/ax402-for-woocommerce
svn add --force trunk
svn status trunk | awk '/^!/{print $2}' | while IFS= read -r path; do
  [ -n "$path" ] && svn delete "$path"
done

svn cp trunk tags/0.4.3
svn status   # review: trunk + tags/0.4.3, no nested slug, assets/ unchanged unless intended

svn ci -m "Tagging version 0.4.3" --username axlabs
```

Commit **trunk and the new tag together** so the directory never sees a `Stable tag` without a matching `tags/` folder.

Directory sync can take several minutes. Confirm:

- https://wordpress.org/plugins/ax402-for-woocommerce/
- `svn info tags/X.Y.Z` shows Last Changed Author `axlabs` and a new revision

To update **plugin code** in an already-published tag: **don’t**. Bump a new SemVer, create the GitHub Release, then add a new `tags/<version>` folder.

Directory **copy** and **artwork** may update without a new version; see [Directory listing updates (no version bump)](#directory-listing-updates-no-version-bump).

### Directory listing updates (no version bump)

WordPress.org reads `Stable tag:` from `trunk/readme.txt`, then loads **the rest of the listing** from `tags/<stable>/readme.txt`. Artwork is independent of tags.

Do this **in GitHub first**, then copy into the SVN working copy (example `~/svn/ax402-for-woocommerce`). No SemVer bump.

| Change | GitHub | SVN | New version? |
|---|---|---|---|
| Icon, screenshots, banner | `wporg-assets/` (not `plugin/assets/`) | top-level `assets/` | No |
| Description, FAQ, screenshot captions, install copy | `plugin/readme.txt` | `trunk/readme.txt` **and** `tags/<stable>/readme.txt` | No |
| Plugin PHP / JS / `Version:` | as usual | new `tags/X.Y.Z` after a GitHub Release | **Yes** |

`plugin/assets/` is runtime CSS/JS shipped in the zip. Directory banners/icons/screenshots never go there.

```bash
# Artwork (from this repo)
cp wporg-assets/icon-*.png wporg-assets/banner-*.png wporg-assets/screenshot-*.png \
  ~/svn/ax402-for-woocommerce/assets/
cd ~/svn/ax402-for-woocommerce/assets
svn add --force .
svn propset svn:mime-type image/png *.png
svn ci -m "Directory icon, banner, and screenshots" --username axlabs

# Listing copy for the current Stable tag (example 0.4.3) — no PHP changes
cp plugin/readme.txt ~/svn/ax402-for-woocommerce/trunk/readme.txt
cp plugin/readme.txt ~/svn/ax402-for-woocommerce/tags/0.4.3/readme.txt
cd ~/svn/ax402-for-woocommerce
svn ci -m "Update directory readme for 0.4.3 (no version bump)" --username axlabs
```

Let the user type the SVN password in their terminal. Directory/CDN cache can take minutes to hours.

### Directory assets (filenames)

Names in SVN `assets/` (and in git `wporg-assets/`):

* `icon-128x128.png`, `icon-256x256.png`
* `screenshot-1.png` … matching `== Screenshots ==` in `readme.txt`
* `banner-772x250.png`, `banner-1544x500.png` — official **green** header. WordPress.org has **no** dark-mode banner filename; ink/cream masters live in `wporg-assets/src/` only (not SVN). Optional later: `banner-*-rtl.png` for RTL locales.

Set `svn:mime-type` `image/png` (or `image/jpeg`) so the directory does not force-download them. Handbook: [plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).

### What not to do

- Do not `svn ci` CI-only or unreleased git commits
- Do not develop by editing the SVN tree and copying back to GitHub
- Do not put WordPress.org passwords, application passwords, or API keys in git
- Do not force-overwrite plugin **code** in an existing `tags/X.Y.Z`; ship a new version
- Do not duplicate GitHub Release notes into `readme.txt` Changelog

## Agent checklist (do not skip)

When asked to “release”, “bump version”, or “cut a GitHub release”:

1. Read this file and run `bash bin/check-version.sh` first.
2. Confirm SemVer bump type with the user if ambiguous.
3. Do **not** write a second changelog in `plugin/readme.txt`. `bump-version.sh` adds a GitHub Release link; full notes belong on the GitHub Release.
4. Use `bash bin/bump-version.sh <version>` — never partial edits.
5. Commit with message `Release vX.Y.Z`.
6. Create **annotated** tag `vX.Y.Z` and push **the tag** (pushing `main` alone does not publish).
7. Do not force-push tags; do not delete published releases without explicit user approval.
8. Do not put secrets in release notes.
9. Leave `bin/repro-*.py` and `.env` out of commits (see `.gitignore`).
10. Plugin Check must be green on `main` (CI job `plugin-check`). After tagging, the Release workflow runs it again on the zip before `gh release create`.
11. GitHub is development. SVN is directory publish only — do not commit to SVN unless the user asked to publish that version to WordPress.org **and** the matching GitHub Release already exists.
12. Stage SVN locally from the packaged zip (`trunk/` + `tags/X.Y.Z`); let the user run `svn ci` in their own terminal so the WordPress.org password is never pasted into chat.

## Manual / emergency release

If Actions is unavailable:

```bash
bash bin/check-version.sh v0.2.0
npm ci && npm --prefix plugin ci
npm run package
npm run plugin-check   # needs wp-env; skip only if Actions is down and you already ran Plugin Check
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
- [docs/ucp.md](docs/ucp.md) — UCP for agents ([ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding))
- [docs/testing.md](docs/testing.md) — test commands
- [AGENTS.md](AGENTS.md) — short agent entrypoint
- [Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/) — WordPress.org SVN handbook
