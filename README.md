<p align="center">
  <a href="https://haroone.com/">
    <img src="https://raw.githubusercontent.com/haroonsulahri/magento2-admin-reindex/main/docs/media/haroone-admin-reindex-banner.png" alt="Haroone Agency" width="960">
  </a>
</p>

<h1 align="center">Admin Reindex for Magento 2</h1>

<p align="center">
  Reindex or reset selected Magento indexers from the native Index Management grid, in the background, with live progress.
</p>

<p align="center">
  An open-source Magento extension by <a href="https://haroone.com/">Haroone Agency</a>.
</p>

<p align="center">
  <a href="https://github.com/haroonsulahri/magento2-admin-reindex/actions/workflows/ci.yml"><img src="https://github.com/haroonsulahri/magento2-admin-reindex/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green.svg" alt="MIT License"></a>
</p>

<p align="center">
  <a href="#installation">Install</a> ·
  <a href="#run-an-indexer-operation">Run an operation</a> ·
  <a href="CHANGELOG.md">Changelog</a> ·
  <a href="#permissions">Permissions</a> ·
  <a href="https://github.com/haroonsulahri/magento2-admin-reindex/blob/main/CONTRIBUTING.md">Contribute</a> ·
  <a href="#support-and-magento-services">Support</a>
</p>

## Run an indexer operation

1. Open **System > Tools > Index Management**.
2. Select one or more indexers, or use Magento's **Select All** control.
3. Choose **Reindex** or **Reset** from the existing **Actions** dropdown and confirm.
4. Watch successful, running, skipped, failed, and remaining indexers in the progress popup.
5. Leave the page safely, or close the completed popup to refresh the grid statuses and timestamps.

**Reindex** runs Magento's full `reindexAll()` operation. **Reset** calls Magento's native `invalidate()` API, which is the same state change as `bin/magento indexer:reset`. The extension replaces Magento's synchronous **Invalidate index** entry with the background **Reset** entry to avoid duplicate actions.

The admin request only schedules new work. Magento's message queue executes each selected indexer in the background, so closing the popup or leaving Index Management does not stop the job.

## Why use it?

Magento provides indexer commands on the server, but it does not provide a native admin action for running selected indexers. This extension adds that missing operation without replacing the core grid or building a separate admin page.

- Adds **Reindex** and **Reset** to Magento's existing Index Management action list
- Opens progress immediately without waiting for a full indexer request
- Continues processing after the administrator leaves the page
- Shows live totals and clear per-indexer results
- Warns in Index Management when no working consumer path is detected
- Reuses an active operation instead of queuing the same indexer twice
- Uses Magento Bulk Operations, Message Queue, ACL, LockManager, cron runner, and indexer APIs
- Skips an indexer when Magento already reports it as working
- Logs complete exceptions while showing safe messages in the admin
- Adds no custom database tables, custom cron job, configuration page, or frontend code

## Requirements

| Magento release | Supported PHP versions | CI test version |
| --- | --- | --- |
| 2.4.4 | 8.1 | 2.4.4-p13 / PHP 8.1 |
| 2.4.5 | 8.1 | 2.4.5-p14 / PHP 8.1 |
| 2.4.6 | 8.1, 8.2 | 2.4.6-p15 / PHP 8.2 |
| 2.4.7 | 8.2, 8.3 | 2.4.7-p10 / PHP 8.3 |
| 2.4.8 | 8.3, 8.4 | 2.4.8-p5 / PHP 8.4 |
| 2.4.9 | 8.4, 8.5 | 2.4.9 / PHP 8.5 |

The module supports Magento Open Source and Adobe Commerce. CI installs the latest publicly available `magento/project-community-edition` package for each release line; Adobe Commerce extended-support patch numbers can be higher. The compatibility matrix follows Adobe's published [system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements) and [released versions](https://experienceleague.adobe.com/en/docs/commerce-operations/release/versions).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for versioned feature, compatibility, and operational changes.

Tagged releases include a verified manual-install ZIP built on Linux. The archive is also produced as a CI artifact for every pull request.

## Installation

### Composer

Install the stable release from Packagist:

```bash
composer require haroone/module-admin-reindex:^1.0
php bin/magento module:enable Haroone_AdminReindex
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:clean
```

Do not skip `setup:upgrade`. Magento registers the module's MySQL queue destination during recurring setup; without it, jobs can appear as scheduled but never start.

### Manual installation

Copy the repository contents to:

```text
app/code/Haroone/AdminReindex
```

Then run:

```bash
php bin/magento module:enable Haroone_AdminReindex
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:clean
```

Run `php bin/magento setup:static-content:deploy -f` as part of the normal deployment flow when the store is in production mode. The progress popup includes small admin JavaScript and CSS files.

## Queue requirement and health check

Magento's `consumers` cron group must be running. A normal Magento cron installation includes the core consumer runner. A dedicated process manager can run the module consumer instead:

```bash
php bin/magento queue:consumers:start haroone.adminreindex.indexer --single-thread
```

`--single-thread` is important for a dedicated process because it holds Magento's standard consumer lock, prevents a duplicate process, and gives the health check a reliable running signal.

Index Management checks worker health when the page renders and again immediately before scheduling. It considers:

