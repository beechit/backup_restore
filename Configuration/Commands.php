<?php


return [
    'backuprestore:restore' => [
        'class' => \BeechIt\BackupRestore\Command\BackupRestoreCommand::class,
        'schedulable' => false,
    ],
    'backuprestore:create' => [
        'class' => \BeechIt\BackupRestore\Command\BackupCreateCommand::class,
        'schedulable' => false,
    ],
    'backuprestore:list' => [
        'class' => \BeechIt\BackupRestore\Command\BackupListCommand::class,
        'schedulable' => false,
    ],
];