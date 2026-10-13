# Working on Docket

Docket (`tey/docket`) is a Laravel package in early development. It has a service provider and empty config, with no usable features or release yet. Read `README.md` and the issue before changing code.

## Setup and Gates

Run `composer install`. This is a package, not a Laravel application: there is no root `artisan`, frontend build or database setup. Tests boot Laravel through Orchestra Testbench.

Run every gate before opening or updating a pull request:

- `composer validate --strict`: package metadata.
- `composer test`: the Pest suite.
- `composer analyse`: PHPStan with Larastan, for both `src` and `tests`.
- `composer lint`: Pint's Laravel preset (`composer format` fixes style).
- `composer check-docs`: every local document indexed by `llms.txt` exists.

`composer test-coverage` is optional and needs a coverage driver.

CI runs these gates on pull requests and pushes to `main`. The test matrix mirrors Mod: Ubuntu and Windows, PHP 8.3 and 8.4, Laravel 12 and 13, lowest and stable dependencies; PHP 8.5 runs both Laravel versions with stable dependencies only. All 20 matrix legs and every other check must be green on the PR's final commit, including Windows.

## Conventions

- Follow the [Tey Labs package conventions](https://github.com/teylabs/.github/blob/main/CONVENTIONS.md) and the surrounding code.
- Use the `Tey\Docket` namespace. Reuse Laravel's behaviour and conventions.
- Mark public API `@api` and implementation details `@internal`.
- Test observable behaviour; regression tests must fail without the fix.
- Keep the README honest about what exists. Do not document unreleased APIs as usable features.
- Keep `llms.txt` accurate when its indexed documents change.
- Hand-write user-facing changes under `## Unreleased` in `CHANGELOG.md`, with the issue number. Repository housekeeping needs no entry.
- Use Canadian spelling in prose ("behaviour", "colour", "favour"; "-ize" is fine). Keep code identifiers and quoted framework names unchanged.
- Shared contributing and security policies live in `teylabs/.github`; do not duplicate them here.

## Git and Pull Requests

- Branch from the latest `origin/main`; never commit or push to `main`.
- Commit as `Jasper Tey <jasper@teylabs.com>` with short, imperative, sentence-case subjects.
- **No attribution or trailers:** no `Co-authored-by`, agent thread IDs, "Generated with" lines or agent session links in commits, PR titles or PR bodies.
- Check `git log origin/main..HEAD --format=%B` before pushing and remove any attribution.
- Open pull requests as **drafts**. The title reads like a commit subject; the body explains the change, lists every gate and the CI matrix result, and includes `Closes #N`.
- Watch CI to completion and fix failures before handing off the PR. Never merge, tag or release; maintainers do that.
- This repository is public. Never include secrets, local absolute paths, private application or client names, or internal notes.
