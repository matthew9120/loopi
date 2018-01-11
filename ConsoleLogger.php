<?php

class ConsoleLogger {
 
	public function info($message)
	{
		$this->writeln($message);
		return $this;
	}

	public function warning($message)
	{
		$this->writeln($message);
		return $this;
	}

	public function error($message)
	{
           $this->writeln($message);
	   return $this;
	}

	protected function writeln($message)
	{
            echo $message . PHP_EOL;
	}
}
