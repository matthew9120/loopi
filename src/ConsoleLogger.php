<?php

namespace IDCT;

use Psr\Log\LoggerInterface;

/**
 * Just a basic implementation of LoggerInterface for console output.
 */
class ConsoleLogger implements LoggerInterface
{
    public function emergency($message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }

    public function alert($message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }
    public function critical($message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }
    public function error($message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }
    public function warning($message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }
    public function notice($message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }
    public function info($message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }
    public function debug($message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }
    public function log($level, $message, array $context = [])
    {
        $this->writeln($message);

        return $this;
    }

    protected function writeln($message)
    {
        echo $message . PHP_EOL;
    }
}
