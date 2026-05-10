# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- Fix RCE via path injection in `Laravel\ConfigCollector` — paths are no longer interpolated into the `php -r` script (use cwd + relative paths).
- Fix HTML report XSS via finding messages — JSON embed now escapes `<`, `>`, `&`, `'`, `"` to `\u00XX` via `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- Force `APP_ENV=local` and `APP_DEBUG=0` for all Laravel runtime collectors (ConfigCollector, RouteCollector, AboutCollector, MigrationCollector).
- Workflows now pin top-level `permissions: { contents: read }`; release workflow keeps `contents: write` scoped to the publish job only.
- Pinned third-party GitHub Actions to full SHA (`shivammathur/setup-php`, `softprops/action-gh-release`) to prevent supply-chain tag mutation.

### Fixed
- `MissingIsGrantedRule` no longer flags methods of classes carrying a class-level `#[IsGranted]` / `#[Security]` attribute, and now finds `$this->denyAccessUnlessGranted()` calls anywhere in the method body (was top-level only).
- `AstAnalyzer` now emits parse-error findings when the parser returns a partial AST with errors (previously only emitted when AST was null).
- `ProcessExecutorInterface::run()` accepts an optional 4th `array $env` parameter for injecting environment variables into spawned processes.

### Improved
- `EloquentNPlusOneRule` now distinguishes Eloquent relations from scalar columns via a curated blacklist of ~60 common column names + 17 suffix heuristics. Also tracks eager-loaded relations across simple variable assignments (`$posts = Post::with('author')->get(); foreach ($posts …)` no longer triggers a false positive).
- `MassAssignmentRule` resolves the target Model in the AST cache: downgrades to Low when `$fillable` is defined, upgrades to Critical when `$guarded = []`, keeps High when neither is set.
- `HardcodedSecretsRule` expanded detection to ArrayItem, ClassConst, Property and assignment patterns; added Stripe (`sk_live_…`), Slack (`xoxb-…`), Google API (`AIza…`) token patterns.
- HTML report is now fully self-contained: Tailwind CDN dependency removed, replaced by embedded minimal CSS (~200 lines). Report renders offline with no external requests.
- SARIF reporter now redacts paths outside the project root (was leaking absolute paths in `artifactLocation.uri`). Out-of-tree files are emitted as `basename` only, with a `properties.outOfTreePath: true` marker.

### Changed
- Documented and tested the full supported framework matrix:
  Symfony 6.4 · 7.x · 8.x and Laravel 10 · 11 · 12 · 13.
  No code change required — the detector is version-agnostic by design;
  this release adds an explicit parameterized test that pins the matrix.

## [0.1.0] - 2026-05-09

### Added
- Initial MVP release.
- Project detection: Symfony 6/7/8, Laravel 10/11/12/13, generic.
- AST pipeline (nikic/php-parser ^5) with ParserPool reuse and AstSnapshot memoization.
- Runtime pipeline:
  - Symfony: `debug:router`, `debug:container`, `debug:event-dispatcher`, kernel bundles.
  - Laravel: `route:list`, `about`, config via `php -r` workaround, `migrate:status`.
  - Common: `composer audit --format=json --locked`.
- PHPStan integration: consume JSON output, aggregate findings as TypeSafety category (N/A if PHPStan absent).
- 8 MVP rules:
  - `common.security.hardcoded-secrets` — AWS keys, GitHub PATs, JWT tokens, define/putenv secrets.
  - `common.hygiene.env-desync` — `.env` keys not declared in `.env.example`.
  - `common.dependencies.composer-audit` — known CVEs from `composer audit`.
  - `symfony.security.missing-is-granted` — controllers missing `#[IsGranted]` or `denyAccessUnlessGranted`.
  - `symfony.architecture.orphan-route` — routes declared but never referenced in templates/forms.
  - `laravel.perf.eloquent-n-plus-one` — N+1 heuristic: Model method calls inside loops.
  - `laravel.security.mass-assignment` — missing `$fillable`/`$guarded` on Eloquent models.
  - `laravel.architecture.blade-business-logic` — complex PHP logic inside Blade templates.
- Reporters: Console (SymfonyStyle, color-coded), JSON (stable v1 schema), HTML (Twig + Tailwind CDN, Lighthouse-style score gauge), SARIF 2.1.0.
- CI mode: `--ci`, `--min-score`, `--fail-on` flags with coherent exit codes.
- `list-rules` command listing all available rules with category and severity.
- PHAR distribution via Box (single-file, no dependencies needed on target).
- GitHub Actions: `ci.yml` (PHP 8.3/8.4 matrix) and `release.yml` (PHAR build on tag `v*`).

[Unreleased]: https://github.com/MakFly/php-doctor/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/MakFly/php-doctor/releases/tag/v0.1.0
