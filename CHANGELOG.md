# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-05-09

### Added
- Initial MVP release.
- Project detection: Symfony 6/7+, Laravel 11/12+, generic.
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
