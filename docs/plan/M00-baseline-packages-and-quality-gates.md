# M00 Baseline packages and quality gates

Workflow, conventions, and status: [README](README.md).

## Goal

Remove the unused skeleton packages. Enforce static analysis, code style, and the test conventions with automated checks.

## Depends on

- The [precondition](README.md#precondition).
- This milestone runs on host PHP 8.5 and Composer. The container arrives in M01.

## In scope

- Remove Mailer, Notifier, Turbo, Stimulus, and AssetMapper, as decision D10 says. Keep Form, because HWIOAuthBundle requires it in M32.
- Set the PHP requirement in `composer.json` to `>=8.5`, so that PHPStan and php-cs-fixer accept PHP 8.5 syntax.
- Add PHPStan with the symfony, doctrine, and phpunit extensions, php-cs-fixer, and Panther.
- PHPUnit suites `unit`, `functional`, and `e2e`.
- Convention meta-tests.

## Out of scope

- Docker, the Makefile, application code, Reprise.
- Backlog files. They exist in [docs/backlog/](../backlog/README.md).

## Files (representative)

- Changed: `composer.json`, `composer.lock`, `symfony.lock`, `config/packages/messenger.yaml`, `templates/base.html.twig`, `tests/bootstrap.php`, `phpunit.dist.xml`, `.gitignore`, `.env`, `compose.override.yaml`.
- New: `phpstan.dist.neon`, `tests/object-manager.php`, `.php-cs-fixer.dist.php`, `tests/Unit/Conventions/*Test.php`, `tests/Functional/KernelBootsTest.php`.
- Deleted: `assets/controllers/`, `assets/controllers.json`, `assets/stimulus_bootstrap.js`, `assets/app.js`, `assets/styles/`, `assets/vendor/`, `importmap.php`, `config/packages/asset_mapper.yaml`, `config/packages/mailer.yaml`, `config/packages/notifier.yaml`, `config/packages/ux_turbo.yaml`. Flex removes most of them. Delete what remains.

## Tasks (ordered)

1. Delete the three Mailer and Notifier routing lines in `config/packages/messenger.yaml`: `SendEmailMessage`, `ChatMessage`, and `SmsMessage`. Otherwise the post-remove `cache:clear` fails.
2. Run `composer remove symfony/mailer symfony/notifier symfony/ux-turbo symfony/stimulus-bundle symfony/asset-mapper`.
3. Check what Flex removed. Delete the leftovers:
   - the `mailer` service block in `compose.override.yaml` and the `MAILER_DSN` block in `.env`;
   - the `importmap:install` auto-script in `composer.json`;
   - the recipe files listed under "Deleted", including the untracked `assets/vendor/`;
   - the `symfony/asset-mapper` block in `.gitignore`;
   - the `importmap` block in `templates/base.html.twig`.
   Keep `symfony/form`.
4. Set `"php": ">=8.5"` in `composer.json`. Run `composer update --lock` to refresh the lock hash.
5. Run `composer require --dev phpstan/phpstan phpstan/phpstan-symfony phpstan/phpstan-doctrine phpstan/phpstan-phpunit friendsofphp/php-cs-fixer symfony/panther`.
6. Write `phpstan.dist.neon`:
   - level 8;
   - paths `bin`, `config`, `public`, `src`, `tests`; exclude `config/reference.php`;
   - `symfony.containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml`;
   - `doctrine.objectManagerLoader: tests/object-manager.php`;
   - one ignore: the identifier `method.unused` in `src/Kernel.php`. PHPStan reports `Kernel::getAllowedEnvs()` as unused, but `KernelTrait` calls it through `MicroKernelTrait`.
7. Write `tests/object-manager.php`. It boots the dev kernel and returns the entity manager.
8. In `tests/bootstrap.php`, call `new Dotenv()->bootEnv(dirname(__DIR__).'/.env')` directly. The `method_exists()` guard is always true, and PHPStan reports it. Fix the code. Do not add an ignore.
9. Write `.php-cs-fixer.dist.php`: the ruleset `@Symfony`. Exclude `var`, `node_modules`, `public/build`, `public/build-e2e`, `config/bundles.php`, and `config/reference.php`.
10. Edit `phpunit.dist.xml`:
    - suites `unit` for `tests/Unit`, `functional` for `tests/Functional`, `e2e` for `tests/E2E`;
    - keep the `failOnDeprecation`, `failOnNotice`, and `failOnWarning` settings;
    - register the Panther PHPUnit extension. Read its class name for PHPUnit 13 in `vendor/symfony/panther/`.
11. Write the convention tests below. They read files, so they need no kernel boot.
12. Break each convention test once by hand, see it fail, then revert the change.

## Acceptance tests

- **U `TestClassesExtendKernelTestCaseTest`** proves that every PHP test is based on `KernelTestCase`, as the standing rules require.
  - "Every test class extends KernelTestCase directly or through WebTestCase or PantherTestCase"
- **U `TestMethodsHaveTestDoxTest`** proves that no test method lacks a description.
  - "Every test method has a TestDox attribute"
- **U `NoContainerMockingTest`** proves that no test replaces a container service. It searches `tests/` for `getContainer()->set(`.
  - "No test replaces a service in the container"
- **U `FileSizeLimitTest`** proves the file-size rule. It keeps an explicit exception list for generated files, which is empty at this point.
  - "No PHP, TypeScript, or CSS file in src, tests, or assets has more than 800 lines"
- **U `NoDebugOutputInSrcTest`** proves that production code writes no debug output.
  - "No PHP file in src calls echo, print, var_dump, print_r, dump, or dd"
- **F `KernelBootsTest`** proves that the package removal left a working container.
  - "The test kernel boots without mailer, notifier, or asset mapper services"

## Verification commands

```sh
composer validate --strict
bin/console cache:clear
bin/console lint:container
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
bin/phpunit --testsuite unit,functional
grep -rniE "importmap|stimulus|turbo" config templates assets
```

PHPStan reports 0 errors. The `grep` finds nothing. `make check` does not exist yet.

## Done

- All gates are green on the host.
- Each convention test failed once on a violation made by hand, and passes after the revert.
- The report names the installed versions of the new development packages.
