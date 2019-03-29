<?php
namespace BeechIt\BackupRestore\Database\Process;

/*
 * This source file is proprietary property of Beech Applications B.V.
 * Date: 01-10-2015
 * All code (c) Beech Applications B.V. all rights reserved
 */
use Symfony\Component\Process\Process;

/**
 * Class MysqlCommand
 */
class MysqlCommand
{

    /**
     * @var array
     */
    protected $dbConfig = [];

    /**
     * MysqlCommand constructor.
     *
     * @param array $dbConfig
     */
    public function __construct(array $dbConfig)
    {
        $this->dbConfig = $dbConfig;
    }

    /**
     * @param array $additionalArguments
     * @param bool|resource $inputStream
     * @param null $outputCallback
     * @return int
     */
    public function mysql(array $additionalArguments = [], $inputStream = STDIN, $outputCallback = null): int
    {
        $processCommand = array_merge([self::getMysqlBinPath()], array_merge($this->buildConnectionArguments(), $additionalArguments));
        $process = new Process($processCommand);
        $process->setTimeout(600);
        $process->setInput($inputStream);
        return $process->run($this->buildDefaultOutputCallback($outputCallback));
    }

    /**
     * @param array $additionalArguments
     * @param null $outputCallback
     * @return int
     */
    public function mysqldump(array $additionalArguments = array(), $outputCallback = null): int
    {
        $processCommand = array_merge([self::getMysqlDumpBinPath()], array_merge($this->buildConnectionArguments(), $additionalArguments));
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
     * @return array
     */
    protected function buildConnectionArguments(): array
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
    public static function getMysqlBinPath(): string
    {
        if (getenv('path_mysql_bin')) {
            return getenv('path_mysql_bin');
        }

        return 'mysql';
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
        }

        return 'mysqldump';
    }
}
