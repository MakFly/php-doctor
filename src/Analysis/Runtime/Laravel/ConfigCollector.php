<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime\Laravel;

use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;

/**
 * Collects the full Laravel config tree by bootstrapping the app via php -r.
 *
 * IMPORTANT — security and environment constraints:
 *   - This command boots the full Laravel application (reads .env, resolves
 *     service providers, etc.). It MUST only run in a local/dev environment.
 *   - Never call this collector when APP_ENV=production or on a remote host.
 *   - The 30-second timeout is intentionally short to avoid hanging on a
 *     misconfigured app. A typical local boot takes < 3 s.
 *
 * Workaround rationale: `php artisan config:show` does not accept --json, and
 * `php artisan tinker` is not always available. The php -r approach is the
 * only reliable way to dump config()->all() as JSON without modifying the
 * project (validated in Phase 0 research).
 */
final class ConfigCollector
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag      $bag,
    ) {}

    /**
     * @return array<mixed>|null  Decoded config tree, or null if unavailable.
     */
    public function collect(FrameworkContext $ctx): ?array
    {
        if ($ctx->framework !== Framework::Laravel || $ctx->consoleBinary === null) {
            return null;
        }

        $root = $ctx->rootPath;

        // Build the inline PHP as a single expression (no shell interpolation).
        // Each element is a separate argument to `php -r` — we pass the whole
        // script as one string so that no shell quoting issues arise.
        $script = sprintf(
            "require '%s/vendor/autoload.php';" .
            "\$app=require '%s/bootstrap/app.php';" .
            "\$app->make('Illuminate\\\\Contracts\\\\Console\\\\Kernel')->bootstrap();" .
            "echo json_encode(config()->all());",
            $root,
            $root,
        );

        $result = $this->exec->run(
            ['php', '-r', $script],
            $root,
        );

        if (!$result->isSuccessful) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.laravel.config-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Laravel config introspection failed — php -r bootstrap returned non-zero exit.',
                file:     null,
                line:     null,
                fixHint:  $result->stderr ?: null,
            ));
            return null;
        }

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.laravel.config-unavailable',
                severity: Severity::Info,
                category: Category::Hygiene,
                message:  'Laravel config bootstrap returned non-JSON output.',
                file:     null,
                line:     null,
                fixHint:  substr($result->stdout, 0, 200) ?: null,
            ));
            return null;
        }

        return $decoded;
    }
}
