<?php
namespace BeechIt\BackupRestore\Command;

/*
 * This source file is proprietary property of Beech Applications B.V.
 * Date: 01-10-2015
 * All code (c) Beech Applications B.V. all rights reserved
 */
use BeechIt\BackupRestore\Database\Process\MysqlCommand;
use BeechIt\BackupRestore\File\Process\TarCommand;
use Helhum\Typo3Console\Database\Schema\SchemaUpdateType;
use Helhum\Typo3Console\Mvc\Controller\CommandController;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Class BackupCommandController
 */
class BackupCommandController extends CommandController
{

    /**
     * @var int processTimeOut in seconds, default on 2 minutes.
     * This variable can be override by setting the environment variable:BACKUP_PROCESS_TIME_OUT
     * For example: BACKUP_PROCESS_TIME_OUT="300" php typo3cms backup:create
     */
    protected $processTimeOut = 120;

    /**
     * Default backup folder relative to web root of site
     */
    const PATH = '../backups/';

    /**
     * @var string
     */
    protected $backupFolder = '';

    /**
     * @var \Helhum\Typo3Console\Database\Configuration\ConnectionConfiguration
     * @inject
     */
    protected $connectionConfiguration;

    /**
     * @var \Helhum\Typo3Console\Service\Database\SchemaService
     * @inject
     */
    protected $schemaService;

    /**
     * @var array temp files (removed after destruction of object)
     */
    protected $tmpFiles = [];

    public function __construct()
    {
        $processTimeOutEnv = getenv('BACKUP_PROCESS_TIME_OUT');
        if (!empty($processTimeOutEnv)) {
            $this->processTimeOut = $processTimeOutEnv;
        }
    }

    /**
     * Create backup of file storages + database
     *
     * Dump database excluding cache and some log tables and pack
     * contents of all local file storages (including legacy folder uploads)
     * into 1 backup file.
     *
     * @param string $prefix Specific prefix (name) to use for backup file
     * @param string $backupFolder Alternative path of backup folder
     * @return void
     */
    public function createCommand($prefix = '', $backupFolder = '')
    {
        if (!$this->checkIfBinaryExists($this->getTarBinPath())) {
            $this->outputLine('Please set correct ENV path_tar_bin to tar binary');
            $this->quit(1);
        }
        if (!$this->checkIfBinaryExists($this->getMysqlDumpBinPath())) {
            $this->outputLine('Please set correct ENV path_mysqldump_bin to mysqldump binary');
            $this->quit(1);
        }
        $this->setBackupFolder($backupFolder);
        if (empty($prefix)) {
            $prefix = $this->createPrefix();
        }

        $target = $this->getPath($prefix) . '.tgz';
        $tmpFolder = $this->getTempFolder();
        $dbDump = $this->dumpDB($tmpFolder);
        $storageFiles = $this->packageFiles($tmpFolder);

        $processBuilder = new ProcessBuilder();
        $processBuilder->setTimeout($this->processTimeOut);
        $tarCommand = new TarCommand($processBuilder);
        $tarCommand->tar(
            array_merge([
                'zcf',
                $target,
                '--directory',
                $tmpFolder,
                $dbDump,
            ],
                $storageFiles
            )
        );
        GeneralUtility::fixPermissions($target);

        $this->outputLine('Created "%s" (%s)', [$target, GeneralUtility::formatSize(filesize($target), 'si') . 'B']);
    }

    /**
     * List available backups
     *
     * @param string $backupFolder Alternative path of backup folder
     */
    public function listCommand($backupFolder = '')
    {
        $this->setBackupFolder($backupFolder);

        $this->outputLine('available backups:');
        foreach ($this->getAvailableBackups() as $backup) {
            $this->outputLine($backup);
        }

        $this->outputLine('');
    }