- Magento's standard lock for `haroone.adminreindex.indexer`
- The age of pending module bulk operations
- The last module operation `started_at` timestamp
- A recent successful or running `consumers_runner` cron job, when that runner is enabled for this consumer

If no working path is detected, Magento shows: **Background worker not detected — jobs will queue but won't run until the consumer is started.** The warning does not discard the request. Jobs remain queued for the consumer to process later. The extension deliberately does not start operating-system processes from an admin request.

Consumers started without `--single-thread` cannot advertise an idle process through Magento's consumer lock. Recent processing still counts as healthy, but use the documented command for reliable continuous-process detection.

## Duplicate operation guard

Before publishing, the scheduler checks native `magento_operation` records for open operations created by this module. If the same indexer already has a pending or running Reset or Reindex operation, the extension does not publish another message. The popup follows the existing bulk operation instead.

The check and publish step run inside one Magento LockManager lock, preventing two simultaneous admin requests from creating the same operation. A mixed selection can follow existing bulk UUIDs and a newly created bulk together while showing each selected indexer once.

## Behavior and scope

Reindex runs the full `reindexAll()` operation for each selected indexer. It does not reindex a specific database table, product, category, or row. Reset marks the selected indexer invalid through `invalidate()`; it does not rebuild index data.

The extension does not change:

- Indexer modes
- PHP memory or execution time limits
- Magento cache state
- Store configuration

Magento's existing yellow **One or more indexers are invalid** notification already tells administrators when reindexing is required. Reset reuses that core behavior instead of adding a competing notification system.

## Core Magento features reused

- Index Management grid and Select All
- `IndexerRegistry` and indexer working state
- Native Bulk Operations persistence and history
- Message Queue and the Magento consumer cron group
- Magento LockManager for sequential processing and race-safe scheduling
- Invalid-indexer system notification
- Admin modal, translations, and non-JavaScript form fallback

Administrators with Magento's **Bulk Actions** permission can also see jobs in the native Bulk Actions history and system-message area.

## Permissions

The extension adds **Reindex and Reset Data** beneath **Index Management** in Magento ACL. Grant it under **System > Permissions > User Roles > Role Resources**.

Users without this permission cannot schedule jobs, request progress, or see the **Reindex** or **Reset** actions. The progress endpoint returns only this module's operations and only for validated bulk UUIDs and indexer IDs. This allows an authorized administrator to follow an existing deduplicated job created by another authorized administrator without exposing unrelated Magento bulk operations.

## LockManager backend and multi-node safety

The extension does not implement or force a lock backend. It injects Magento's public `LockManagerInterface`, so both the per-operation processing lock and the scheduling deduplication lock use the store's configured Magento lock provider.

Magento reads the provider from `app/etc/env.php` at `lock/provider`. If it is omitted, Magento defaults to `db`.

| `lock/provider` | Where the lock lives | Multi-node guidance |
| --- | --- | --- |
| `db` | Magento database advisory locks | Safe when every web and consumer node uses the same database. This is Magento's default and the module's safest out-of-box multi-node choice. |
| `cache` | Magento cache lock backend | Safe only when every node uses the same shared lock-capable cache backend. A shared Redis cache is the common setup; the provider value is still `cache`, not `redis`. |
| `zookeeper` | Shared ZooKeeper service | Safe when all nodes use the same ZooKeeper ensemble and the PHP extension is installed. |
| `file` | Filesystem lock files | Suitable for one node. Do not use it for multi-node or Cloud deployments unless every node shares a filesystem with reliable cross-node file locking. |

For a multi-node or Cloud deployment, verify the resolved provider before enabling admin operations. The database provider is safe out of the box when the application nodes share Magento's database. A file provider on node-local storage is not safe because two nodes can acquire independent copies of the same lock.

## Failure handling

- An indexer already marked as working is skipped.
- A pending or running module operation for the same indexer is reused.
- One failed indexer does not prevent later queued indexers from running.
- The popup exposes only safe summaries.
- Full exceptions are written to Magento logs with the indexer ID and action.
- A global Magento lock keeps module-created Reset and Reindex operations sequential, even with multiple consumer processes.

## Security

Please follow the private reporting process in [SECURITY.md](https://github.com/haroonsulahri/magento2-admin-reindex/security/policy) for suspected vulnerabilities. Do not include credentials, customer information, or production data in a public issue.

- POST-only scheduling endpoints with Magento form-key validation
- GET-only progress endpoint
- Dedicated ACL permission on all endpoints
- Strict indexer ID and bulk UUID validation
- Module-topic and validated UUID/indexer filtering on progress requests
- Escaped PHP output and result text inserted through jQuery text nodes

## Support and Magento services

This extension is built and maintained by [Haroone Agency](https://haroone.com/), a Magento-focused ecommerce engineering company working on custom modules, checkout fixes, migrations, performance, and production support.

- Product bugs and feature requests: [GitHub Issues](https://github.com/haroonsulahri/magento2-admin-reindex/issues)
- Contributions: [Contribution guidelines](https://github.com/haroonsulahri/magento2-admin-reindex/blob/main/CONTRIBUTING.md)
- Magento engineering: [Haroone Magento services](https://haroone.com/services/magento)
- Private support and project enquiries: [Contact Haroone Agency](https://haroone.com/contact)

## License

Released under the [MIT License](LICENSE). Copyright © 2026 Haroone.com.
