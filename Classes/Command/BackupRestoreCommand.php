<?php

namespace BeechIt\BackupRestore\Command;

/*
 * This source file is proprietary property of Beech Applications B.V.
 * Date: 01-10-2015
 * All code (c) Beech Applications B.V. all rights reserved
 */

use BeechIt\BackupRestore\Database\Process\MysqlCommand;
use BeechIt\BackupRestore\File\Process\TarCommand;
use BeechIt\BackupRestore\Service\BackupFileService;
use BeechIt\BackupRestore\Service\DatabaseTableService;
use BeechIt\BackupRestore\Service\StorageService;
use BeechIt\BackupRestore\Utility\BinaryUtility;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Helhum\Typo3Console\Database\Configuration\ConnectionConfiguration;
use Helhum\Typo3Console\Database\Schema\SchemaUpdate;
use Helhum\Typo3Console\Database\Schema\SchemaUpdateType;
use Helhum\Typo3Console\Service\Database\SchemaService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class BackupCommandController
 */
class BackupRestoreCommand extends \Symfony\Component\Console\Command\Command
{
    /**
     * @var int processTimeOut in seconds, default on 10 minutes.
     * This variable can be override by setting the environment variable:BACKUP_PROCESS_TIME_OUT
     * For example: BACKUP_PROCESS_TIME_OUT="30000" php typo3cms backup:create
     */
    protected $processTimeOut = 30000;

    /**
     * @var BackupFileService
     */
    protected $backupFileService;

    /**
     * @var ConnectionConfiguration
     */
    protected $connectionConfiguration;

    /**
     * @var SchemaService
     */
    protected $schemaService;

    /**
     * @var DatabaseTableService
     */
    protected $databaseTableService;

    /**
     * @var array temp files (removed after destruction of object)
     */
    protected $tmpFiles = [];

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * Tables to merge on restore
     * @var string[]
     */
    protected $tablesToMerge = ['be_users', 'tx_scheduler_task'];

