<?php
namespace BeechIt\BackupRestore\Database\Process;

/*
 * This source file is proprietary property of Beech Applications B.V.
 * Date: 01-10-2015
 * All code (c) Beech Applications B.V. all rights reserved
 */
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Class MysqlCommand
 */
class MysqlCommand
{
    /**
     * @var ProcessBuilder
     */
    protected $processBuilder;

    /**
     * @var array
     */
    protected $dbConfig = array();

    /**
     * MysqlCommand constructor.
     *
     * @param array $dbConfig
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(array $dbConfig, ProcessBuilder $processBuilder)
    {
        $this->dbConfig = $dbConfig;
        $this->processBuilder = $processBuilder;
    }

    /**
     * @param array $additionalArguments
     * @param resource $inputStream
     * @param null $outputCallback
     * @return int
     */
    public function mysql(array $additionalArguments = array(), $inputStream = STDIN, $outputCallback = null)
    {
        $this->processBuilder->setPrefix(self::getMysqlBinPath());
        $this->processBuilder->setArguments(array_merge($this->buildConnectionArguments(), $additionalArguments));
        $process = $this->processBuilder->getProcess();
        $process->setInput($inputStream);
        return $process->run($this->buildDefaultOutputCallback($outputCallback));
    }

    /**
     * @param array $additionalArguments
     * @param null $outputCallback
     * @return int
     */
    public function mysqldump(array $additionalArguments = array(), $outputCallback = null)
    {
        $this->processBuilder->setPrefix(self::getMysqlDumpBinPath());
        $this->processBuilder->setArguments(array_merge($this->buildConnectionArguments(), $additionalArguments));

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

    protected function buildConnectionArguments()
    {
        if (!empty($this->dbConfig['user'])) {
            $arguments[] = '-u';
            $arguments[] = $this->dbConfig['user'];
        }
        if (!empty($this->dbConfig['password'])) {
            $arguments[] = '-p' . $this->dbConfig['password'];
        }
        if (!empty($this->dbConfig['host'])) {
            $arguments[] = '-h';
            $arguments[] = $this->dbConfig['host'];
        }
        if (!empty($this->dbConfig['port'])) {
            $arguments[] = '-P';
            $arguments[] = $this->dbConfig['port'];
        }
        if (!empty($this->dbConfig['unix_socket'])) {
            $arguments[] = '-S';
            $arguments[] = $this->dbConfig['unix_socket'];
        }
        $arguments[] = $this->dbConfig['dbname'];
        return $arguments;
    }


    /**
     * Get path to mysql bin
     *
     * @return string
     */
    public static function getMysqlBinPath()
    {
        if (getenv('path_mysql_bin')) {
            return getenv('path_mysql_bin');
        } else {
            return 'mysql';
        }
    }

    /**
     * Get path to mysqldump bin
     *
     * @return string
     */
    public static function getMysqlDumpBinPath()
    {
        if (getenv('path_mysqldump_bin')) {
            return getenv('path_mysqldump_bin');
        } else {
            return 'mysqldump';
        }
    }
}
