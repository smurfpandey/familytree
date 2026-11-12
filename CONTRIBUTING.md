# Contributing to FamilyTree

Thanks for helping. FamilyTree is plain PHP, vanilla JavaScript and D3, with no
build step, so you can start in a minute.

## Set up a dev environment

You need [mise](https://mise.jdx.dev) and git. The checked-in `mise.toml` pins
the toolchains (PHP, Node and Python — see it for the current versions), and
Composer comes with the PHP build.

1. Install mise and hook it into your shell (the installer prints the exact
   lines for your shell; mise must be active for the `php`/`node`/`python`
   commands below to resolve):

   ```bash
   curl https://mise.run | sh
   echo 'eval "$(~/.local/bin/mise activate bash)"' >> ~/.bashrc  # zsh/fish: your rc, or see mise docs
   ```

2. In the repo root, install the toolchains:

   ```bash
   mise install
   ```

   The first run builds PHP from source, so it takes a few minutes. mise also
   installs the system libraries PHP needs (gd, curl, zip, …) by itself on
   macOS, Debian/Ubuntu and Fedora. On Alpine, `apk add` the build toolchain
   (`gcc`, `make`, `autoconf`, `re2c`, `bison`, `pkgconf`) and the `-dev`
   packages for openssl, curl, libpng, libjpeg-turbo, libwebp, freetype,
   libzip, sqlite, oniguruma, gettext, icu first.

3. Check it works:

   ```bash
   mise run lint && mise run test
   ```

## Run the app locally

```bash
mise run dev
# open http://127.0.0.1:8080/install/ and fill in your family details
```

The installer creates the SQLite file and tables and your owner account, then
sends you to the login page. It locks itself once a family exists. Family data
lives in `public_html/storage/`; delete `storage/*.sqlite` to start from scratch.
There is no `.env` file: settings come from defaults or environment variables
(see `docker-compose.yml`).

Other ways to run it:

- **Docker**: `docker compose up -d --build` (uses `Containerfile`), then
  http://localhost:8088/install/ (fill in your family details).
- **Desktop app**: `mise run desktop:install`, then `mise run desktop:start`.
  While unpackaged it uses the PHP mise installed.
- **Without mise**: any PHP 8.1+ works —
  `php -S 127.0.0.1:8080 -t public_html tools/router.php`.

Your PHP needs `pdo_sqlite`, `mbstring` and `openssl`; `curl` and `gd` for maps,
Sheets, Airtable and share cards.

## Tests and tasks

`mise run check` runs everything CI runs, in order: lint, unit tests, smoke
(SQLite install in a temp dir, serve it, check the main pages). GitHub Actions
runs the same on every push and pull request. `mise tasks` lists the rest —
`mise run schema` regenerates the SQLite schema after you edit
`public_html/install/schema.sql`, and `mise run emblems` re-rasterises the
emblem PNGs (needs `npx playwright install chromium`, once).

## Ground rules

- Family data goes through `includes/store.php` (`FamilyStore`). Never query the
  `family_*` tables directly, or trees that live in a Google Sheet or someone's own
  database will break.
- `public_html/install/schema.sql` is the schema of record. After changing it, run
  `mise run schema` (or `python tools/make-sqlite-schema.py`) and add an upgrade
  file under `public_html/install/upgrades/` for existing installs.
- Privacy is a feature: anything shown to the public must pass through
  `applyPublicPrivacy()`.
- Keep pull requests small and say how you tested them.

## Good first contributions

- A new data home (for example Notion, Baserow, NocoDB, Supabase REST).
- GEDCOM import.
- Translations: Hindi, Maithili, Nepali, Bengali.
- Trying the Google Sheets and Airtable homes with real accounts and reporting what breaks.

## Reporting a security issue

Please do not open a public issue. Email the maintainer listed on the GitHub
profile of this repository instead.
