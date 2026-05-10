# php-doctor

> **Health-check & quality audit CLI for Symfony 6/7/8 and Laravel 10/11/12/13 projects.**
> Inspired by [react.doctor](https://www.react.doctor) — gives your PHP project a Lighthouse-style scored report you actually want to read.

[![CI](https://github.com/MakFly/php-doctor/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/MakFly/php-doctor/actions/workflows/ci.yml)
[![Compatibility](https://github.com/MakFly/php-doctor/actions/workflows/compat-matrix.yml/badge.svg?branch=main)](https://github.com/MakFly/php-doctor/actions/workflows/compat-matrix.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb3.svg)
![PHPStan level 6](https://img.shields.io/badge/PHPStan-level%206-2a4365.svg)
![Release: v0.1.0](https://img.shields.io/badge/release-v0.1.0-blue.svg)

`php-doctor` scans a PHP project, cross-references its **AST** with the **framework's runtime introspection** (`bin/console debug:*`, `php artisan route:list`, `composer audit`), optionally consumes **PHPStan**'s output, and emits a **scored health report** in Console / JSON / HTML / SARIF.

It is **not another linter**. It is an **orchestrator** that aggregates static analysis, framework-aware checks, and dependency security into a single number you can ship to CI or display in a browser.

---

## Why php-doctor

| Tool | Scope | What it tells you |
|---|---|---|
| **PHPStan / Larastan** | Type-safety static analysis | "This call will crash" |
| **Rector** | Automated refactoring | "Apply this transform" |
| **Symfony Insight** | SaaS quality scoring | "Pay us monthly" |
| **php-doctor** | **Project health audit** | **"Your project scores 72/100 — here's why"** |

`php-doctor` is closest in spirit to **Lighthouse** for the web: a single command, an opinionated scored report, with drill-down explanations. It does **not** compete with PHPStan — it **uses** PHPStan when available and folds its findings into a `Type safety` category.

---

## Quick start

```bash
# Install via PHAR (recommended)
curl -L -o php-doctor.phar https://github.com/MakFly/php-doctor/releases/latest/download/php-doctor.phar
chmod +x php-doctor.phar

# Scan any Symfony / Laravel / generic PHP project
./php-doctor.phar scan /path/to/project

# Generate the Lighthouse-style HTML report
./php-doctor.phar scan /path/to/project --format=html --output=report.html

# Use it in CI — exit non-zero below threshold
./php-doctor.phar scan . --ci --min-score=70
```

---

## Features

### 🩺 Framework-aware analysis
Detects **Symfony 6.4 / 7.x / 8.x** and **Laravel 10 / 11 / 12 / 13** automatically from `composer.json`, then runs the right pack of rules.

### 🌐 Runtime introspection (the moat)
Goes beyond AST by parsing **what your framework actually exposes at runtime**:
- Symfony: `debug:router`, `debug:container`, `debug:event-dispatcher`, `debug:config` (all via `--format=json`).
- Laravel: `route:list --json`, `about --json`, `migrate:status` (text parser), `php -r` config dump.
- Composer: `composer audit --format=json` for CVE detection.

### 📊 Lighthouse-style scoring
Every finding has a severity weight; categories are scored 0–100 and aggregated into a global score. Reports include a SVG dial, per-category cards, and per-finding drill-down.

### 🔌 PHPStan integration (optional but seamless)
If `vendor/bin/phpstan` exists in the audited project, it is invoked, its JSON output is parsed, and each message becomes a finding under the **Type safety** category. **Absent → category is N/A** (does not penalize the score).

### 🤖 CI-ready
Native flags `--ci`, `--min-score=N`, `--fail-on=<severity>`. Reports in **JSON v1** (stable schema) and **SARIF 2.1.0** (works out of the box with GitHub Code Scanning).

### 📦 Single-binary distribution
Built with [Box](https://github.com/box-project/box) into a ~2.5 MB PHAR. No global Composer install, no version conflicts with the audited project.

---

## Supported framework matrix

| Framework | Versions | Detection signal | Runtime introspection |
|-----------|----------|------------------|------------------------|
| **Symfony**   | 6.4 LTS · 7.x · 8.x | `symfony/framework-bundle` in `composer.json` | `bin/console debug:* --format=json` |
| **Laravel**   | 10 · 11 · 12 · 13 | `laravel/framework` in `composer.json` | `php artisan route:list --json`, `about --json`, `migrate:status` text parser |
| **Generic PHP** | n/a | neither of the above | `composer audit --format=json` only |

Detection is **version-agnostic** — any constraint matching the package name is supported. The matrix is enforced by:
- A parameterized unit test ([`ProjectDetectorTest::supportedFrameworkVersions`](tests/Core/Project/ProjectDetectorTest.php)).
- A GitHub Actions [compatibility workflow](.github/workflows/compat-matrix.yml) that creates a real skeleton project for each version on every push and runs the freshly-built PHAR against it.

---

## Usage

```bash
# Default human-readable console output with ANSI colors
php bin/php-doctor scan /path/to/project

# Machine-readable JSON (stable v1 schema)
php bin/php-doctor scan /path/to/project --format=json
php bin/php-doctor scan /path/to/project --format=json --output=report.json

# Self-contained Lighthouse-style HTML
php bin/php-doctor scan /path/to/project --format=html --output=report.html

# SARIF 2.1.0 — direct upload to GitHub Code Scanning
php bin/php-doctor scan /path/to/project --format=sarif --output=results.sarif

# CI mode — exit 1 if global score < 70
php bin/php-doctor scan /path/to/project --ci --min-score=70

# CI mode — exit 1 on any high-or-above finding
php bin/php-doctor scan /path/to/project --ci --fail-on=high

# Inventory rules
php bin/php-doctor list-rules
```

---

## Built-in rules (MVP)

| ID | Category | Severity | Frameworks |
|----|----------|----------|------------|
| `common.security.hardcoded-secrets` | Security | Critical | All |
| `common.hygiene.env-desync` | Hygiene | Medium | All |
| `common.dependencies.composer-audit` | Dependencies | High (variable per advisory) | All |
| `symfony.security.missing-is-granted` | Security | High | Symfony |
| `symfony.architecture.orphan-route` | Architecture | Low | Symfony |
| `laravel.perf.eloquent-n-plus-one` | Performance | High | Laravel |
| `laravel.security.mass-assignment` | Security | High | Laravel |
| `laravel.architecture.blade-business-logic` | Architecture | Medium | Laravel |
| `phpstan.*` (1 per identifier) | Type safety | Mapped from PHPStan severity | Any (when PHPStan installed) |

---

## Use cases

### As a PR check
```yaml
# .github/workflows/quality.yml
- run: ./php-doctor.phar scan . --ci --min-score=80
```

### As a SARIF feed for GitHub Code Scanning
```yaml
- run: ./php-doctor.phar scan . --format=sarif --output=results.sarif
- uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

### As a local pre-commit gate
```bash
./php-doctor.phar scan . --ci --fail-on=critical
```

### As a quarterly architecture audit
```bash
./php-doctor.phar scan . --format=html --output=audit-q1.html
```

---

## FAQ

### Is this a replacement for PHPStan / Larastan?
**No.** PHPStan/Larastan answer "will my code crash?". `php-doctor` answers "is my project healthy?". They run together — `php-doctor` invokes PHPStan if installed and aggregates its findings into the Type safety category.

### How is the score computed?
Each finding has a severity weight (Critical=20, High=10, Medium=5, Low=2, Info=0). For each category: `score = clamp(100 - 0.5 × Σ weights, 0, 100)`. Global score = arithmetic mean across the 6 categories. Categories with zero findings stay at 100. PHPStan absent → Type safety is **N/A** (excluded from the mean).

### Does it execute my application code?
**No.** `php-doctor` only invokes the framework's official introspection commands (`bin/console debug:*`, `php artisan route:list`). The single exception is the Laravel config workaround (`php -r` that boots the app), required because `config:show --json` does not exist in any Laravel 10–13. That command runs in the project root with the project's own `.env` — never against production.

### Can I add custom rules?
The MVP ships rules in-tree (no plugin loader). A plugin system is on the roadmap once the core stabilizes. For now, fork or open a PR.

### Does it work on Windows?
Untested. The PHAR should run anywhere PHP runs, but the runtime collectors shell out to `bin/console` and `php artisan` — paths and quoting are POSIX-first. Reports of Windows runs welcome.

### How fast is it?
On the embedded `symfony-min` test fixture: `357 ms ± 7 ms` (hyperfine). On a 500-file real project: target < 10 s, not yet measured publicly.

---

## Known limitations

- **EloquentNPlusOne** is heuristic (AST-based). False positives possible when the eager loading is not on the same expression as the `foreach`.
- **Laravel config introspection** uses a `php -r` workaround that boots the app — needs a usable `.env`.
- **MissingIsGranted** assumes PSR-4 (`App\Controller\…` → `src/Controller/…`). Controllers in non-standard locations are silently skipped.
- **Type safety** is `N/A` (not 0) when PHPStan is absent — the audited project must `composer require --dev phpstan/phpstan` and `composer install`.
- **Symfony 8 / Laravel 13** matrix entries are *forward-looking* — the CI matrix gracefully skips entries whose skeleton is not yet released on Packagist.

---

## Architecture (high-level)

```
ScanCommand
   ├── ProjectDetector     → FrameworkContext (Symfony | Laravel | Generic)
   ├── AST pipeline         → ParserPool · FileWalker · AstSnapshot · NameResolver
   ├── Runtime pipeline     → ProcessExecutor · 12 collectors · SnapshotBuilder
   ├── PhpStanRunner        → consumes vendor/bin/phpstan output (if present)
   ├── RuleRegistry         → 8 in-tree rules + PhpStan aggregator
   └── Reporter             → Console · JSON · HTML · SARIF
```

Full blueprint: [`docs/plans/PLAN-php-doctor-mvp.md`](docs/plans/PLAN-php-doctor-mvp.md).

---

## Contributing

```bash
git clone https://github.com/MakFly/php-doctor.git
cd php-doctor
composer install

composer test                  # 170 tests
composer build                 # PHAR → build/php-doctor.phar
vendor/bin/phpstan analyse     # level 6, 0 errors required
```

Conventions :
- PHP 8.3 minimum, strict types everywhere, `final readonly` by default.
- Each new rule = 1 file in `src/Rules/.../*.php` + 1 test file with **at least** 1 positive and 1 negative case.
- No new framework-aware rule without updating the [supported matrix](#supported-framework-matrix) and the compat workflow.

---

## Credits & license

- Inspired by [react.doctor](https://www.react.doctor).
- Built on [nikic/php-parser](https://github.com/nikic/PHP-Parser), [Symfony Console](https://symfony.com/components/Console), [Twig](https://twig.symfony.com), and [Box](https://github.com/box-project/box).

MIT — see [LICENSE](LICENSE). © 2026 Kevin (MakFly).

---

**Tags:** php · symfony · laravel · static analysis · code quality · health check · audit · phpstan · lighthouse · sarif · cli tool · php-cli · symfony-bundle · laravel-package · ast · composer-audit
