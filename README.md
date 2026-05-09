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
