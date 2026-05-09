# php-doctor

> **Status: MVP — early alpha. Not production-ready.**

Health-check CLI for **Symfony 6/7+** and **Laravel 11/12+** projects — inspired by react.doctor.
Combines AST analysis, runtime framework introspection, PHPStan integration, and a Lighthouse-style
HTML report with a global health score.

**Stack:** PHP 8.3+ · nikic/php-parser ^5 · Symfony Console 7 · Twig 3

### Supported framework matrix

| Framework | Versions tested | Detection signal | Runtime introspection |
|---|---|---|---|
| Symfony   | 6.4 LTS · 7.x · 8.x | `symfony/framework-bundle` in `composer.json` | `bin/console debug:* --format=json` (DescriptorHelper, present since 3.x) |
| Laravel   | 10 · 11 · 12 · 13   | `laravel/framework` in `composer.json`        | `php artisan route:list --json` (≥9), `about --json` (≥9), `migrate:status` text parser (all versions) |
| Generic   | n/a                 | neither of the above                          | `composer audit --format=json` only |

Detection is version-agnostic — any constraint matching the package name is supported. The matrix is pinned by a parameterized test (`ProjectDetectorTest::supportedFrameworkVersions`).

## Installation

### Via PHAR (recommended)

```bash
curl -L -o php-doctor.phar https://github.com/MakFly/php-doctor/releases/latest/download/php-doctor.phar
chmod +x php-doctor.phar
./php-doctor.phar scan /path/to/project
```

### Via Composer (dev / contribution)

```bash
git clone https://github.com/MakFly/php-doctor.git
cd php-doctor
composer install
```

## Usage

```bash
# Scan with default console output
php bin/php-doctor scan /path/to/project

# JSON output (machine-readable, stable v1 schema)
php bin/php-doctor scan /path/to/project --format=json

# HTML report (Lighthouse-style, opens in browser)
php bin/php-doctor scan /path/to/project --format=html --output=report.html

# SARIF 2.1.0 (for GitHub Code Scanning / VS Code)
php bin/php-doctor scan /path/to/project --format=sarif

# CI mode: exit 1 if score < 70
php bin/php-doctor scan /path/to/project --ci --min-score=70

# CI mode: exit 1 if any critical finding
php bin/php-doctor scan /path/to/project --ci --fail-on=critical

# List all available rules
php bin/php-doctor list-rules
```

## Rules (MVP — 8 rules)

| ID | Category | Severity | Frameworks |
|----|----------|----------|------------|
| `common.security.hardcoded-secrets` | Security | Critical | All |
| `common.hygiene.env-desync` | Hygiene | High | All |
| `common.dependencies.composer-audit` | Security | High | All |
| `symfony.security.missing-is-granted` | Security | High | Symfony |
| `symfony.architecture.orphan-route` | Architecture | Medium | Symfony |
| `laravel.perf.eloquent-n-plus-one` | Performance | High | Laravel |
| `laravel.security.mass-assignment` | Security | High | Laravel |
| `laravel.architecture.blade-business-logic` | Architecture | Medium | Laravel |

Plus **TypeSafety** category via PHPStan integration (N/A if PHPStan not installed in audited project).

## Known Limitations

- **EloquentNPlusOne** is heuristic (AST-based): false positives possible when Model method calls
  inside loops are not actual N+1 patterns. Manual review recommended.
- **Laravel config** introspection uses a `php -r` workaround (boots the app) instead of
  `config:show --json` (unavailable in Laravel 11/12). Requires a working `.env` in the project.
- **MissingIsGranted** uses PSR-4 heuristics: controllers outside `App\Controller\` namespace
  are not detected.
- **TypeSafety (PHPStan)** is N/A (score not counted) when `vendor/bin/phpstan` is absent from
  the audited project. PHPStan must be installed and `composer install` run first.
- PHPStan detection relies solely on the presence of `vendor/bin/phpstan` binary.

## Optional Dependencies

### PHPStan (in the audited project)

If `vendor/bin/phpstan` is present in the project being audited, php-doctor invokes it
automatically and aggregates its findings under **Type safety**. If absent, that category
is marked **N/A** and does not affect the global score.

## Contributing

```bash
# Run tests
composer test
# or directly:
vendor/bin/phpunit

# Build PHAR
composer build
# or:
vendor/bin/box compile -v

# Static analysis (PHPStan level 6)
vendor/bin/phpstan analyse --memory-limit=512M
```

PRs welcome. Please add or update tests for any new rule.

## License

MIT — see [LICENSE](LICENSE).

Copyright (c) 2026 Kevin (MakFly) — https://github.com/MakFly/php-doctor
