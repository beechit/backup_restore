<?php
/*
 * This source file is proprietary of Beech Applications bv.
 * Created by: Ruud Silvrants
 * Date: 01/04/2020
 * All code (c) Beech Applications bv. all rights reserverd
 */

namespace BeechIt\BackupRestore\Repository;


use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class StorageRepository
{
    /**
     * @return array
     * @throws \InvalidArgumentException
     */
    public function getStorages(): array
    {
        $table = 'sys_file_storage';
        $storages = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_storage')
            ->select(
                ['uid', 'name', 'processingfolder', 'driver', 'is_online', 'configuration'],
                $table
            )->fetchAll();
        return $storages;
    }
}