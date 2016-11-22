<?php
namespace BeechIt\BackupRestore\File\Process;

    /*
     * This source file is proprietary property of Beech Applications B.V.
     * Date: 21-11-2016
     * All code (c) Beech Applications B.V. all rights reserved
     */
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Class TarCommand
 */
class TarCommand
{
    /**
     * @var ProcessBuilder
     */
    protected $processBuilder;

    /**
     * MysqlCommand constructor.
     *
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(ProcessBuilder $processBuilder)
    {
        $this->processBuilder = $processBuilder;
    }

    /**
     * @param array $additionalArguments
     * @param null $outputCallback
     * @return int
     */
    public function tar(array $additionalArguments = array(), $outputCallback = null)
    {
        $this->processBuilder->setPrefix(self::getTarBinPath());
        $this->processBuilder->setArguments($additionalArguments);

        $process = $this->processBuilder->getProcess();
        return $process->run($this->buildDefaultOutputCallback($outputCallback));
    }

    /**
     * @param callable $outputCallback
     * @return callable
     */
    protected function buildDefaultOutputCallback($outputCallback)
    {
        if (!is_callable($outputCallback)) {
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
    public static function getTarBinPath()
    {
        if (getenv('path_tar_bin')) {
            return getenv('path_tar_bin');
        } else {
            return 'tar';
        }
    }

}