    /**
     * Restore file storages + database from backup
     *
     * @param string $backup Name of backup (with or without file extension)
     * @param string $backupFolder Alternative path of backup folder
     * @param bool $plainRestore Restore db without sanitizing and merging with local tables
     * @param bool $force Force restore in Production context
     */
    public function restoreCommand($backup = '', $backupFolder = '', $plainRestore = false, $force = false)
    {
        if (!$force && (string)GeneralUtility::getApplicationContext() === 'Production') {
            $this->outputLine('<error>Restore is not possible in <em>Production</em> context without the <i>--force</i> option</error>');
            $this->quit(1);
        }
        if (!$this->checkIfBinaryExists($this->getTarBinPath())) {
            $this->outputLine('Please set correct ENV path_tar_bin to tar binary');
            $this->quit(1);
        }
        if (!$this->checkIfBinaryExists($this->getMysqlBinPath())) {
            $this->outputLine('Please set correct ENV path_mysql_bin to mysql binary');
            $this->quit(1);
        }

        $this->setBackupFolder($backupFolder);

        while(empty($backup)) {
            $backups = $this->getAvailableBackups();
            if ($backups === []) {
                $this->output->outputLine('<error>No backups found in "%s" to restore!</error>', [$this->backupFolder]);
                $this->quit(1);
            }
            $default = end(array_keys($backups));
            $backup = $this->output->select(
                'Select backup [' . $default .']:',
                $backups,
                $default,
                false,
                99
            );
        }

        // We expect the backup name without leading extension so be sure it is stripped
        $backup = preg_replace('/\.tgz$/', '', $backup);
        $tmpFolder = $this->getTempFolder() . $backup . '/';
        GeneralUtility::mkdir($tmpFolder);

        $backupFile = $this->backupFolder . $backup . '.tgz';

        if (!file_exists($backupFile)) {
            $this->outputLine('Backup ' . $backup . ' (' . $backupFile . ') not found!!');
            $this->quit(1);
        }
        $processBuilder = new ProcessBuilder();
        $processBuilder->setTimeout($this->processTimeOut);
        $tarCommand = new TarCommand($processBuilder);
        $tarCommand->tar(
            [
                'zxf',
                $backupFile,
                '-C',
                $tmpFolder
            ],
            $this->buildOutputClosure()
        );

        if (is_file($tmpFolder . 'db.sql')) {
            $this->restoreDB($tmpFolder . 'db.sql', $plainRestore);
        } else {
            // legacy db dump
            $legacyDbDump = $tmpFolder . preg_replace('/-backup$/', '', $backup) . '-db.sql';
            if (is_file($legacyDbDump)){
                $this->restoreDB($legacyDbDump, $plainRestore);
            }
        }

        $legacyFileBackup = $tmpFolder . preg_replace('/-backup$/', '', $backup) . '-files.tgz';
        if (is_file($legacyFileBackup)) {
            $this->legacyRestore($tmpFolder, $legacyFileBackup);
        } else {

            foreach ($this->getStorageInfo() as $storageInfo) {
                $storageFile = $tmpFolder . $storageInfo['backupFile'];
                if (!file_exists($storageFile)) {
                    continue;
                }

                // restore files
                if (is_dir($storageInfo['folder'])) {

                    // empty target folder
                    shell_exec('rm -r ' . trim($storageInfo['folder']) . '/*');

                    $tarCommand->tar(
                        [
                            'zxf',
                            $storageFile,
                            '-C',
                            $storageInfo['folder']
                        ],
                        $this->buildOutputClosure()
                    );

                    $this->outputLine('Restore storage "%s"', [$storageInfo['name']]);
                } else {
                    $output = $this->output->getSymfonyConsoleOutput();
                    $output->getErrorOutput()->write(
                        vsprintf(
                            '[!!!] Failed to restore storage "%s", root folder "%s" does not exist!!',
                            [
                                $storageInfo['name'],
                                $storageInfo['folder']
                            ]
                        )
                        . PHP_EOL
                    );
                }
            }
        }

        // Cleanup tmp folder
        shell_exec('rm -r ' . $tmpFolder);
    }

    /**
     * Restore legacy format backups
     *
     * @param string $tmpFolder folder to temporary extract the files
     * @param string $fileBackups path of files tgz file
     */
    protected function legacyRestore($tmpFolder, $fileBackups) {

        $tmpFolder .= 'file-storages/';

        // Create tmp folder
        GeneralUtility::mkdir($tmpFolder);

        $processBuilder = new ProcessBuilder();
        $processBuilder->setTimeout($this->processTimeOut);
        $tarCommand = new TarCommand($processBuilder);
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
            $this->outputLine('restore file storage: ' . basename($folder));

            // todo: cleanup removed files from storage
            // todo: get target path from sys_file_storages like packageFiles() instead of PATH_site
            shell_exec('cp -R ' . $folder . ' ' . PATH_site);
        }

