<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2018 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler\Testsuite;

use PHPUnit\Framework\TestCase;
use TaskScheduler\Testsuite\Mock\SuccessJobMock;

/**
 * @coversNothing
 */
class JobTest extends TestCase
{
    protected $job;

    public function setUp()
    {
        $this->job = new SuccessJobMock();
    }

    public function testSetData()
    {
        $self = $this->job->setData(['foo' => 'bar']);
        $this->assertInstanceOf(SuccessJobMock::class, $self);
    }

    public function testGetData()
    {
        $self = $this->job->setData(['foo' => 'bar']);
        $data = $this->job->getData();
        $this->assertSame($data, ['foo' => 'bar']);
    }

    public function testStart()
    {
        $result = $this->job->start();
        $this->assertSame($result, true);
    }
}
