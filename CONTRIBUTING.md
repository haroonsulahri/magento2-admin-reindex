# Contributing

Thank you for helping improve Admin Reindex for Magento 2.

## Before opening a change

- Search existing issues before creating a new one.
- Use the issue forms for reproducible bugs and focused feature requests.
- Discuss large behavior, compatibility, persistence, or queue changes before implementation.
- Never attach credentials, customer information, database exports, production configuration, or proprietary store code.

## Development requirements

Test the module inside a supported Magento 2 checkout. Keep the package generic and use Magento public APIs, dependency injection, ACL, message queue, Bulk Operations, and LockManager facilities instead of store-specific code or direct process control.

Run the checks used by CI from the Magento root:

```bash
vendor/bin/phpcs --standard=Magento2 --extensions=php app/code/Haroone/AdminReindex
vendor/bin/phpunit --no-configuration \
  --bootstrap dev/tests/unit/framework/bootstrap.php \
  app/code/Haroone/AdminReindex/Test/Unit
php bin/magento setup:di:compile
```

Also validate the package metadata from the module directory:

```bash
composer validate --strict --no-check-publish
```

## Pull requests

- Keep each pull request focused on one problem.
- Add or update unit tests for changed behavior.
- Update README and CHANGELOG entries when behavior, compatibility, setup, or operations change.
- Preserve backward compatibility unless the change is explicitly proposed as breaking.
- Confirm that no secrets, local paths, customer names, or store-specific values are included.
- Explain required Magento cache, compilation, setup, static-content, queue, or reindex steps.

By contributing, you agree that your contribution may be distributed under this repository's MIT License.
