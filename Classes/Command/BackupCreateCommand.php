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
use Helhum\Typo3Console\Database\Configuration\ConnectionConfiguration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class BackupCommandController
 */
class BackupCreateCommand extends \Symfony\Component\Console\Command\Command
{

    /**
     * @var int processTimeOut in seconds, default on 10 minutes.
     * This variable can be override by setting the environment variable:BACKUP_PROCESS_TIME_OUT
     * For example: BACKUP_PROCESS_TIME_OUT="30000" php typo3cms backup:create
     */
    protected $processTimeOut = 600;

    /**
     * @var BackupFileService
     */
    protected $backupFileService;

    /**
     * @var ConnectionConfiguration
     */
    protected $connectionConfiguration;

    /**
     * @var array temp files (removed after destruction of object)
     */
    protected $tmpFiles = [];

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * BackupCreateCommand constructor.
     * @param string|null $name
     * @param ConnectionConfiguration $connectionConfiguration
     */
    public function __construct(
        string $name = null,
        ConnectionConfiguration $connectionConfiguration = null
    ) {
        parent::__construct($name);
        $this->connectionConfiguration = $connectionConfiguration ?: new ConnectionConfiguration();
    }

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setDescription('Create backup of file storages + database');
        $this->addArgument('prefix', InputArgument::OPTIONAL, 'Specific prefix (name) to use for backup file', '');
        $this->addArgument('backupFolder', InputArgument::OPTIONAL, 'Alternative path of backup folder', '');
        $this->setHelp('Dump database excluding cache and some log tables and pack contents of all local file storages (including legacy folder uploads) into 1 backup file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // TODO check if the backup_process_time_out is still relevant
        $processTimeOutEnv = getenv('BACKUP_PROCESS_TIME_OUT');
        if (!empty($processTimeOutEnv)) {
            $this->processTimeOut = $processTimeOutEnv;
        }

        $prefix = $input->getArgument('prefix');
        $backupFolder = $input->getArgument('backupFolder');
        $this->io = new SymfonyStyle($input, $output);
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
        if (empty($prefix)) {
            $prefix = $this->createPrefix();
        }

        $target = $this->getPath($prefix) . '.tgz';
        $tmpFolder = $this->backupFileService->getTmpFolder();
        $dbDump = $this->dumpDB($tmpFolder);
        $storageFiles = $this->packageFiles($tmpFolder);

        $tarCommand = new TarCommand();
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
        $this->io->success(sprintf('Created "%s" (%s B)', $target, GeneralUtility::formatSize(filesize($target), 'si')));
        return 0; // everything ok
    }

    /**
     * Package files which are used but not part of the git repo
     *
     * @param string $tmpFolder
     * @return array
     */
    protected function packageFiles($tmpFolder): array
    {
        $tarCommand = new TarCommand();
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
            $this->io->success(
                sprintf('Packed storage "%s" (%sB)',
                    $storageInfo['name'],
                    GeneralUtility::formatSize(filesize($tmpFolder . $file), 'si')
                )
            );

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
        /** @var StorageService $storageService */
        $storageService = GeneralUtility::makeInstance(StorageService::class);
        return $storageService->getOnlineLocalStorages();
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
            $dbConfig
        );
        $dbDumpFile = 'db.sql';
        $path = $tmpFolder . $dbDumpFile;
        $commandParts = [];
        foreach ($this->getUnNeededTables() as $tableName) {
            $commandParts[] = '--ignore-table=' . $dbConfig['dbname'] . '.' . $tableName;
        }

        $exitCode = $mysqlCommand->mysqldump(
            $commandParts,
            $this->buildOutputToFileClosure($path)
        );

        if ($exitCode) {
            $this->io->error('Database backup failed');
            $this->quit(1);
        }

        $this->io->success(sprintf('Database dump created (%s)B', GeneralUtility::formatSize(filesize($path), 'si')));

        $this->tmpFiles[] = $path;

        return $dbDumpFile;
    }

    /**
     * @param $file
     * @return \Closure
     */
    protected function buildOutputToFileClosure($file): callable
    {
        // empty file
        file_put_contents($file, '');

        return function ($type, $data) use ($file) {
            if (Process::OUT === $type) {
                file_put_contents($file, $data, FILE_APPEND);
            } elseif (Process::ERR === $type) {
                $this->io->error($data);
            }
        };
    }

    /**
     * @return \Closure
     */
    protected function buildOutputClosure(): callable
    {
        return function ($type, $data) {
            if (Process::OUT === $type) {
                $this->io->writeln($data);
            } elseif (Process::ERR === $type) {
                $this->io->error($data);
            }
        };
    }

    /**
     * Get all tables which might be truncated
     *
     * @return array
     */
    protected function getUnNeededTables(): array
    {
        /** @var DatabaseTableService $databaseTableService */
        $databaseTableService = GeneralUtility::makeInstance(DatabaseTableService::class);
        return $databaseTableService->getUnNeededTables();
    }


    /**
     * Return the path, including timestamp + a random value
     *
     * @param string $prefix
     * @return string
     */
    protected function getPath($prefix): string
    {
        $path = $this->backupFileService->getBackupFolder();

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
    protected function createPrefix(): string
    {
        return date('Y-m-d_h-i') . '-' . GeneralUtility::makeInstance(Random::class)->generateRandomHexString(16);
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
