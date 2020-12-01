<?php
/*
 * This source file is proprietary of Beech Applications bv.
 * Created by: Ruud Silvrants
 * Date: 01/04/2020
 * All code (c) Beech Applications bv. all rights reserverd
 */

namespace BeechIt\BackupRestore\Utility;


class BinaryUtility
{
    /**
     * Check if given binary exists
     *
     * @param string $binary
     * @return bool
     */
    public static function checkIfBinaryExists($binary): bool
    {
        $returnVal = shell_exec('which ' . $binary);
        return (empty($returnVal) ? false : true);
    }
}