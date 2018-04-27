<?php

namespace IDCT;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * PHP application loop for Raspberry Pi.
 *
 * Extend this class and write your implementation of the `loop` method.
 */
abstract class Loopi
{
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

    /**
     * Input GPIOs identifiers (numbers) by their defined names.
     *
     * @var int[string]
     */
    private $inputGpioByName;

    /**
     * Input GPIOs states (high, low) by their defined names.
     *
     * @var int[string]
     */
    private $inputStatesByName;
    private $previousStatesByName;

    /**
     * Output GPIOs identifiers (numbers) by their defined names.
     *
     * @var int[string]
     */
    private $outputGpioByName;

    /**
     * Output GPIOs states (high, low) by their defined names.
     *
     * @var int[string]
     */
    private $outputStatesByName;

    /**
     * Output GPIOs states to be set after the loop.
     *
     * @var int[string]
     */
    private $outputStatesByNameDelayed;

    /**
     * Logger instance. If not defined ConsoleLogger will be used.
     *
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Indicates if the loop should continue to run.
     *
     * @var boolean
     */
    private $running;

    /**
     * Sets the logger interface.
     *
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Gets the logger. If not set sets and returns a new instance of ConsoleLogger.
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        if ($this->logger === null) {
            $this->logger = new ConsoleLogger();
        }

        return $this->logger;
    }

    /**
     * Initializes the application and runs the main application loop.
     *
     * Ensures that GPIOs are registered and unregistered.
     *
     * @param string $config
     * @throws Exception File not readable
     * @return $this
     */
    final public function run($config = null)
    {
        $this->running = true;
        if (is_string($config) && is_readable($config)) {
            $this->initialize(include $config);
        } elseif (is_string($config)) {
            throw new Exception("Config file not readable.");
        } elseif (is_array($config)) {
            $this->initialize($config);
        } else {
            $this->initialize([ ]);
        }

        $this->writeDelayedOutputs();
        $this->retrieveDelayedInputs();
        while ($this->running) {
            $this->writeDelayedOutputs();
            $this->retrieveDelayedInputs();
            $this->loop();
        }
        $this->close();

        return $this;
    }

    /**
     * Gets input GPIO value (HIGH or LOW) by name. If $delayed is set to true
     * returns from cached entries before loop launch.
     *
     * @param string $inputName
     * @param boolean $delayed
     * @return string GPIO_HIGH, GPIO_LOW
     */
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

    /**
     * Sets the GPIO value by registered name. If $delayed is set to true then
     * value will be set before next loop round-robin.
     *
     * @param string $outputName
     * @param string $value GPIO_LOW GPIO_HIGH
     * @param boolean $delayed
     * @return $this
     */
    final protected function setGpioValue($outputName, $value, $delayed = true)
    {
        if (!$delayed) {
            $this->writeOutput($outputName, $value);

            return $this;
        }

        if (!in_array($value, [ static::GPIO_HIGH, static::GPIO_LOW ])) {
            throw new Exception("Invalid value for output. Please use GPIO_HIGH or GPIO_LOW.");
        }

        if (!isset($this->outputGpioByName[$outputName])) {
            throw new Exception('Output `' . $outputName . '` is not registered.');
        }

        $this->outputStatesByNameDelayed[$outputName] = $value;

        return $this;
    }

    /**
     * Sets the internal variables required to exit the main loop gently.
     *
     * @return $this
     */
    final protected function quit()
    {
        $this->running = false;

        return $this;
    }

    /**
     * Main application loop. To close it gently call `quit` method.
     */
    abstract protected function loop();

    /**
     * Initializes GPIOs.
     *
     * @param string GPIO_DIRECTION_IN, GPIO_DIRECTION_OUT
     * @param int[string] $gpioByName
     * @return $this
     * @throws Exception Invalid direction. Please use GPIO_DIRECTION_IN or GPIO_DIRECTION_OUT.
     */
    final private function registerGpio($direction, array $gpioByName)
    {
        if (!in_array($direction, [ static::GPIO_DIRECTION_IN, static::GPIO_DIRECTION_OUT ])) {
            throw new Exception("Invalid direction. Please use GPIO_DIRECTION_IN or GPIO_DIRECTION_OUT.");
        }
        $success = true;
        foreach ($gpioByName as $name => $gpio) {
            $this->unregisterGpio($gpio);
            if (@file_put_contents(static::GPIO_PATH . static::GPIO_FILE_EXPORT, $gpio) > 0) {
                $this->getLogger()->info('Exported GPIO ' . $gpio . '.');
            } else {
                $success = false;
                $this->getLogger()->warning('Required GPIO ' . $gpio . ' could not be exported (got permissions?).');
            }
        }

        if (!$success) {
            $this->getLogger()->error('Could not properly export GPIOs.');
            $this->quit();

            return $this;
        }

        $this->getLogger()->info('Waiting 1 second for GPIO settings to settle.');
        sleep(1);

        foreach ($gpioByName as $name => $gpio) {
            if (@file_put_contents(static::GPIO_PATH . static::GPIO_PREFIX . $gpio . DIRECTORY_SEPARATOR . static::GPIO_PIN_FILE_DIRECTION, $direction) === false) {
                $this->getLogger()->warning('Required `'.$direction.'` GPIO ' . $gpio . ' direction could not be set.');
                $success = false;
            } else {
                if ($direction === static::GPIO_DIRECTION_IN) {
                    $this->inputGpioByName[$name] = $gpio;
                } else {
                    $this->outputGpioByName[$name] = $gpio;
                }
                $this->getLogger()->info('Registered `'.$direction.'` GPIO ' . $gpio . ' as `'.$name.'`.');
            }
        }

        if (!$success) {
            $this->getLogger()->error('Could not properly register GPIOs.');
            $this->quit();
        }

        return $this;
    }