    /**
     * BackupCreateCommand constructor.
     *
     * @param string|null $name
     * @param ConnectionConfiguration|null $connectionConfiguration
     * @param DatabaseTableService $databaseTableService
     */
    public function __construct(
        string $name = null,
        ConnectionConfiguration $connectionConfiguration = null
    ) {
        parent::__construct($name);
        $this->connectionConfiguration = $connectionConfiguration ?: new ConnectionConfiguration();
        $this->schemaService = $schemaService ?? new SchemaService(new SchemaUpdate());
        $this->databaseTableService = $databaseTableService ?? GeneralUtility::makeInstance(DatabaseTableService::class);

        if ($this->databaseTableService->checkIfTableExists('sys_domain')) {
            $this->tablesToMerge[] = 'sys_domain';
        }

        $processTimeOutEnv = getenv('BACKUP_PROCESS_TIME_OUT');
        if (!empty($processTimeOutEnv)) {
            $this->processTimeOut = (int)$processTimeOutEnv;
        }
    }

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setDescription('Restore file storages + database from backup');
        $this->addArgument('backup', InputArgument::OPTIONAL, 'Name of backup (with or without file extension)', '');
        $this->addOption(
            'backup-folder',
            'backupFolder',
            InputOption::VALUE_OPTIONAL,
            'Alternative path of backup folder',
            ''
        );
        $this->addOption(
            'plain-restore',
            'plainRestore',
            InputOption::VALUE_OPTIONAL,
            'Restore db without sanitizing and merging with local tables',
            false
        );
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_OPTIONAL,
            'Force restore in Production context',
            false
        );
        $this->setHelp('--');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $backup = $input->getArgument('backup');
        $backupFolder = $input->getOption('backup-folder');
        $plainRestore = $input->getOption('plain-restore');
        $force = $input->getOption('force');

        // when implicit the arguments is provided but no value is set. It means that we say the value is true
        // $ backup:restore --plain-restore => is that we want plain-restore
        if ($plainRestore === null) {
            $plainRestore = true;
        }
        if ($force === null) {
            $force = true;
        }

        $this->io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        if (!$force && (string)Environment::getContext() === 'Production') {
            $this->io->error(sprintf('Restore is not possible in "Production" context without the --force option'));
            return 1;
        }
        if (!BinaryUtility::checkIfBinaryExists(TarCommand::getTarBinPath())) {
            $this->io->error(sprintf('Please set correct ENV path_tar_bin to tar binary'));
            return 1;
        }
        if (!BinaryUtility::checkIfBinaryExists(MysqlCommand::getMysqlBinPath())) {
            $this->io->error(sprintf('Please set correct ENV path_mysql_bin to mysql binary'));
            return 1;
        }
        /** @var BackupFileService $backupFileService */
        $this->backupFileService = GeneralUtility::makeInstance(BackupFileService::class, $backupFolder);

        if (empty($backup)) {
            $backups = $this->backupFileService->getAvailableBackupFiles();
            if ($backups === []) {
                $this->io->error(sprintf('No backups found in "%s" to restore!',
                    $this->backupFileService->getBackupFolder()));
                return 1;
            }
            $backup = $this->askBackupFile($input, $output, $backups, $helper);
        }

        // We expect the backup name without leading extension so be sure it is stripped
        $backup = preg_replace('/\.tgz$/', '', $backup);
        $backupFile = $this->backupFileService->getBackupFolder() . $backup . '.tgz';
        if (!file_exists($backupFile)) {
            $this->io->error(sprintf('Backup %s (%s) is not a valid file', $backup, $backupFile));
            return 1;
        }
        $tarCommand = new TarCommand();
        $tmpFolder = $this->backupFileService->getTmpFolder() . $backup . '/';
        GeneralUtility::mkdir($tmpFolder);

        $this->io->note('Extracting backup tgz');
        $this->extractBackupFileToTmpFolder($tarCommand, $backupFile, $tmpFolder);

        $this->io->note('Restore DB dump');
        $this->restoreDatabaseFromFolder($tmpFolder, $backup, $plainRestore);

        $this->io->note('Restore file storages');
        $this->restoreStorages($tmpFolder, $backup, $tarCommand);

        $this->io->note('Remove temp folders');
        $this->backupFileService->removeFolder($tmpFolder);
        return 0; // everything ok
    }

    /**
     * Restore legacy format backups
     *
     * @param string $tmpFolder folder to temporary extract the files
     * @param string $fileBackups path of files tgz file
     */
    protected function legacyRestore($tmpFolder, $fileBackups)
    {
        $tmpFolder .= 'file-storages/';

        // Create tmp folder
        GeneralUtility::mkdir($tmpFolder);

        $tarCommand = new TarCommand();
        $tarCommand->tar(
            [
                'zxf',
                $fileBackups,
                '-C',
                $tmpFolder
            ],
            $this->buildOutputClosure()
        );

        foreach (glob($tmpFolder . '*', GLOB_ONLYDIR) as $folder) {
            $this->io->writeln('restore file storage: ' . basename($folder));

            // todo: cleanup removed files from storage
            // todo: get target path from sys_file_storages like packageFiles() instead of Environment::getPublicPath()
            shell_exec('cp -R ' . $folder . ' ' . Environment::getPublicPath() . '/');
        }

        $this->backupFileService->removeFolder($tmpFolder);
    }

    /**
     * Copy db tables we merge with restored database later
     *
     * @todo: make it possible to extend this through configuration
     */
    protected function createCopyOfTablesToMerge()
    {
        /** @var Connection $dbConnection */
        $dbConnection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        foreach ($this->tablesToMerge as $table) {
            try {
                $dbConnection->exec('CREATE TABLE ' . $table . '_local LIKE ' . $table);
                $dbConnection->exec('INSERT INTO ' . $table . '_local SELECT * FROM ' . $table);
            } catch (DBALException $ex) {
                $this->io->error(sprintf('Failed creating [%s_local]', $table));
                continue;
            }
            $this->io->success(sprintf('Created [%s_local]', $table));
        }
    }

    protected function dropCopiedTablesUsedForMerge()
    {
        /** @var Connection $dbConnection */
        $dbConnection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        foreach ($this->tablesToMerge as $table) {
            try {
                $dbConnection->exec('DROP TABLE  ' . $table . '_local');
                $this->io->success(sprintf('Deleted [%s_local]', $table));
            } catch (DBALException $ex) {
                $this->io->error(sprintf('Failed dropping table [%s_local]', $table));
            }
        }
    }

    /**
     * Sanitize restored tables
     *
     * @todo: make it possible to extend this through configuration
     */
    protected function sanitizeRestoredTables()
    {
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('fe_users');
        try {
            $connection->exec('UPDATE fe_users SET username = MD5(username), password = MD5(password), email = CONCAT(LEFT(UUID(), 8), "@beech.it") WHERE email NOT LIKE "%@beech.it"');
            $this->io->success('Sanitized `fe_users` table');
        } catch (DBALException $ex) {
            $this->io->error('Could not sanitize `fe_users` table');
        }
    }

    /**
     * Merge restored tables with local tables
     *
     * @todo: make it possible to extend this through configuration
     */
    protected function mergeRestoredTablesWithLocalCopies()
    {
        if (in_array('sys_domain', $this->tablesToMerge)) {
            try {
                $this->updateSysDomainWithLocalInformation();
                $this->io->success('Merged sys_domain with local version');
            } catch (DBALException $exception) {
                $this->io->error(sprintf('[SQL ERROR] %s', $exception->getMessage()));
            }
        }

        try {
            $this->disableAllBackendUsers();
            $this->updateBackendUserWithLocalInformation();
            $this->io->success('Merged be_users with local version (new added users are disabled)');
        } catch (DBALException $exception) {
            $this->io->error(sprintf('[SQL ERROR] %s', $exception->getMessage()));
        }

        try {
            $this->disableAllSchedularTasks();
            $this->updateSchedularTaskWithLocalInformation();
            $this->io->success('Merged tx_scheduler_task with local version (new added tasks are disabled)');
        } catch (DBALException $exception) {
            $this->io->error(sprintf('[SQL ERROR] %s', $exception->getMessage()));
        }
    }

    /**
     * Disable all BE users
     */
    protected function disableAllBackendUsers()
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        $queryBuilder->update('be_users')
            ->set('disable', 1)
            ->execute();
    }

    protected function updateBackendUserWithLocalInformation()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        $connection->exec('
            UPDATE
                be_users AS a
            JOIN
                be_users_local AS b
            ON
                a.uid = b.uid
            SET
                a.username = b.username,
                a.password = b.password,
                a.admin = b.admin,
                a.disable = b.disable,
                a.deleted = b.deleted');
    }

    /**
     * Disable all BE users
     */
    protected function disableAllSchedularTasks()
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_scheduler_task');
        $queryBuilder->update('tx_scheduler_task')
            ->set('disable', 1)
            ->execute();
    }

    protected function updateSchedularTaskWithLocalInformation()
    {
        $schedulerTaskFields = $this->getFieldsOfTable('tx_scheduler_task');

        $updateFields = [];
        foreach ($schedulerTaskFields as $fieldName) {
            if ($fieldName === 'uid') {
                continue;
            }
            $updateFields[] = 'a.' . $fieldName . ' = b.' . $fieldName;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_scheduler_task');
        $connection->exec('
            UPDATE
                tx_scheduler_task AS a
            JOIN
                 tx_scheduler_task_local AS b
            ON
                a.uid = b.uid
            SET
                ' . implode(',', $updateFields));
    }

    protected function updateSysDomainWithLocalInformation()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_domain');
        $connection->exec('
            UPDATE
                sys_domain AS a
            JOIN
                sys_domain_local AS b
            ON
                a.uid = b.uid
            SET
                a.domainName = b.domainName');
    }

    /**
     * @param $tableName
     * @return array
     * @throws TableNotFoundException
     */
    protected function getFieldsOfTable($tableName): array
    {
        return $this->databaseTableService->getFieldsOfTable($tableName);
    }

    /**
     * @param string $dbFolderName name of the folder containing the db.sql filename to restore
     * @param string $backup name for the legacy restore
     * @param boolean $plainRestore restore full db or sanitized
     * @throws \TYPO3\CMS\Core\Type\Exception\InvalidEnumerationValueException
     */
    protected function restoreDatabaseFromFolder($dbFolderName, $backup, $plainRestore): void
    {
        $dbFile = 'db.sql';
        if (is_file($dbFolderName . $dbFile)) {
            $this->restoreDatabaseByFileName($dbFolderName . $dbFile, $plainRestore);
        } else {
            // legacy db dump
            $legacyDbDump = $dbFolderName . preg_replace('/-backup$/', '', $backup) . '-db.sql';
            if (is_file($legacyDbDump)) {
                $this->restoreDatabaseByFileName($legacyDbDump, $plainRestore);
            }
        }
    }

    /**
     * @param string $sqlFile
     * @param $plainRestore
     * @return int
     * @throws \TYPO3\CMS\Core\Type\Exception\InvalidEnumerationValueException
     */
    protected function restoreDatabaseByFileName($sqlFile, $plainRestore): int
    {
        if (!$plainRestore) {
            $this->createCopyOfTablesToMerge();
        }

        $dbConfig = $this->connectionConfiguration->build();
        $mysqlCommand = new MysqlCommand(
            $dbConfig
        );

        $exitCode = $mysqlCommand->mysql(
            [],
            // @todo: improve to real resource input
            file_get_contents($sqlFile),
            $this->buildOutputClosure(),
            $this->processTimeOut
        );

        if (!$exitCode) {
            $this->io->success('The db has been restored');

            if (!$plainRestore) {
                $this->sanitizeRestoredTables();
                $this->mergeRestoredTablesWithLocalCopies();
                $this->dropCopiedTablesUsedForMerge();
            }

            // Update DB to be sure all needed tables are present
            try {
                $schemaUpdateTypes = SchemaUpdateType::expandSchemaUpdateTypes(['*.add', '*.change']);
                $result = $this->schemaService->updateSchema($schemaUpdateTypes);
                if ($result->hasPerformedUpdates()) {
                    $this->io->success('Updated db to be inline with extension configuration');
                }
            } catch (\UnexpectedValueException $e) {
                $this->io->error(sprintf('Failed to update db: %s', $e->getMessage()));
            }
        }

        return $exitCode;
    }

    /**
     * @return \Closure
     */
    protected function buildOutputClosure()
    {
        return function ($type, $data) {
            if (Process::OUT === $type) {
                $this->io->success($data);
            } elseif (Process::ERR === $type) {
                $this->io->error($data);
            }
        };
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array $backups
     * @param $helper
     * @return string
     */
    protected function askBackupFile(
        InputInterface $input,
        OutputInterface $output,
        array $backups,
        $helper
    ): string {
        $backup = '';
        while (empty($backup)) {
            $backupFileNames = array_keys($backups);
            $default = end($backupFileNames);
            $question = new ChoiceQuestion(
                sprintf('Please select your backup (defaults to %s)', $default),
                $backups,
                $default
            );
            $question->setAutocompleterValues($backups);
            $question->setErrorMessage('Backup %s does not exist');
            $backup = $helper->ask($input, $output, $question);
        }
        return $backup;
    }

    /**
     * @param string $tmpFolder
     * @param $backup
     * @param TarCommand $tarCommand
     */
    protected function restoreStorages(
        string $tmpFolder,
        $backup,
        TarCommand $tarCommand
    ): void {
        $legacyFileBackup = $tmpFolder . preg_replace('/-backup$/', '', $backup) . '-files.tgz';
        if (is_file($legacyFileBackup)) {
            $this->legacyRestore($tmpFolder, $legacyFileBackup);
        } else {
            foreach ($this->getOnlineLocalStorages() as $storageInfo) {
                $storageFile = $tmpFolder . $storageInfo['backupFile'];
                if (!file_exists($storageFile)) {
                    continue;
                }
                if (!is_dir($storageInfo['folder'])) {
                    $this->io->error(
                        vsprintf(
                            'Failed to restore storage "%s", root folder "%s" does not exist!!',
                            [
                                $storageInfo['name'],
                                $storageInfo['folder']
                            ]
                        )
                        . PHP_EOL
                    );
                    continue;
                }
                // empty target folder
                shell_exec('rm -r ' . trim($storageInfo['folder']) . '/*');
                $tarCommand->tar(
                    [
                        'zxf',
                        $storageFile,
                        '-C',
                        $storageInfo['folder']
                    ],
                    $this->buildOutputClosure(),
                    $this->processTimeOut
                );

                GeneralUtility::fixPermissions($storageInfo['folder'], true);
                $this->io->success(sprintf('Restored storage "%s"', $storageInfo['name']));
            }
        }
    }

    /**
     * @return array
     */
    public function getOnlineLocalStorages(): array
    {
        /** @var StorageService $storageService */
        $storageService = GeneralUtility::makeInstance(StorageService::class);
        return $storageService->getOnlineLocalStorages();
    }

    /**
     * @param TarCommand $tarCommand
     * @param string $backupFile
     * @param string $tmpFolder
     */
    protected function extractBackupFileToTmpFolder(TarCommand $tarCommand, string $backupFile, string $tmpFolder): void
    {
        $tarCommand->tar(
            [
                'zxf',
                $backupFile,
                '-C',
                $tmpFolder
            ],
            $this->buildOutputClosure(),
            $this->processTimeOut
        );
    }
}
