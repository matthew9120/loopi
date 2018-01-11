<?php

require "../vendor/autoload.php";

$config = [
    'output' => [
        'red' => 14,
        'yellow' => 4
    ]
];

class DiodeBlinker extends IDCT\Loopi
{
    protected $state = 0;

    protected function loop()
    {
        switch ($this->state) {
            case 0:
                $this->setGpioValue('red', static::GPIO_LOW)
                ->setGpioValue('yellow', static::GPIO_LOW);
            break;
            case 1:
                $this->setGpioValue('red', static::GPIO_HIGH)
                ->setGpioValue('yellow', static::GPIO_LOW);
            break;
            case 2:
                $this->setGpioValue('red', static::GPIO_LOW)
                     ->setGpioValue('yellow', static::GPIO_HIGH);
            break;
            case 3:
                $this->setGpioValue('red', static::GPIO_HIGH)
                     ->setGpioValue('yellow', static::GPIO_HIGH);
            break;
            case 4:
            $this->setGpioValue('red', static::GPIO_HIGH)
                 ->setGpioValue('yellow', static::GPIO_LOW);
            break;
        }

        if (++$this->state > 4) {
            $this->state = 0;
        }

        usleep(250000);
    }
}

$app = new DiodeBlinker();
$app->run($config);
