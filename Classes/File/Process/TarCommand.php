<?php

namespace BeechIt\BackupRestore\File\Process;

/*
 * This source file is proprietary property of Beech Applications B.V.
 * Date: 21-11-2016
 * All code (c) Beech Applications B.V. all rights reserved
 */

use Symfony\Component\Process\Process;

/**
 * Class TarCommand
 */
class TarCommand
{

    /**
     * @param array $additionalArguments
     * @param null $outputCallback
     * @return int
     */
    public function tar(array $additionalArguments = [], $outputCallback = null): int
    {
        $processCommand = array_merge([self::getTarBinPath()], $additionalArguments);
        $process = new Process($processCommand);
        $process->setTimeout(600);
        return $process->run($this->buildDefaultOutputCallback($outputCallback));
    }

    /**
     * @param callable $outputCallback
     * @return callable
     */
    protected function buildDefaultOutputCallback($outputCallback): callable
    {
        if (!\is_callable($outputCallback)) {
            $outputCallback = function ($type, $output) {
                if (Process::OUT === $type) {
                    // Explicitly just echo out for now (avoid symfony console formatting)
                    echo $output;
                }
            };
        }
        return $outputCallback;
    }

    /**
     * Get path to tar bin
     *
     * @return string
     */
    public static function getTarBinPath(): string
    {
        if (getenv('path_tar_bin')) {
            return getenv('path_tar_bin');
        }

        return 'tar';
    }

}
