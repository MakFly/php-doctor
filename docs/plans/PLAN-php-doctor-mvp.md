# PLAN — php-doctor MVP

> Audit-de-santé pour projets **Symfony 6/7+** et **Laravel 11/12+**, inspiré de react.doctor.
> Positionnement : **orchestrateur** qui englobe PHPStan + un pipeline runtime (artefacts framework) + un rapport HTML scoré façon Lighthouse. PHPStan = dépendance assumée, jamais concurrent.

---

## Phase 0 — Documentation Discovery (CONSOLIDÉE)

### Allowed APIs (validées en session, ne PAS inventer au-delà)

#### nikic/php-parser `^5`
- Source : `github.com/nikic/PHP-Parser` doc/ folder.
- Composer : `nikic/php-parser:^5` (PHP ≥ 7.4 runtime, parse PHP 7.0–8.4).
- API autorisée :
  - `(new ParserFactory())->createForNewestSupportedVersion(): Parser`
  - `$parser->parse(string $code): ?array<Node>`
  - `new NodeTraverser()` + `addVisitor(NodeVisitor)` + `traverse(array $stmts): array`
  - `class extends NodeVisitorAbstract { enterNode(Node), leaveNode(Node) }`
  - Constantes retour : `NodeVisitor::DONT_TRAVERSE_CHILDREN`, `STOP_TRAVERSAL`, `REMOVE_NODE`
  - Position : `$node->getStartLine()`, `getEndLine()`, `getAttribute('startFilePos')`
  - Name resolution : `new \PhpParser\NodeVisitor\NameResolver()` AVANT les autres visitors. Option `replaceNodes: false` → ajoute `resolvedName` en attribut sans muter (recommandé pour analyse-only).
  - Erreurs syntaxe : `ErrorHandler\Collecting` pour récupérer un AST partiel.
- Perf : **réutiliser** parser/traverser entre fichiers. Utiliser `composer/xdebug-handler` pour relancer sans Xdebug.
- Anti-patterns : pas de parent par défaut → `ParentConnectingVisitor` si besoin.

#### PHPStan (consommé en sous-processus)
- CLI : `vendor/bin/phpstan analyse <paths> --error-format=json --no-progress [--level=N]`
- Schéma JSON officiel (depuis `phpstan-src 2.2.x` `JsonErrorFormatter`) :
  ```json
  {
    "totals": { "errors": 2, "file_errors": 5 },
    "files": {
      "/abs/path/Foo.php": {
        "errors": 2,
        "messages": [
          { "message": "...", "line": 12, "ignorable": true,
            "identifier": "missingType.parameter", "tip": "..." }
        ]
      }
    },
    "errors": ["global-or-config-level errors"]
  }
  ```
- Détection : 1) `vendor/bin/phpstan` exists → 2) sinon `composer.json` require/require-dev contient `phpstan/phpstan`.
- Sans `phpstan.neon` : exiger `--paths` + `--level`. Fallback recommandé : `--level=5`.
- Exit code : 0 = OK, 1 = errors found, autre = fatal (parser stderr brut).

#### Symfony introspection (`bin/console …`)
Toutes les commandes ci-dessous supportent `--format=json` (via `DescriptorHelper`, présent depuis Symfony 3.x — non-bloquant pour 6/7+) :
- `debug:router --format=json` → objet keyé par nom de route ; champs `path, pathRegex, host, scheme, method, class, defaults, requirements, options, condition`.
- `debug:container --format=json` → `{ definitions, aliases, services }`. Avec `--parameters` pour les paramètres DI, `--parameter=kernel.bundles` pour la liste des bundles.
- `debug:event-dispatcher --format=json` → keyé par event, listeners avec `type, name, class, static, priority`.
- `debug:config <bundle> --format=json` → config compilée d'une extension.

#### Laravel introspection (`php artisan …`)
- `route:list --json` ✅ — `domain, method, uri, name, action, middleware[]`.
- `about --json` ✅ — sections `environment, cache, drivers, storage`.
- `config:show <key>` ❌ pas de `--json`. **Workaround validé** : `php artisan tinker --execute="echo json_encode(config('app'));"` ou lire le cache compilé.
- `migrate:status` ❌ pas de `--json`. Options dispo : `--pending, --database, --path`. **Workaround** : parser la table texte (regex sur les lignes) OU `php -r "..."` qui exécute `Artisan::call` et sérialise.

