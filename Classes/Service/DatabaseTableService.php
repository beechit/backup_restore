<?php
/*
 * This source file is proprietary of Beech Applications bv.
 * Created by: Ruud Silvrants
 * Date: 01/04/2020
 * All code (c) Beech Applications bv. all rights reserverd
 */

namespace BeechIt\BackupRestore\Service;


use Helhum\Typo3Console\Service\Persistence\TableDoesNotExistException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DatabaseTableService
{
    /**
     * Get all tables which might be truncated
     *
     * @return array
     */
    public function getUnNeededTables(): array
    {
        $tables = [];

        $truncatedPrefixes = [
            'cf_',
            'cache_',
            'zzz_',
            'tx_extensionmanager_domain_model_extension',
            'tx_extensionmanager_domain_model_repository',
            'tx_realurl_errorlog',
            'sys_lockedrecords',
            'be_sessions'
        ];

        $tableList = $this->getAllDatabaseTables();
        foreach ($tableList as $tableName) {
            $found = false;
            foreach ($truncatedPrefixes as $prefix) {
                if ($found || GeneralUtility::isFirstPartOfStr($tableName, $prefix)) {
                    $tables[$tableName] = $tableName;
                    $found = true;
                }
            }
        }

        return $tables;
    }

    /**
     * @param $tableName
     * @return array
     * @throws TableDoesNotExistException
     */
    public function getFieldsOfTable($tableName): array
    {
        /** @var Connection $dbConnection */
        $dbConnection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $tables = $dbConnection->getSchemaManager()->listTables();
        foreach ($tables as $table) {
            if ($tableName !== $table->getName()) {
                continue;
            }
            $fields = [];
            foreach ($table->getColumns() as $column) {
                $fields[] = $column->getName();
            }
            return $fields;
        }
        throw new TableDoesNotExistException('Table does not exist');
    }

    /**
     * Get tables in the database
     * @return array
     */
    public function getAllDatabaseTables(): array
    {
        /** @var Connection $dbConnection */
        $dbConnection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $tables = $dbConnection->getSchemaManager()->listTables();
        $tableNames = [];
        foreach ($tables as $table) {
            $tableNames[] = $table->getName();
        }
        return $tableNames;
    }
}