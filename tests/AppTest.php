<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\App;

class AppTest extends TestCase
{
    public function testAppInitialization()
    {
        $app = App::init();

        $this->assertInstanceOf(App::class, $app);
    }

    public function testAppIsSingleton()
    {
        $app1 = App::init();
        $app2 = App::init();

        $this->assertSame($app1, $app2);
    }
}