        // Cleanup tmp folder
        shell_exec('rm -r ' . $tmpFolder);
    }

    /**
     * Copy db tables we merge with restored database later
     *
     * @todo: make it possible to extend this through configuration
     */
    protected function createCopyOfTablesToMerge()
    {
        $db = $this->getDatabaseConnection();
        foreach (['sys_domain', 'be_users'] as $table) {
            $db->sql_query('CREATE TABLE ' . $table . '_local LIKE ' . $table);
            $db->sql_query('INSERT INTO ' . $table . '_local SELECT * FROM ' . $table);
            $this->output->outputLine('Created <i>%s</i>', [$table . '_local']);
        }
    }

    /**
     * Sanitize restored tables
     *
     * @todo: make it possible to extend this through configuration
     */
    protected function sanitizeRestoredTables()
    {
        $db = $this->getDatabaseConnection();

        $availableTables = $db->admin_get_tables();
        if (isset($availableTables['fe_users'])) {
            $db->sql_query('UPDATE fe_users SET username = MD5(username), password = MD5(password), email = CONCAT(LEFT(UUID(), 8), "@beech.it") WHERE email NOT LIKE "%@beech.it"');

            $this->output->outputLine('<info>Sanitized `fe_users` table</info>');
        }
    }

    /**
     * Merge restored tables with local tables
     *
     * @todo: make it possible to extend this through configuration
     */
    protected function mergeRestoredTablesWithLocalCopies()
    {
        $db = $this->getDatabaseConnection();

        // Keep domain info of local environment
        $db->sql_query('
            UPDATE
                sys_domain AS a
            JOIN
                sys_domain_local AS b
            ON
                a.uid = b.uid
            SET
                a.domainName = b.domainName,
                a.redirectTo = b.redirectTo
        ');

        if ($db->sql_error()) {
            $this->output->outputLine('<error>[SQL ERROR] %s</error>', [$db->sql_error()]);
        } else {
            $this->output->outputLine('<info>Merged sys_domain with local version</info>');
        }

        // Disable all BE users
        $db->sql_query('UPDATE be_users SET disable = 1');

        // Keep BE users info of local environment
        $db->sql_query('
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
                a.deleted = b.deleted
        ');

        if ($db->sql_error()) {
            $this->output->outputLine('<error>[SQL ERROR] %s</error>', [$db->sql_error()]);
        } else {
            $this->output->outputLine('<info>Merged be_users with local version (new added users are disabled)</info>');
        }

        foreach (['sys_domain', 'be_users'] as $table) {
            $db->sql_query('DROP TABLE  ' . $table . '_local');

            $this->output->outputLine('Deleted <i>%s</i>', [$table . '_local']);
        }
    }

    /**
     * Create database dump
     *
     * Dump database excluding cache and some log tables
     *
     * @param string $prefix Specific prefix (name) to use for backup file
     * @param string $backupFolder Alternative path of backup folder
     * @return void
     */
    public function dbCommand($prefix = '', $backupFolder = '')
    {
        if (!$this->checkIfBinaryExists($this->getMysqlDumpBinPath())) {
            $this->outputLine('Please set correct ENV path_mysqldump_bin to mysqldump binary');
            $this->quit(1);
        }
        $this->setBackupFolder($backupFolder);

        $tmpFolder = $this->getTempFolder();
        $dbDumpFile = $this->dumpDB($tmpFolder);

        $target = $this->getPath($prefix) . '.sql';
        rename($tmpFolder . $dbDumpFile, $target);

        $this->outputLine('DB dump saved as "%s"', [$target]);
    }

    /**
     * Package files which are used but not part of the git repo
     *
     * @param string $tmpFolder
     * @return array
     */
    protected function packageFiles($tmpFolder)
    {
        $processBuilder = new ProcessBuilder();
        $processBuilder->setTimeout($this->processTimeOut);
        $tarCommand = new TarCommand($processBuilder);
        $storageFiles = [];

        foreach ($this->getStorageInfo() as $storageInfo) {

            $file = $storageInfo['backupFile'];
            $storageFiles[] = $file;
            $commandArguments =
                [
                    '-zcf',
                    $tmpFolder . $file,
                    '--directory',
                    $storageInfo['folder'],
                    '.',
                ];
            foreach ((array)$storageInfo['exclude'] as $exclude) {
                $commandArguments[] = '--exclude';
                $commandArguments[] = $exclude;
            }

            $tarCommand->tar(
                $commandArguments,
                $this->buildOutputClosure()
            );

            $this->outputLine('Packed storage "%s" (%s)', [$storageInfo['name'], GeneralUtility::formatSize(filesize($tmpFolder . $file), 'si') . 'B']);

            $this->tmpFiles[] = $tmpFolder . $file;
        }

        return $storageFiles;
    }

    /**
     * Get available backups
     *
     * @return array
     */
    protected function getAvailableBackups()
    {
        $backups = [];
        foreach (glob($this->backupFolder . '*.tgz') as $file) {
            $backup = preg_replace('/\.tgz/', '', basename($file));
            $backups[$backup] = vsprintf(
                '%s (%sB - %s)',
                [
                    $backup,
                    GeneralUtility::formatSize(filesize($file), 'si'),
                    date('Y-m-d H:i:s', filemtime($file))
                ]
            );
        }
        return $backups;
    }

    /**
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function getStorageInfo()
    {
        $storageInfo = [];

        // Default "legacy" upload folder
        $storageInfo[] = [
            'id' => 'uploads',
            'name' => 'uploads',
            'folder' => realpath(PATH_site . 'uploads'),
            'exclude' => [],
            'backupFile' => 'uploads.tgz',
        ];

        $table = 'sys_file_storage';
        $storages = $this->getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            $table,
            '1=1'
            . \TYPO3\CMS\Backend\Utility\BackendUtility::BEenableFields($table)
            . \TYPO3\CMS\Backend\Utility\BackendUtility::deleteClause($table),
            '',
            'name',
            '',
            'uid'
        );

        foreach ((array)$storages as $storageRecord) {
            if ($storageRecord['driver'] !== 'Local' || empty($storageRecord['is_online'])) {
                continue;
            }
            $configuration = ResourceFactory::getInstance()->convertFlexFormDataToConfigurationArray($storageRecord['configuration']);
            $basePath = '';
            if (!empty($configuration['basePath'])) {
                $basePath = rtrim($configuration['basePath'], '/');
            }
            if (strpos($basePath, '/') !== 0) {
                $basePath = PATH_site . $basePath;
            }
            if ($basePath) {
                $storageInfo[] = [
                    'id' => $storageRecord['uid'],
                    'name' => $storageRecord['name'],
                    'folder' => $basePath,
                    'exclude' => [$storageRecord['processingfolder'] ?: \TYPO3\CMS\Core\Resource\ResourceStorageInterface::DEFAULT_ProcessingFolder],
                    'backupFile' => 'storage-' . $storageRecord['uid'] . '.tgz',
                ];
            }
        }

        return $storageInfo;
    }

    /**
     * Export the complete DB using mysqldump
     *
     * @param string $tmpFolder
     * @return string
     */
    protected function dumpDB($tmpFolder)
    {
        $dbConfig = $this->connectionConfiguration->build();
        $mysqlCommand = new MysqlCommand(
            $dbConfig,
            new ProcessBuilder()
        );
        $dbDumpFile = 'db.sql';
        $path = $tmpFolder . $dbDumpFile;
        $commandParts = [];
        foreach ($this->getNotNeededTables() as $tableName) {
            $commandParts[] = '--ignore-table=' . $dbConfig['dbname'] . '.' . $tableName;
        }

        $exitCode = $mysqlCommand->mysqldump(
            $commandParts,
            $this->buildOutputToFileClosure($path)
        );

        if ($exitCode) {
            $this->output->outputLine('<error>Database backup failed</error>');
            $this->quit(1);
        }

        $this->outputLine('Database dump created (%s)', [GeneralUtility::formatSize(filesize($path), 'si') . 'B']);

        $this->tmpFiles[] = $path;

        return $dbDumpFile;
    }

    /**
     * Restore the complete DB using mysql
     *
     * @param string $sqlFile
     * @return void
     */
    protected function restoreDB($sqlFile, $plainRestore)
    {
        if (!$plainRestore) {
            $this->createCopyOfTablesToMerge();
        }

        $dbConfig = $this->connectionConfiguration->build();
        $mysqlCommand = new MysqlCommand(
            $dbConfig,
            new ProcessBuilder()
        );

        $exitCode = $mysqlCommand->mysql(
            [],
            // @todo: improve to real resource input
            file_get_contents($sqlFile),
            $this->buildOutputClosure()
        );

        if (!$exitCode) {
            $this->outputLine('The db has been restored');

            if (!$plainRestore) {
                $this->sanitizeRestoredTables();
                $this->mergeRestoredTablesWithLocalCopies();
            }

            // Update DB to be sure all needed tables are present
            try {
                $schemaUpdateTypes = SchemaUpdateType::expandSchemaUpdateTypes(['*.add', '*.change']);
                $result = $this->schemaService->updateSchema($schemaUpdateTypes);
                if ($result->hasPerformedUpdates()) {
                    $this->output->outputLine('<info>Updated db to be inline with extension configuration</info>');
                }
            } catch (\UnexpectedValueException $e) {
                $this->outputLine('<error>Failed to update db: %s</error>', [$e->getMessage()]);
            }
        }

        return $exitCode;
    }

    /**
     * @return \Closure
     */
    protected function buildOutputToFileClosure($file)
    {
        // empty file
        file_put_contents($file, '');

        return function ($type, $data) use ($file) {
            $output = $this->output->getSymfonyConsoleOutput();
            if (Process::OUT === $type) {
                file_put_contents($file, $data, FILE_APPEND);
            } elseif (Process::ERR === $type) {
                $output->getErrorOutput()->write($data);
            }
        };
    }

    /**
     * @return \Closure
     */
    protected function buildOutputClosure()
    {
        return function ($type, $data) {
            $output = $this->output->getSymfonyConsoleOutput();
            if (Process::OUT === $type) {
                $output->write($data);
            } elseif (Process::ERR === $type) {
                $output->getErrorOutput()->write($data);
            }
        };
    }

    /**
     * Get all tables which might be truncated
     *
     * @return array
     */
    protected function getNotNeededTables()
    {
        $tables = [];

        $truncatedPrefixes = ['cf_', 'cache_', 'zzz_', 'tx_extensionmanager_domain_model_extension', 'tx_extensionmanager_domain_model_repository', 'tx_realurl_errorlog', 'sys_lockedrecords', 'be_sessions'];

        $tableList = array_keys($GLOBALS['TYPO3_DB']->admin_get_tables());
        foreach ($tableList as $tableName) {
            $found = FALSE;
            foreach ($truncatedPrefixes as $prefix) {
                if ($found || GeneralUtility::isFirstPartOfStr($tableName, $prefix)) {
                    $tables[$tableName] = $tableName;
                    $found = TRUE;
                }
            }
        }

        return $tables;
    }

    /**
     * Set backup folder
     * When no specific folder is given the default folder is used
     *
     * @param string $backupFolder
     * @return void
     */
    protected function setBackupFolder($backupFolder = '')
    {
        $this->backupFolder = rtrim(PathUtility::getCanonicalPath($backupFolder ?: $this->getDefaultBackupFolder()), '/') . '/';
        if (!is_dir($this->backupFolder)) {
            GeneralUtility::mkdir_deep($this->backupFolder);
        }
    }

    /**
     * @return string
     */
    protected function getTempFolder()
    {
        $tmpFolder = $this->backupFolder . 'temp/';
        if (!is_dir($tmpFolder)) {
            GeneralUtility::mkdir_deep($tmpFolder);
        }
        return $tmpFolder;
    }

    /**
     * Gets the default backupFolder
     *
     * @return string
     */
    private function getDefaultBackupFolder()
    {
        $path = rtrim(PathUtility::getCanonicalPath(PATH_site . self::PATH), '/') . '/';

        if (is_writable($path) || is_writable(dirname($path))) {
            return $path;
        } else {
            return PATH_site . 'typo3temp/backups';
        }
    }

    /**
     * Return the path, including timestamp + a random value
     *
     * @param string $prefix
     * @return string
     */
    protected function getPath($prefix)
    {
        $path = $this->backupFolder;

        if (!empty($prefix) && preg_match('/^[a-z0-9_\\-]{2,}$/i', $prefix)) {
            $path .= $prefix;
        } else {
            $path .= $this->createPrefix();
        }

        return $path;
    }

    /**
     * Create unique backup prefix name
     *
     * @return string
     */
    protected function createPrefix()
    {
        return date('Y-m-d_h-i') . '-' . GeneralUtility::getRandomHexString(16);
    }

    /**
     * Get path to mysql bin
     *
     * @return string
     */
    protected function getMysqlBinPath()
    {
        if (getenv('path_mysql_bin')) {
            return getenv('path_mysql_bin');
        } else {
            return 'mysql';
        }
    }

    /**
     * Get path to mysqldump bin
     *
     * @return string
     */
    protected function getMysqlDumpBinPath()
    {
        if (getenv('path_mysqldump_bin')) {
            return getenv('path_mysqldump_bin');
        } else {
            return 'mysqldump';
        }
    }

    /**
     * Get path to tar bin
     *
     * @return string
     */
    protected function getTarBinPath()
    {
        if (getenv('path_tar_bin')) {
            return getenv('path_tar_bin');
        } else {
            return 'tar';
        }
    }

    /**
     * Check if given binary exists
     *
     * @param string $binary
     * @return bool
     */
    protected function checkIfBinaryExists($binary)
    {
        $returnVal = shell_exec('which ' . $binary);
        return (empty($returnVal) ? false : true);
    }

    /**
     * @return DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
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
}
