# Security Policy

## Supported versions

Only the latest minor version receives security fixes. The MVP series is `0.1.x`.

| Version | Supported          |
|---------|--------------------|
| 0.1.x   | :white_check_mark: |
| < 0.1   | :x:                |

## Reporting a vulnerability

**Please do NOT open a public GitHub issue for security reports.**

Use one of:

1. **GitHub Security Advisories** (preferred):
   <https://github.com/dev-toolings/php-doctor/security/advisories/new>
2. Email: open a draft advisory and add the reporter via GitHub UI.

You can expect:
- Acknowledgement within **5 business days**.
- A triage decision within **10 business days**.
- A fix release for confirmed vulnerabilities within **30 days** (Critical/High) or **60 days** (Medium/Low).

## Scope

In scope:
- The `php-doctor` CLI binary and its PHAR distribution.
- Code under `src/` and `bin/`.
- The default `templates/report.html.twig` (HTML report XSS / injection).
- Workflow files under `.github/workflows/` (supply-chain).

Out of scope:
- Vulnerabilities in **audited projects** (php-doctor only reads your code; it cannot remediate it).
- Third-party dependencies — please report those upstream and we will bump the lock when fixes land.
- Findings reported by `php-doctor` itself on a target project — those are normal output, not vulnerabilities.

## Hardening already applied

- No code execution against the audited project beyond official framework introspection commands (`bin/console debug:*`, `php artisan route:list`, `composer audit`).
- Path injection mitigations: `Laravel\ConfigCollector` does not interpolate user-controlled paths into PHP source code passed to `php -r`.
- HTML XSS mitigations: JSON embedded in `<script type="application/json">` is encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- SARIF output redacts paths outside the audited project root.
- GitHub Actions workflows use top-level least-privilege `permissions: { contents: read }`.
- Third-party Actions are pinned to full SHA.

## Disclosure

Confirmed vulnerabilities are disclosed via GitHub Security Advisory + a CHANGELOG entry under `### Security`. CVE IDs are requested via GitHub when the impact warrants it.
