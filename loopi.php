<?php

namespace IDCT;

abstract class Loopi {

    const GPIO_PATH = '/sys/class/gpio/';
    const GPIO_PREFIX = 'gpio';

    const GPIO_FILE_EXPORT = 'export';
    const GPIO_FILE_UNEXPORT = 'unexport';

    const GPIO_PIN_FILE_DIRECTION = 'direction';
    const GPIO_PIN_FILE_VALUE = 'value';

    const GPIO_DIRECTION_IN = 'in';
    const GPIO_DIRECTION_OUT = 'out';

    const GPIO_HIGH = "1";
    const GPIO_LOW = "0";

    private $inputGpioByName;
    private $inputStatesByName;
    private $outputGpioByName;
    private $outputStatesByName;
    private $outputStatesByNameDelayed;
    private $logger;
    private $running;

    public function setLogger($logger) {
        $this->logger = $logger;
        return $this;
    }

    public function getLogger() {
        return $this->logger;
    }

    final private function registerGpio($direction, array $gpioByName)
    {
        if (!in_array($direction, [ static::GPIO_DIRECTION_IN, static::GPIO_DIRECTION_OUT ]) {
            throw new Exception("Invalid direction. Please use GPIO_DIRECTION_IN or GPIO_DIRECTION_OUT.");
        }

        foreach ($gpioByName as $name => $gpio) {
            file_put_contents(static::GPIO_PATH . static::GPIO_FILE_EXPORT, $gpio);
            file_put_contents(static::GPIO_PATH . static::GPIO_PREFIX . $gpio . DIRECTORY_SEPARATOR . static::GPIO_PIN_FILE_DIRECTION, $direction);
            if ($direction === static::GPIO_DIRECTION_IN) {
                $this->inputGpioByName[$name] = $gpio;
            } else {
                $this->outputGpioByName[$name] = $gpio;
            }

            $this->getLogger()->info('Registered `'.$direction.'` GPIO ' . $gpio . ' as `'.$name.'`.');
        }

        return $this;
    }

    final private function resetGpio()
    {
        $success = true;
        foreach ($this->outputGpioByName as $name => $gpio) {
            $this->writeOutput($name, static::GPIO_LOW);
        }

        return $success;
    }

    final private function verifyGpio()
    {
        $success = true;
        foreach ($this->inputGpioByName as $name => $gpio) {
            if (!is_dir(static::GPIO_PATH . static::GPIO_PREFIX . $gpio)) {
                $this->getLogger()->warning('Required `in` GPIO ' . $gpio . ' was not properly registered.');
                $success = false;
            }
        }

        foreach ($this->outputGpioByName as $name => $gpio) {
            if (!is_dir(static::GPIO_PATH . static::GPIO_PREFIX . $gpio)) {
                $this->getLogger()->warning('Required `out` GPIO ' . $gpio . ' was not properly registered.');
                $success = false;
            }
        }

        return $success;
    }

    final private function initialize($config)
    {
        if (isset($config['input'])) {
            $this->registerGpio(static::GPIO_DIRECTION_IN, $config['input']);
        }

        if (isset($config['output'])) {
            $this->registerGpio(static::GPIO_DIRECTION_OUT, $config['output']);
        }

        $this->getLogger()->info('Waiting 1 second for GPIO settings to settle.');
        sleep(1);

        if (!$this->verifyGpio()) {
            $this->getLogger()->error('Could not properly register GPIOs.');
            $this->quit();
        }

        return $this;
    }

    final private function retrieveInput($inputName)
    {
        if (!isset($this->inputGpioByName[$inputName])) {
            throw new Exception('Input `' . $inputName . '` is not registered.');
        }
        $gpio = $this->inputGpioByName[$inputName];
        $gpioFile = static::GPIO_PATH . static::GPIO_PREFIX . $gpio . DIRECTORY_SEPARATOR . static::GPIO_PIN_FILE_VALUE;
        if (!file_exists($gpioFile) || !is_readable($gpioFile)) {
            throw new Exception('Cannot read input GPIO ' . $gpio . ': ' . $inputName);
        }

        $value = file_get_contents($gpioFile);
        $this->inputStatesByName[$inputName] = $value;
        return $this;
    }

    final protected function getGpioValue($inputName, $delayed = true)
    {
        if (!$delayed) {
            $this->retrieveInput($inputName);
            return $this->inputStatesByName[$inputName];
        }

        if (!isset($this->inputGpioByName[$inputName])) {
            throw new Exception('Input `' . $inputName . '` is not registered.');
        }

        return $this->inputStatesByName[$inputName];
    }

    final protected function setGpioValue($outputName, $value, $delayed = true)
    {
        if (!$delayed) {
            $this->writeOutput($outputName, $value);
            return $this;
        }

        if (!in_array($value, [ static::GPIO_HIGH, static::GPIO_LOW ]) {
            throw new Exception("Invalid value for output. Please use GPIO_HIGH or GPIO_LOW.");
        }

        if (!isset($this->outputGpioByName[$outputName])) {
            throw new Exception('Output `' . $outputName . '` is not registered.');
        }

        $this->outputStatesByNameDelayed[$outputName] = $value;
        return $this;
    }

    final private function writeOutput($outputName, $value)
    {
        if (!in_array($value, [ static::GPIO_HIGH, static::GPIO_LOW ]) {
            throw new Exception("Invalid value for output. Please use GPIO_HIGH or GPIO_LOW.");
        }

        if (!isset($this->outputGpioByName[$outputName])) {
            throw new Exception('Output `' . $outputName . '` is not registered.');
        }

        $gpio = $this->outputGpioByName[$outputName];
        $gpioFile = static::GPIO_PATH . static::GPIO_PREFIX . $gpio . DIRECTORY_SEPARATOR . static::GPIO_PIN_FILE_VALUE;
        if (!file_exists($gpioFile) || !is_readable($gpioFile)) {
            throw new Exception('Cannot write output GPIO ' . $gpio . ': ' . $inputName);
        }

        file_put_contents($gpioFile, $value);
        $this->outputStatesByName[$inputName] = $value;
        return $this;
    }

    final private function retrieveDelayedInputs()
    {
        foreach(array_keys($this->inputGpioByName) as $inputName) {
            $this->retrieveInput($inputName);
        }

        return $this;
    }

    final private function writeDelayedOutputs()
    {
        foreach($this->outputStatesByNameDelayed as $inputName => $value) {
            $this->writeOutput($inputName, $value);
        }
        $this->outputStatesByNameDelayed = [];

        return $this;
    }

    final private function quit()
    {
        $this->running = false;
    }

    final public run()
    {
        $this->initialize(include "config.php");
        $this->writeDelayedOutputs();
        $this->retrieveDelayedInputs();
        $this->running = true;
        while($this->running) {
            $this->loop();
        }
        $this->close();
    }

    abstract protected function loop();

    final private function close()
    {
        foreach ($inputGpioByName as $name => $gpio) {
            file_put_contents(static::GPIO_PATH . static::GPIO_FILE_UNEXPORT, $gpio);
            $this->getLogger()->info('Unregistered `in` GPIO ' . $gpio . ' as `'.$name.'`.');
        }

        foreach ($outputGpioByName as $name => $gpio) {
            file_put_contents(static::GPIO_PATH . static::GPIO_FILE_UNEXPORT, $gpio);
            $this->getLogger()->info('Unregistered `in` GPIO ' . $gpio . ' as `'.$name.'`.');
        }

        $this->getLogger()->info('Fin.');
    }
}
