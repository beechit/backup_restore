<?php
/*
 * This source file is proprietary of Beech Applications bv.
 * Created by: Ruud Silvrants
 * Date: 01/04/2020
 * All code (c) Beech Applications bv. all rights reserverd
 */

namespace BeechIt\BackupRestore\Service;


use Doctrine\DBAL\Exception\TableNotFoundException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DatabaseTableService implements SingletonInterface
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
     * @throws TableNotFoundException
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
        throw new TableNotFoundException('Table does not exist');
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

    /**
     * Check if given table exists
     *
     * @param string $table
     * @return bool
     */
    public function checkIfTableExists($table): bool
    {
        $tableColumns = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table)
            ->getSchemaManager()
            ->listTableColumns($table);

        return !(empty($tableColumns) === true);
    }
}
