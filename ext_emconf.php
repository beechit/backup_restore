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
    'version' => '2.0.0',
    'constraints' => [
        'depends' => [
            'php' => '7.0',
            'typo3' => '8.7.0',
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

