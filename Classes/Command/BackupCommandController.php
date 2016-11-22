<?php
namespace BeechIt\BackupRestore\Command;

/*
 * This source file is proprietary property of Beech Applications B.V.
 * Date: 01-10-2015
 * All code (c) Beech Applications B.V. all rights reserved
 */
use BeechIt\BackupRestore\Database\Process\MysqlCommand;
use BeechIt\BackupRestore\File\Process\TarCommand;
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
     * @var array temp files (removed after destruction of object)
     */
    protected $tmpFiles = [];

    /**
     * Export files + database
     *
     * @param string $prefix
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

        $tarCommand = new TarCommand(new ProcessBuilder());
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
        foreach (glob($this->backupFolder . '*.tgz') as $file) {
            $this->outputLine(preg_replace('/\.tgz/', '', basename($file)));
        }

        $this->outputLine('');
    }

    /**
     * Restore backup
     *
     * @param string $backup
     * @param string $backupFolder
     */
    public function restoreCommand($backup, $backupFolder = '')
    {
        if (!$this->checkIfBinaryExists($this->getTarBinPath())) {
            $this->outputLine('Please set correct ENV path_tar_bin to tar binary');
            $this->quit(1);
        }
        if (!$this->checkIfBinaryExists($this->getMysqlBinPath())) {
            $this->outputLine('Please set correct ENV path_mysql_bin to mysql binary');
            $this->quit(1);
        }
        $this->setBackupFolder($backupFolder);

        if (preg_match('/-backup$/', $backup)) {
            $this->legacyRestore($backup);
            return;
        }

        $tmpFolder = $this->getTempFolder() . preg_replace('/\.tgz$/', '', $backup) . '/';
        GeneralUtility::mkdir($tmpFolder);

        $backupFile = $this->backupFolder . $backup . '.tgz';

        if (!file_exists($backupFile)) {
            $this->outputLine('Backup ' . $backup . ' (' . $backupFile . ') not found!!');
            $this->quit(1);
        }

        $tarCommand = new TarCommand(new ProcessBuilder());
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
            $this->restoreDB($tmpFolder . 'db.sql');
        }

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

        // Cleanup tmp folder
        shell_exec('rm -r ' . $tmpFolder);
    }

    /**
     * Restore legacy format backups
     *
     * @param string $backup
     */
    protected function legacyRestore($backup) {

        // strip off default suffix -backup.tgz from backup name
        $backup = preg_replace('/-backup\.tgz$/', '', $backup);

        // Create full path to backup file
        $backupFile = $this->backupFolder . $backup;
        $tmpFolder = rtrim($this->backupFolder . $backup, '/') . '/';

        if (!file_exists($backupFile)) {
            $backupFile .= '-backup.tgz';
        }

        if (!file_exists($backupFile)) {
            $this->outputLine('Backup ' . $backup . '(' . $backupFile . ') not found!!');
            $this->quit(1);
        }

        // Create tmp folder
        GeneralUtility::mkdir($tmpFolder);
        GeneralUtility::mkdir($tmpFolder . 'file-storages/');

        shell_exec($this->getTarBinPath() . ' zxf ' . $backupFile . ' -C ' . $tmpFolder);
        shell_exec($this->getTarBinPath() . ' zxf ' . $tmpFolder . $backup . '-files.tgz -C ' . $tmpFolder . 'file-storages/');

        foreach (glob($tmpFolder . 'file-storages/*', GLOB_ONLYDIR) as $folder) {
            $this->outputLine('restore file storage: ' . basename($folder));

            // todo: cleanup removed files from storage
            // todo: get target path from sys_file_storages like packageFiles() instead of PATH_site
            shell_exec('cp -R ' . $folder . ' ' . PATH_site);
        }


        $this->restoreDB($tmpFolder . $backup . '-db.sql');

        // Cleanup tmp folder
        shell_exec('rm -r ' . $tmpFolder);
    }

    /**
     * Export database
     *
     * @param string $prefix
     * @param string $backupFolder Alternative path of backup folder
     * @return void
     */
    public function dbCommand($prefix = '', $backupFolder = '')
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
        $tarCommand = new TarCommand(new ProcessBuilder());
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

        // @todo: do something with $exitCode

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
    protected function restoreDB($sqlFile)
    {
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
