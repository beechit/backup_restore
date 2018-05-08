<?php
return [
    'controllers' => [
        \BeechIt\BackupRestore\Command\BackupCommandController::class,
    ],
    'runLevels' => [
        'backup_restore:backup:restore' => \Helhum\Typo3Console\Core\Booting\RunLevel::LEVEL_MINIMAL,
    ],
    'bootingSteps' => [
        'backup_restore:backup:restore' => [
            'helhum.typo3console:database',
            'helhum.typo3console:persistence',
        ],
    ]
];