#### symfony/console `^7`
- API autorisée :
  - `new Application(string $name, string $version)` + `$app->add(new XCommand())` + `$app->run()`
  - Attribut `#[AsCommand(name: '…', description: '…')]` sur classe étendant `Command`
  - `Command::SUCCESS`, `Command::FAILURE`, `Command::INVALID` comme codes retour
  - `SymfonyStyle($input, $output)` : `success()`, `error()`, `warning()`, `note()`, `progressStart(int)`, `progressAdvance()`, `progressFinish()`, `progressIterate(iterable)`
- Doc : https://symfony.com/doc/current/components/console.html

#### humbug/box `^4` (PHAR)
- Commande : `box compile` (lit `box.json` ou `box.json.dist`).
- Champs requis : `main`, `output`, `finder`, `stub: true`, `check-requirements: true`.
- `check-requirements: true` (défaut) embarque un check PHP-version + extensions au démarrage du PHAR.

### Anti-patterns (interdits)
- ❌ Inventer une commande Laravel `migrate:status --json` ou `config:show --json` (n'existent pas).
- ❌ Réinstancier `ParserFactory` par fichier.
- ❌ Mutating l'AST avec `NameResolver` quand on ne fait que lire (`replaceNodes: false`).
- ❌ Lire stdout PHPStan sans vérifier l'exit code d'abord.
- ❌ Exécuter du code utilisateur autrement qu'à travers les commandes d'introspection officielles.

---

## Phase 1 — Bootstrap projet & squelette

### À implémenter
1. `composer.json` minimal :
   - `require`: `php: ^8.3`, `nikic/php-parser: ^5`, `symfony/console: ^7`, `symfony/process: ^7`, `symfony/finder: ^7`, `composer/xdebug-handler: ^3`, `twig/twig: ^3`
   - `require-dev`: `humbug/box: ^4`, `phpunit/phpunit: ^11`
   - `autoload` PSR-4 : `"PhpDoctor\\": "src/"`
   - `bin`: `["bin/php-doctor"]`
2. Arbo `src/` selon le découpage validé en conversation (Cli/, Core/, Analysis/, Rules/, Reporting/).
3. `bin/php-doctor` : entrypoint conforme au snippet Phase 0 (Topic A), ajoute `ScanCommand` et `ListRulesCommand` (stubs).
4. `phpunit.xml.dist` minimal pointant `tests/`.
5. `.gitignore` : `vendor/`, `build/`, `.phpunit.cache/`, `.ai/reports/`, `.ai/state/`.

### Vérification
- [ ] `composer install` réussit.
- [ ] `php bin/php-doctor list` affiche `scan` et `list-rules` (mêmes si stubs).
- [ ] `php bin/php-doctor scan --help` affiche la description.

### Anti-pattern guards
- Ne PAS ajouter `phpstan/phpstan` en `require` — c'est une dépendance **du projet audité**, pas de php-doctor.
- Ne PAS commiter de `box.json` à cette phase (Phase 10).

---

## Phase 2 — Core domain : Finding, Score, Rule

### À implémenter (tous readonly où c'est possible)
1. `src/Core/Rule/Severity.php` — `enum Severity: string { Critical='critical'; High='high'; Medium='medium'; Low='low'; Info='info'; }`. Méthode `weight(): int` (Critical=20, High=10, Medium=5, Low=2, Info=0).
2. `src/Core/Rule/Category.php` — `enum Category: string { Security; Performance; Architecture; TypeSafety; Hygiene; Dependencies; }`.
3. `src/Core/Finding/Finding.php` — `final readonly class` avec : `string $ruleId, Severity $severity, Category $category, string $message, ?string $file, ?int $line, ?string $fixHint, ?string $docUrl`.
4. `src/Core/Finding/FindingBag.php` — collection mutable (add only). Méthode `all(): iterable<Finding>`, `byCategory(): array<string, Finding[]>`.
5. `src/Core/Finding/Score.php` — `static fromBag(FindingBag): self`. Score 0–100 par catégorie + global. Formule : `100 - sum(severity.weight()) * coefficient` clampé à `[0,100]`. Coefficient documenté dans le code.
6. `src/Core/Rule/Rule.php` (interface) :
   ```php
   interface Rule {
       public function id(): string;                              // ex 'laravel.eloquent.n-plus-one'
       public function category(): Category;
       public function severity(): Severity;
       public function appliesTo(FrameworkContext $ctx): bool;
       public function analyze(AnalysisInput $input): iterable;   // yield Finding
   }
   ```
7. `src/Core/Rule/RuleRegistry.php` — collection ordonnée, `register(Rule)`, `enabledFor(FrameworkContext): iterable<Rule>` (filtre via `appliesTo`).
8. `src/Core/AnalysisInput.php` — DTO readonly passé à `Rule::analyze()` : `FrameworkContext $ctx, ?AstSnapshot $ast, ?RuntimeSnapshot $runtime`. Définir `AstSnapshot` (chemins + AST en cache) et `RuntimeSnapshot` (routes, services, etc.) en stubs vides — remplis aux Phases 4 et 5.

### Vérification
- [ ] Tests unitaires sur `Score::fromBag` : bag vide → 100 ; 1 Critical → ≤ 80 ; clamp à 0 vérifié.
- [ ] Test que `RuleRegistry::enabledFor()` filtre bien selon `appliesTo`.
- [ ] `Finding` est immuable (pas de setter — readonly enforce).

### Anti-pattern guards
- Ne PAS ajouter de logique d'analyse ici (pas de parser, pas de FS).
- Ne PAS rendre les enums `string-backed` autrement que via `: string`.

---

## Phase 3 — Détection projet & FrameworkContext

### À implémenter
1. `src/Core/Project/Framework.php` — `enum Framework { case Symfony; case Laravel; case Generic; }`.
2. `src/Core/Project/ProjectDetector.php` — `detect(string $path): FrameworkContext`. Logique :
   - Lire `$path/composer.json` (`json_decode` stricte). Erreur claire si absent.
   - Si `require.laravel/framework` présent → Laravel.
   - Sinon si `require.symfony/framework-bundle` → Symfony.
   - Sinon → Generic.
   - Détecter le binaire CLI : `bin/console` (Symfony) ou `artisan` (Laravel).
3. `src/Core/Project/FrameworkContext.php` — `final readonly class` :
   - `Framework $framework`, `string $rootPath`, `?string $consoleBinary`, `array $composerData`, `array $sourcePaths` (déduit : `src/`, `app/`).

### Vérification
- [ ] Test fixtures : 3 dossiers minimaux dans `tests/fixtures/projects/` (`symfony-min/`, `laravel-min/`, `generic/`) chacun avec un `composer.json` représentatif. `ProjectDetector::detect()` retourne le bon `Framework` pour chacun.
- [ ] Test : composer.json malformé → exception explicite.

### Anti-pattern guards
- Ne PAS exécuter `bin/console` ou `artisan` ici. Détection = lecture statique uniquement.

---

## Phase 4 — Pipeline AST

### À implémenter
1. `src/Analysis/Ast/ParserPool.php` — singleton-like : un `Parser` créé via `ParserFactory::createForNewestSupportedVersion()`, réutilisé. Idem un `NodeTraverser` partagé (les visitors sont ajoutés/retirés par scan).
2. `src/Analysis/Ast/FileWalker.php` — utilise `Symfony\Component\Finder\Finder`. Yield `SplFileInfo` filtrés sur `*.php`, exclure `vendor/`, `node_modules/`, `var/cache/`, `storage/framework/`, `bootstrap/cache/`. Configurable via `FrameworkContext::$sourcePaths`.
3. `src/Analysis/Ast/AstSnapshot.php` — cache simple `array<string filepath, Node[]>`. `forFile(string): ?array` parse à la demande, mémoïse.
4. `src/Analysis/Ast/Visitors/AbstractRuleVisitor.php` — base class `extends NodeVisitorAbstract` qui collecte les `Finding` dans un `FindingBag` injecté.
5. **Toujours** ajouter `\PhpParser\NodeVisitor\NameResolver` AVANT toute règle, avec `['replaceNodes' => false]`.
6. Wrapper `composer/xdebug-handler` dans `bin/php-doctor` (snippet officiel : 3 lignes).

### Référence à copier
- Bootstrap parser/traverser : Phase 0 → "Minimal Copy-Ready Snippet" du rapport nikic/php-parser.

### Vérification
- [ ] Test : parser un fichier PHP avec syntaxe 8.3 (readonly class, enum) sans erreur.
- [ ] Test : un visitor de comptage compte bien `n` `FunctionLike` dans une fixture.
- [ ] Test : `NameResolver(replaceNodes:false)` ajoute l'attribut `resolvedName` sans muter `$node->name`.
- [ ] Mémo : pas de re-création de `Parser` entre fichiers (assert via spy ou compteur dans `ParserPool`).

### Anti-pattern guards
- Ne PAS utiliser `createForVersion(PhpVersion::fromComponents(8,3))` — préférer `createForNewestSupportedVersion()`.
- Ne PAS lever sur erreur de syntaxe : utiliser `ErrorHandler\Collecting` et émettre un `Finding` `Severity::Info` "fichier non parseable".

---

## Phase 5 — Pipeline Runtime (cœur de la valeur)

### À implémenter
Tous les collectors shellent via `Symfony\Component\Process\Process` (timeout 30 s, capture stdout/stderr). En cas d'échec : émettre un `Finding` Hygiene "introspection indisponible", ne pas crasher.

1. `src/Analysis/Runtime/ProcessExecutor.php` — wrapper `Process` avec timeout, exit code, stdout/stderr en string.
2. `src/Analysis/Runtime/RuntimeSnapshot.php` — DTO readonly contenant : `array $routes, array $services, array $listeners, array $about, array $config, array $migrations`. Tout optionnel.
3. **Symfony collectors** (`src/Analysis/Runtime/Symfony/`) :
   - `RouteCollector` → `bin/console debug:router --format=json --env=dev --no-debug`
   - `ServiceCollector` → `bin/console debug:container --format=json`
   - `EventCollector` → `bin/console debug:event-dispatcher --format=json`
   - `BundleCollector` → `bin/console debug:container --parameter=kernel.bundles --format=json`
4. **Laravel collectors** (`src/Analysis/Runtime/Laravel/`) :
   - `RouteCollector` → `php artisan route:list --json`
   - `AboutCollector` → `php artisan about --json`
   - `ConfigCollector` → workaround validé Phase 0 : `php -r "require __DIR__.'/vendor/autoload.php'; \$app=require __DIR__.'/bootstrap/app.php'; \$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap(); echo json_encode(config()->all());"`. Documenter la limite (ne pas exécuter en prod, doit être lancé localement).
   - `MigrationCollector` → `php artisan migrate:status` + parser de table texte (regex sur lignes `| Yes/No | <name> |`). Tester sur fixture de capture réelle.
5. `src/Analysis/Runtime/Composer/ComposerAuditor.php` → `composer audit --format=json --locked` (requiert composer ≥ 2.4, valider la version disponible avant exécution, sinon Finding Info "composer audit indisponible").
6. `src/Analysis/Runtime/SnapshotBuilder.php` — sélectionne les bons collectors selon `Framework`, agrège en `RuntimeSnapshot`.

### Vérification
- [ ] Test d'intégration : sur `tests/fixtures/projects/symfony-min/` avec un faux `bin/console` (script bash qui echo du JSON statique), `RouteCollector::collect()` rend bien `array`.
- [ ] Idem Laravel avec faux `artisan`.
- [ ] Test : timeout dépassé → exception captée → snapshot partiel (pas de crash).
- [ ] Test : exit code non-zéro → finding Hygiene émis, snapshot a la clé manquante.

### Anti-pattern guards
- ❌ NE PAS appeler `migrate:status --json` (n'existe pas) — utiliser le parser de table.
- ❌ NE PAS appeler `config:show --json` — utiliser le workaround `php -r`.
- ❌ NE PAS lancer les artisan/console en mode prod (forcer `APP_ENV=local` ou `--env=dev`).
- ❌ NE PAS faire confiance à stderr quand on parse stdout JSON : toujours vérifier exit code d'abord.

---

## Phase 6 — Intégration PHPStan

### À implémenter
1. `src/Analysis/Runtime/PhpStan/PhpStanRunner.php` :
   - `isAvailable(FrameworkContext): bool` — vérifie `vendor/bin/phpstan` puis composer.json.
   - `run(FrameworkContext, ?int $level = 5): PhpStanReport` — exécute `vendor/bin/phpstan analyse <sourcePaths> --error-format=json --no-progress`, sans `--level` si `phpstan.neon` existe (laisser le projet décider), sinon avec `--level=5`.
   - Vérifier exit code : 0/1 = OK pour parsing JSON ; autre = retour `null` + Finding Hygiene "phpstan a échoué" avec le stderr en `fixHint`.
2. `src/Analysis/Runtime/PhpStan/PhpStanReport.php` — DTO immuable `array $messages, int $totalErrors`.
3. `src/Rules/Common/PhpStanAggregatorRule.php` — règle spéciale qui :
   - `appliesTo` = toujours, quand `PhpStanRunner::isAvailable` est vrai.
   - Pour chaque message PHPStan → un `Finding` de catégorie `Category::TypeSafety`, severity dérivée de `identifier` (`*.error` → High ; manque de type → Medium ; sinon Low).
   - `ruleId` = `phpstan.<identifier>` (fallback `phpstan.unknown`).
4. Documenter dans `README.md` que PHPStan est optionnel : si absent, la catégorie TypeSafety est marquée "non évaluée" (score N/A, pas 0).

### Vérification
- [ ] Test : décodage JSON conforme au schéma documenté Phase 0 sur fixture réelle (capture d'un `phpstan analyse --error-format=json` sur un projet jouet).
- [ ] Test : projet sans phpstan → `isAvailable()` false, aucune erreur émise.
- [ ] Test : exit code 2 → null + Finding Hygiene.

### Anti-pattern guards
- ❌ NE PAS forcer `--level=9` "pour être strict" — respecter la config du projet.
- ❌ NE PAS pénaliser le score global quand PHPStan est absent (catégorie N/A, pas 0).

---

## Phase 7 — Premières règles MVP (5–8)

Chaque règle = 1 fichier `src/Rules/.../*.php` + 1 fichier de test `tests/Rules/.../*Test.php` avec **au moins** 1 cas positif (déclenche) et 1 cas négatif (ne déclenche pas).

### Règles à livrer (ordre = priorité)

1. **`common.security.hardcoded-secrets`** (`Severity::Critical`, `Category::Security`) — AST.
   - Pattern : assignation/string contenant `AKIA[0-9A-Z]{16}`, `ghp_[0-9A-Za-z]{36}`, regex JWT, password hardcodé > 8 chars dans `.env`-like keys du code.
   - Visitor : détecte `Node\Scalar\String_` dans `Node\Stmt\Property` ou args de `define()`/`putenv()`.

2. **`common.hygiene.env-desync`** (`Severity::Medium`, `Category::Hygiene`) — Runtime, pas AST.
   - Compare `.env.example` vs `.env` (clés présentes seulement dans l'un ou l'autre).
   - Émet 1 Finding par clé manquante.

3. **`common.dependencies.composer-audit`** (`Severity::High` par CVE, `Category::Dependencies`) — Runtime.
   - Re-route `ComposerAuditor` output : 1 Finding par advisory.

4. **`symfony.security.missing-is-granted`** (`Severity::High`, `Category::Security`) — AST + Runtime.
   - Pour chaque controller dans `RouteCollector` snapshot dont la classe vit sous `App\Controller\`, charger l'AST, vérifier présence d'attribut `#[IsGranted]` ou appel à `denyAccessUnlessGranted` dans la méthode mappée. Émet Finding si absent ET la route n'est pas dans une whitelist (`/`, `/login`, `/health`).

5. **`symfony.architecture.orphan-route`** (`Severity::Low`, `Category::Architecture`) — Runtime.
   - Une route dont la classe `_controller` n'existe plus dans `ServiceCollector.definitions` → Finding.

6. **`laravel.perf.eloquent-n-plus-one`** (`Severity::High`, `Category::Performance`) — AST.
   - Heuristique : dans une `foreach($collection as $item)`, détecter `$item->relation` ou `$item->relation()->...->get()` sans appel `->with('relation')` en amont sur `$collection`. Documenter explicitement le taux faux-positif (heuristique, pas analyse de flot complète) → `fixHint` propose `->with(...)`.

7. **`laravel.security.mass-assignment`** (`Severity::High`, `Category::Security`) — AST.
   - Détecter `Model::create($request->all())` ou `->fill($request->all())` sur classes étendant `Illuminate\Database\Eloquent\Model`. Vérifier via AST si la classe a `$guarded = []` OU pas de `$fillable` ni `$guarded` défini.

8. **`laravel.architecture.blade-business-logic`** (`Severity::Medium`, `Category::Architecture`) — file scan, pas AST.
   - Scanner `resources/views/**/*.blade.php` pour : appels DB (`DB::`, `Model::query()`, `::all()`, `::where()`) à l'intérieur de directives Blade (`@php … @endphp` ou `{{ … }}`).

### Vérification globale Phase 7
- [ ] Pour chaque règle : test positif + test négatif passent.
- [ ] `php bin/php-doctor list-rules` affiche les 8 règles avec id/category/severity.
- [ ] Sur les 3 fixtures projets, `scan` produit un nombre attendu de findings (snapshot test sur le JSON output).

### Anti-pattern guards
- ❌ NE PAS écrire de règle qui dépend d'un service/Container réel.
- ❌ NE PAS faire de réseau dans une règle.
- ❌ Chaque règle DOIT être stateless : pas de propriété mutable accumulée entre fichiers (l'état va dans `FindingBag`).

---

## Phase 8 — Reporters (Console + JSON + HTML)

### À implémenter
1. `src/Reporting/Reporter.php` (interface) : `render(FindingBag $bag, Score $score, FrameworkContext $ctx): string|void`.
2. `src/Reporting/Console/ConsoleReporter.php` — utilise `SymfonyStyle` :
   - Header : nom projet + framework + score global coloré (vert ≥ 80, jaune ≥ 60, rouge < 60).
   - Section par catégorie avec score local.
   - Tableau (`$io->table`) des findings : severity | rule | file:line | message.
   - Footer : compte par severity.
3. `src/Reporting/Json/JsonReporter.php` — schéma stable documenté :
   ```json
   {
     "version": "1",
     "project": { "framework": "laravel", "path": "/abs" },
     "score": { "global": 72, "byCategory": { "security": 60, ... } },
     "findings": [ { "ruleId": "...", "severity": "...", ... } ]
   }
   ```
4. `src/Reporting/Html/HtmlReporter.php` — template Twig dans `templates/report.html.twig` :
   - Layout single-file : Tailwind via CDN `<script src="https://cdn.tailwindcss.com"></script>` (acceptable pour rapport local).
   - Cards de score par catégorie (Lighthouse-like : cercle SVG + nombre).
   - Liste de findings groupés par catégorie, expand/collapse via `<details>` natif (pas de JS framework).
   - Embed les données en JSON dans une balise `<script type="application/json">` pour permettre filtrage côté client (vanilla JS minimal inline).
   - Output : 1 fichier HTML self-contained dans `build/report.html`.

### Vérification
- [ ] `scan --format=console` renvoie un texte avec ANSI codes et le score.
- [ ] `scan --format=json` produit du JSON `json_decode`-able conforme au schéma.
- [ ] `scan --format=html` écrit `build/report.html`, taille > 5 Ko, contient `<title>php-doctor</title>` et le score.
- [ ] Test snapshot du JSON sur fixture (assertion sur structure, pas sur valeurs exactes).

### Anti-pattern guards
- ❌ NE PAS embarquer Tailwind buildé localement (alourdit le PHAR pour rien — CDN suffit pour rapport local).
- ❌ NE PAS ajouter Vue/React/Alpine — `<details>` + ~30 lignes de JS vanilla.
- ❌ NE PAS coupler le Reporter au Core (interface only).

---

## Phase 9 — Mode CI + SARIF

### À implémenter
1. Flag `--ci` sur `ScanCommand` : implique `--format=json --quiet` et applique un seuil.
2. Flag `--min-score=<int>` (défaut 70) : exit `Command::FAILURE` si `Score::global` < seuil.
3. Flag `--fail-on=<severity>` (défaut `high`) : exit `FAILURE` si ≥ 1 finding ≥ severity donnée.
4. `src/Reporting/Sarif/SarifReporter.php` — format SARIF 2.1.0 (schéma : https://docs.github.com/en/code-security/code-scanning/integrating-with-code-scanning/sarif-support-for-code-scanning).
   - Champs minimaux : `version, $schema, runs[0].tool.driver.name, runs[0].tool.driver.rules[], runs[0].results[]`.
   - Mapping severity → SARIF `level` : Critical/High → `error`, Medium → `warning`, Low/Info → `note`.

### Vérification
- [ ] `scan --ci` sur projet sain → exit 0.
- [ ] `scan --ci --min-score=99` sur fixture avec findings → exit 1.
- [ ] `scan --format=sarif` produit du JSON validable contre le schéma SARIF (test : `json_decode` + check des clés obligatoires).

### Anti-pattern guards
- ❌ NE PAS laisser `scan` (sans `--ci`) retourner un exit code non-zéro juste parce qu'il a trouvé des findings (UX terminale = toujours 0 hors crash, sauf si `--ci`).

---

## Phase 10 — PHAR build & release

### À implémenter
1. `box.json` racine, basé sur Phase 0 Topic B :
   ```json
   {
     "main": "bin/php-doctor",
     "output": "build/php-doctor.phar",
     "shebang": "#!/usr/bin/env php",
     "chmod": "0755",
     "finder": [
       { "notName": "/LICENSE|.*\\.md|.*\\.dist/", "exclude": ["doc","test","tests"], "in": "src" },
       { "in": "vendor" },
       { "in": "templates" }
     ],
     "stub": true,
     "check-requirements": true
   }
   ```
2. Script `composer build` (`scripts.build`) → `box compile`.
3. Workflow GitHub Actions (`.github/workflows/release.yml`) qui sur tag `v*` :
   - Setup PHP 8.3, install deps `--no-dev` puis re-add box.
   - `box compile`.
   - Upload `build/php-doctor.phar` en release asset.

### Vérification
- [ ] `composer build` produit `build/php-doctor.phar` exécutable.
- [ ] `./build/php-doctor.phar scan tests/fixtures/projects/laravel-min/` fonctionne et match l'output de `php bin/php-doctor scan ...`.
- [ ] Le PHAR refuse de tourner sur PHP 8.2 (test : container Docker `php:8.2-cli`).

### Anti-pattern guards
- ❌ NE PAS inclure `tests/` dans le PHAR.
- ❌ NE PAS désactiver `check-requirements`.

---

## Phase 11 — Vérification finale

### À exécuter
1. Sur un vrai projet Symfony 7 et un vrai projet Laravel 12 :
   - [ ] `php-doctor scan` complète sans crash.
   - [ ] Le rapport HTML s'ouvre dans un navigateur sans erreur console.
   - [ ] Au moins 3 findings de catégories différentes.
   - [ ] Mode `--ci --min-score=70` retourne un exit code cohérent.
2. Anti-pattern grep (commande à exécuter, doit retourner 0 lignes) :
   ```bash
   ig "migrate:status --json" src/        # API inexistante
   ig "config:show --json" src/           # API inexistante
   ig "new ParserFactory\(\)" src/ -c     # max 1 occurrence (pool)
   ig "->parse\(" src/Analysis/Ast/       # juste dans ParserPool/AstSnapshot
   ```
3. Tests : `vendor/bin/phpunit` → tous verts.
4. PHPStan sur php-doctor lui-même (eat-your-own-dogfood) : `vendor/bin/phpstan analyse src/ --level=6` → 0 erreur.

### Critères de done MVP
- [ ] Phases 1 à 10 cochées.
- [ ] README à jour avec : install, usage, exemple de rapport, limites connues (ex: heuristique N+1, workaround Laravel config).
- [ ] CHANGELOG initial `0.1.0`.
- [ ] PHAR signé téléchargeable depuis une release GitHub.

---

## Notes transverses

- **Tests fixtures** : `tests/fixtures/projects/{symfony-min, laravel-min, generic}/` doivent être minimaux (composer.json + 2-3 fichiers PHP signifiants chacun). Les artefacts CLI sont **mockés** par des scripts `bin/console.sh` / `artisan.sh` qui echo du JSON statique préparé.
- **Performance cible** (non bloquant MVP) : scan d'un projet 500 fichiers en < 10 s (hors PHPStan). À mesurer avec `hyperfine` après Phase 7.
- **Bus fichiers** (rappel CLAUDE.md) : créer `.ai/reports/` au début de Phase 1, gitignored.
- **Rapport verifier** obligatoire après Phase 7 et après Phase 10 (re-run des commandes claimées).
