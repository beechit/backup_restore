<?php
/*
 * This source file is proprietary of Beech Applications bv.
 * Created by: Ruud Silvrants
 * Date: 01/04/2020
 * All code (c) Beech Applications bv. all rights reserverd
 */

namespace BeechIt\BackupRestore\Command;


use BeechIt\BackupRestore\Service\BackupFileService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class BackupListCommand extends \Symfony\Component\Console\Command\Command
{
    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setDescription('List available backups');
        $this->addArgument('backupFolder', InputArgument::OPTIONAL, 'Alternative path of backup folder', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $backupFolder = $input->getArgument('backupFolder');
        $io = new SymfonyStyle($input, $output);
        $io->success('Available backups:');
        /** @var BackupFileService $backupService */
        $backupService = GeneralUtility::makeInstance(BackupFileService::class, $backupFolder);
        $availableBackups = $backupService->getAvailableBackupFiles();
        foreach ($availableBackups as $backup) {
            $io->success($backup);
        }
        $io->newLine(1);
    }
}