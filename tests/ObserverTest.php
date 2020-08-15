<?php

namespace Tests;

use Pear\Http\Request2;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Request2 package
 *
 * PHP version 5
 *
 * LICENSE
 *
 * This source file is subject to BSD 3-Clause License that is bundled
 * with this package in the file LICENSE and available at the URL
 * https://raw.github.com/pear/Request2/trunk/docs/LICENSE
 *
 * @category  HTTP
 * @package   Request2
 * @author    Alexey Borzov <avb@php.net>
 * @copyright 2008-2020 Alexey Borzov <avb@php.net>
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link      http://pear.php.net/package/Request2
 */

/**
 * Mock observer
 */
class MockObserver implements \SplObserver
{
    public $calls = 0;

    public $event;

    public function update (\SplSubject $subject)
    {
        $this->calls++;
        $this->event = $subject->getLastEvent();
    }
}

/**
 * Unit test for subject-observer pattern implementation in Request2
 */
class ObserverTest extends TestCase
{
    public function testSetLastEvent()
    {
        $request  = new Request2();
        $observer = new MockObserver();
        $request->attach($observer);

        $request->setLastEvent('foo', 'bar');
        $this->assertEquals(1, $observer->calls);
        $this->assertEquals(['name' => 'foo', 'data' => 'bar'], $observer->event);

        $request->setLastEvent('baz');
        $this->assertEquals(2, $observer->calls);
        $this->assertEquals(['name' => 'baz', 'data' => null], $observer->event);
    }

    public function testAttachOnlyOnce()
    {
        $request   = new Request2();
        $observer  = new MockObserver();
        $observer2 = new MockObserver();
        $request->attach($observer);
        $request->attach($observer2);
        $request->attach($observer);

        $request->setLastEvent('event', 'data');
        $this->assertEquals(1, $observer->calls);
        $this->assertEquals(1, $observer2->calls);
    }

    public function testDetach()
    {
        $request   = new Request2();
        $observer  = new MockObserver();
        $observer2 = new MockObserver();

        $request->attach($observer);
        $request->detach($observer2); // should not be a error
        $request->setLastEvent('first');

        $request->detach($observer);
        $request->setLastEvent('second');
        $this->assertEquals(1, $observer->calls);
        $this->assertEquals(['name' => 'first', 'data' => null], $observer->event);
    }
}
