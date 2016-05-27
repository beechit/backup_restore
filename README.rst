=======================================
EXT:backup_restore TYPO3 Backup/Restore
=======================================

A low-level tool to backup and restore a full TYPO3 installation


**How to use:**

1. Download and install backup_restore in the extension manager
   Or use composer ``composer require beechit/backup-restore`` and install extension in the extension manager

2. Now you have a set of cli commands to backup and restore a TYPO3 install ::

    # create a backup
    ./typo3cms backup:create

    # list existing backups
    ./typo3cms backup:list

    # restore a backup
    ./typo3cms backup:restore 2016-05-19_10-16-dd20a00976208b56

    # create backup with specific name
    ./typo3cms backup:create kickstart


**Requirements:**

    TYPO3 >= 7.6
    ext:typo3_console >= 2.0.0

**Important:**

If no backup path is given the controller tries to create a folder named `backups` 1 level below the web root. When this isn't possible a folder named `backups` is created in typo3temp. *Make sure to secure this folder!*

**Additional config:**

The following ENV variables can be used to define custom paths to the needed binaries:

    path_tar_bin="/usr/bin/tar"
    path_mysqldump_bin="/usr/bin/mysqldump"
    path_mysql_bin="/usr/bin/mysql"
