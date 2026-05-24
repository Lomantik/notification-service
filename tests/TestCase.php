<?php

namespace Tests;

use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app !== null) {
            Cache::flush();
        }
    }

    protected function bindCommandOutput(\Illuminate\Console\Command $command): void
    {
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
    }
}
