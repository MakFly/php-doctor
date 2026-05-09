# php-doctor

> **Status: WIP — MVP en cours**

Health-check CLI for **Symfony 6/7+** and **Laravel 11/12+** projects.
Combines AST analysis, runtime introspection, PHPStan integration, and a Lighthouse-style HTML report.

**Stack:** PHP 8.3 · nikic/php-parser 5 · Symfony Console 7 · Twig 3

## Install

```bash
composer install
```

## Usage

```bash
php bin/php-doctor list
php bin/php-doctor scan /path/to/project
php bin/php-doctor scan /path/to/project --format=json
php bin/php-doctor list-rules
```

## Optional dependencies

### PHPStan

PHPStan est optionnel. Si présent dans le projet audité (`vendor/bin/phpstan`),
php-doctor l'invoque automatiquement et agrège ses findings dans la catégorie
**Type safety**. Si absent, cette catégorie est marquée **N/A** (non évaluée)
et n'impacte pas le score global.

> **Note:** La détection repose uniquement sur la présence du binaire
> `vendor/bin/phpstan`. Une entrée dans `composer.json` sans `composer install`
> préalable n'est pas suffisante.
