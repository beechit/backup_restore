<?php
namespace BeechIt\BackupRestore\Command;

/*
 * This source file is proprietary property of Beech Applications B.V.
 * Date: 01-10-2015
 * All code (c) Beech Applications B.V. all rights reserved
 */
use Helhum\Typo3Console\Mvc\Controller\CommandController;
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
        $this->createOutputDirectory($backupFolder);
        if (empty($prefix)) {
            $prefix = $this->createPrefix();
        }
        $this->dumpDB($prefix);
        $this->packageFiles($prefix);

        $this->combineBackupFiles($prefix);
    }

    /**
     * List available backups
     *
     * @param string $backupFolder Alternative path of backup folder
     */
    public function listCommand($backupFolder = '')
    {
        $this->createOutputDirectory($backupFolder);

        $this->outputLine('available backups:');
        foreach (glob($this->backupFolder . '*-backup.tgz') as $file) {
            $this->outputLine(preg_replace('/-backup\.tgz/', '', basename($file)));
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
        $this->createOutputDirectory($backupFolder);

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
     * Combine DB + Files backup to 1 file
     *
     * @param string $prefix
     */
    protected function combineBackupFiles($prefix)
    {

        $target = $this->getPath($prefix) . '-backup.tgz';

        $commandParts = [
            'cd ' . $this->backupFolder . '&&',
            $this->getTarBinPath() . ' zcvf',
            $target,
            '-C ' . $this->backupFolder,
            $prefix . '-files.tgz',
            $prefix . '-db.sql',
        ];
        $command = implode(' ', $commandParts);
        shell_exec($command);
        GeneralUtility::fixPermissions($target);

        $this->outputLine('The backup has been saved to "%s" and got a size of "%s".', [$target, GeneralUtility::formatSize(filesize($target))]);

        unlink($this->getPath($prefix) . '-files.tgz');
        unlink($this->getPath($prefix) . '-db.sql');
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
        $this->createOutputDirectory($backupFolder);

        $this->dumpDB($prefix, $backupFolder);
    }

    /**
     * Export files
     *
     * @param string $prefix
     * @param string $backupFolder Alternative path of backup folder
     * @return void
     */
    public function filesCommand($prefix = '', $backupFolder = '')
    {
        if (!$this->checkIfBinaryExists($this->getTarBinPath())) {
            $this->outputLine('Please set correct ENV path_tar_bin to tar binary');
            $this->quit(1);
        }
        $this->createOutputDirectory($backupFolder);

        $this->packageFiles($prefix);
    }

    /**
     * Package files which are used but not part of the git repo
     *
     * @param string $prefix
     * @return void
     */
    protected function packageFiles($prefix)
    {
        $path = PATH_site;
        $target = $this->getPath($prefix) . '-files.tgz';

        $commandParts = [
            'cd ' . $path . ' &&',
            $this->getTarBinPath() . ' zcvf',
            $target,
            '-C ' . $path,
        ];

        /** @var \TYPO3\CMS\Core\Resource\StorageRepository $storageRepository */
        $storageRepository = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\StorageRepository');
        $storages = $storageRepository->findAll();
        /** @var \TYPO3\CMS\Core\Resource\ResourceStorage */
        foreach ($storages as $storage) {
            if ($storage->getDriverType() === 'Local' && $storage->isOnline()) {
                $configuration = $storage->getConfiguration();
                $basePath = '';
                if (!empty($configuration['basePath'])) {
                    $basePath = trim($configuration['basePath'], '/') . '/';
                }
                if ($basePath) {
                    $commandParts[] = $basePath;
                    $storageRecord = $storage->getStorageRecord();
                    $commandParts[] = '--exclude="' . $basePath . ($storageRecord['processingfolder'] ?: \TYPO3\CMS\Core\Resource\ResourceStorageInterface::DEFAULT_ProcessingFolder) . '"';
                }
            }
        }

        $command = implode(' ', $commandParts);
        shell_exec($command);
        shell_exec('chmod 664 ' . $target);

        $this->outputLine('The files have been saved to "%s" and got a size of "%s".', [$target, GeneralUtility::formatSize(filesize($target))]);
    }

    /**
     * Export the complete DB using mysqldump
     *
     * @param string $prefix
     * @return void
     */
    protected function dumpDB($prefix)
    {
        $dbData = $GLOBALS['TYPO3_CONF_VARS']['DB'];
        $path = $this->getPath($prefix) . '-db.sql';

        $commandParts = [
            $this->getMysqlDumpBinPath() . ' --host=' . $dbData['host'],
            '--user=' . $dbData['username'],
            '--password="' . str_replace('"', '\"', $dbData['password']) . '"',
            $dbData['database']
        ];

        foreach ($this->getNotNeededTables() as $tableName) {
            $commandParts[] = '--ignore-table=' . $dbData['database'] . '.' . $tableName;
        }
        $commandParts[] = ' > ' . $path;
        $command = implode(' ', $commandParts);

        shell_exec($command);
        shell_exec('chmod 664 ' . $path);

        $this->outputLine('The dump has been saved to "%s" and got a size of "%s".', [$path, GeneralUtility::formatSize(filesize($path))]);
    }

    /**
     * Export the complete DB using mysqldump
     *
     * @param string $sqlFile
     * @return void
     */
    protected function restoreDB($sqlFile)
    {
        $dbData = $GLOBALS['TYPO3_CONF_VARS']['DB'];

        $commandParts = [
            $this->getMysqlBinPath() . ' --host=' . $dbData['host'],
            '--user=' . $dbData['username'],
            '--password="' . str_replace('"', '\"', $dbData['password']) . '"',
            $dbData['database'] . ' < ' . $sqlFile
        ];

        $command = implode(' ', $commandParts);
        shell_exec($command);

        $this->outputLine('The db has been restored');
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
     * Create directory
     *
     * @param string $backupFolder
     * @return void
     */
    protected function createOutputDirectory($backupFolder)
    {
        $this->backupFolder = rtrim(PathUtility::getCanonicalPath($backupFolder ?: $this->getDefaultBackupFolder()), '/') . '/';
        if (!is_dir($this->backupFolder)) {
            GeneralUtility::mkdir_deep($this->backupFolder);
        }
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
}
