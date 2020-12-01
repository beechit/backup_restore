<?php
/*
 * This source file is proprietary of Beech Applications bv.
 * Created by: Ruud Silvrants
 * Date: 01/04/2020
 * All code (c) Beech Applications bv. all rights reserverd
 */

namespace BeechIt\BackupRestore\Service;


use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class BackupFileService
{

    /** @var string */
    protected $backupFolderStorage = '';

    /**
     * Default backup folder relative to web root of site
     */
    const PATH = '/backups/';

    /**
     * BackupFileService constructor.
     * @param string $backupFolderStorage
     */
    public function __construct(string $backupFolderStorage)
    {
        $this->setBackupFolder($backupFolderStorage);
    }

    /**
     * @return array
     */
    public function getAvailableBackupFiles(): array
    {
        $backups = [];
        foreach (glob($this->backupFolderStorage . '*.tgz') as $file) {
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
     * @param $backupFolder
     */
    protected function setBackupFolder($backupFolder)
    {
        $backupFolder = $backupFolder ?: $this->getDefaultBackupFolder();
        $this->backupFolderStorage = rtrim(PathUtility::getCanonicalPath($backupFolder), '/') . '/';
        if (!is_dir($this->backupFolderStorage)) {
            GeneralUtility::mkdir_deep($this->backupFolderStorage);
        }
    }

    /**
     * @return string
     */
    public function getBackupFolder(): string
    {
        return $this->backupFolderStorage;
    }

    /**
     * @return string
     */
    public function getTmpFolder()
    {
        $tmpFolder = $this->getBackupFolder() . 'temp/';
        if (!is_dir($tmpFolder)) {
            GeneralUtility::mkdir_deep($tmpFolder);
        }
        return $tmpFolder;
    }

    public function removeFolder($folderName)
    {
        shell_exec('rm -r ' . $folderName);
    }

    /**
     * @return string
     */
    public function getDefaultBackupFolder(): string
    {
        $path = rtrim(PathUtility::getCanonicalPath(Environment::getProjectPath() . self::PATH), '/') . '/';

        if (is_writable($path) || is_writable(\dirname($path))) {
            return $path;
        }
        return Environment::getPublicPath() . '/typo3temp/backups';
    }
}