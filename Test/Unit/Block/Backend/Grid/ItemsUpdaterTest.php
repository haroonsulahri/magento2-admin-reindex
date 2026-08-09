<?php
/**
 * Copyright (c) 2026 Haroone.com
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Haroone\AdminReindex\Test\Unit\Block\Backend\Grid;

use Haroone\AdminReindex\Block\Backend\Grid\ItemsUpdater;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class ItemsUpdaterTest extends TestCase
{
    public function testKeepsActionWhenAuthorized(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($this->exactly(2))
            ->method('isAllowed')
            ->willReturn(true);

        $updater = new ItemsUpdater($authorization);
        $options = $updater->update($this->getOptions());

        $this->assertArrayHasKey('reindex_selected', $options);
        $this->assertArrayHasKey('reset_selected', $options);
        $this->assertArrayNotHasKey('invalidate_index', $options);
        $this->assertArrayHasKey('change_mode_onthefly', $options);
    }

    public function testRemovesOnlyReindexActionWhenUnauthorized(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($this->exactly(2))
            ->method('isAllowed')
            ->willReturnCallback(
                static fn (string $resource): bool => $resource === 'Magento_Indexer::changeMode'
            );

        $updater = new ItemsUpdater($authorization);
        $options = $updater->update($this->getOptions());

        $this->assertArrayNotHasKey('reindex_selected', $options);
        $this->assertArrayNotHasKey('reset_selected', $options);
        $this->assertArrayNotHasKey('invalidate_index', $options);
        $this->assertArrayHasKey('change_mode_onthefly', $options);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getOptions(): array
    {
        return [
            'change_mode_onthefly' => ['label' => 'Update on Save'],
            'invalidate_index' => ['label' => 'Invalidate index'],
            'reindex_selected' => ['label' => 'Reindex'],
            'reset_selected' => ['label' => 'Reset'],
        ];
    }
}
