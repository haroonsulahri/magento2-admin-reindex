# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.1.0] - 2026-08-09

- Added background **Reset** beside **Reindex** and replaced Magento's duplicate synchronous **Invalidate index** action.
- Added a worker-health banner based on the consumer lock, pending operation age, last processed timestamp, and consumer cron history.
- Added a pre-scheduling worker check while still allowing jobs to queue for later processing.
- Added a race-safe deduplication guard that reuses progress from active operations instead of queuing the same indexer twice.
- Added multi-bulk progress aggregation for selections containing both new and already-active indexers.
- Documented Magento LockManager providers and multi-node safety explicitly.

## [1.0.0] - 2026-07-16

- Added the secured **Reindex** action to Magento Index Management.
- Added background execution through Magento Bulk Operations and Message Queue.
- Added a live Magento progress modal with successful, skipped, failed, and remaining counts.
- Added immediate AJAX submission so the progress modal opens without a page reload.
- Added compact preparing, queued, running, complete, and error modal states.
- Added distinct progress indicators for completed, running, skipped, and failed indexers.
- Added an automatic Index Management refresh when the completed popup is closed.
- Reused Magento's built-in invalid-indexer notification and Bulk Actions history.
- Added sequential locking, working-indexer skips, per-indexer failure isolation, and full exception logging.
- Persisted consumer operation entities so Magento bulk progress is recorded from the first queue message.
- Added Magento 2.4.4 through 2.4.9 compatibility checks.
- Persisted progress through Magento's public Bulk Operations API.
- Added action-focused documentation and consistent Haroone Agency branding.
- Hardened CI with explicit Magento repository access, Composer version pinning, and JavaScript syntax checks.