    /**
     * Sets registered output GPIOs to GPIO_LOW.
     *
     * @todo default values
     * @return $this
     */
    final private function resetGpio()
    {
        foreach ($this->outputGpioByName as $name => $gpio) {
            $this->writeOutput($name, static::GPIO_LOW);
        }

        return $this;
    }

    /**
     * Initializes the GPIOs based on $config settings.
     *
     * @param array $config
     * @return $this
     */
    final private function initialize($config)
    {
        $this->inputGpioByName = [];
        $this->inputStatesByName = [];
        $this->previousStatesByName = [];
        $this->outputGpioByName = [];
        $this->outputStatesByName = [];
        $this->outputStatesByNameDelayed = [];
        if (isset($config['input'])) {
            $this->registerGpio(static::GPIO_DIRECTION_IN, $config['input']);
        }

        if (isset($config['output'])) {
            $this->registerGpio(static::GPIO_DIRECTION_OUT, $config['output']);
        }

        return $this;
    }

    /**
     * Retrieves value (state GPIO_HIGH, GPIO_LOW) of a registered GPIO and saves
     * into the array of states.
     *
     * @param string $inputName name of the registered GPIO
     * @return $this
     * @throws Exception
     */
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

        $value = trim(file_get_contents($gpioFile));
        $this->inputStatesByName[$inputName] = $value;

        return $this;
    }

    /**
     * Writes the output of a GPIO directly to the pin (sets the state).
     *
     * @param string $outputName
     * @param string $value GPIO_LOW GPIO_HIGH
     * @return $this
     * @throws Exception
     */
    final private function writeOutput($outputName, $value)
    {
        if (!in_array($value, [ static::GPIO_HIGH, static::GPIO_LOW ])) {
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
        $this->outputStatesByName[$outputName] = $value;

        return $this;
    }

    /**
     * Fills values of all input GPIOs.
     *
     * @return $this
     */
    final private function retrieveDelayedInputs()
    {
        $this->previousStatesByName = $this->inputStatesByName;

        foreach (array_keys($this->inputGpioByName) as $inputName) {
            $this->retrieveInput($inputName);
        }

        return $this;
    }

    final private function switchedDown($inputName) {
        return $this->previousStatesByName[$inputName] === $this::GPIO_HIGH && $this->inputStatesByName[$inputName] === $this::GPIO_LOW;
    }

    /**
     * Stores (sets GPIOs) states of the delayed GPIOs (ones set during loop).
     *
     * @return $this
     */
    final private function writeDelayedOutputs()
    {
        foreach ($this->outputStatesByNameDelayed as $inputName => $value) {
            $this->writeOutput($inputName, $value);
        }
        $this->outputStatesByNameDelayed = [];

        return $this;
    }

    /**
     * Unregisters GPIO by identifier.
     *
     * Returns false if failed.
     *
     * @param int $gpio
     * @return boolean
     */
    final private function unregisterGpio($gpio)
    {
        $value = @file_put_contents(static::GPIO_PATH . static::GPIO_FILE_UNEXPORT, $gpio);
        if ($value === false) {
            return false;
        }

        return true;
    }

    /**
     * Gently destructs the application by unregistering GPIOs.
     *
     * @return $this
     */
    final private function close()
    {
        foreach ($this->inputGpioByName as $name => $gpio) {
            $this->unregisterGpio($gpio);
            $this->getLogger()->info('Unregistered `in` GPIO ' . $gpio . ' as `'.$name.'`.');
        }

        foreach ($this->outputGpioByName as $name => $gpio) {
            $this->unregisterGpio($gpio);
            $this->getLogger()->info('Unregistered `in` GPIO ' . $gpio . ' as `'.$name.'`.');
        }

        $this->getLogger()->info('Fin.');

        return $this;
    }
}
