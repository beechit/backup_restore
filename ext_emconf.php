<?php

/***************************************************************
 * Extension Manager/Repository config file for ext "backup_restore".
 ***************************************************************/

$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 Backup/Restore',
    'description' => 'A low-level tool to backup and restore a full TYPO3 installation',
    'category' => 'cli',
    'state' => 'stable',
    'uploadfolder' => true,
    'createDirs' => '',
    'clearCacheOnLoad' => true,
    'author' => 'Frans Saris',
    'author_email' => 't3ext@beech.it',
    'author_company' => 'Beech.it',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'php' => '5.5',
            'typo3' => '7.6.0-8.0.99',
            'typo3_console' => '3.0.0',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'BeechIt\\BackupRestore\\' => 'Classes',
        ],
    ],
];

