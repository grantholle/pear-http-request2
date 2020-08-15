<?php

namespace Tests\Request2\Adapters;

use Pear\Http\Request2;
use Pear\Http\Request2\Adapters\Mock;
use Pear\Http\Request2\Exceptions\Request2Exception;
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
 * Unit test for Request2_Response class
 */
class MockTest extends TestCase
{
    public function testDefaultResponse()
    {
        $req = new Request2('http://www.example.com/', Request2::METHOD_GET,
                                 ['adapter' => Mock::class]);
        $response = $req->send();
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals(0, count($response->getHeader()));
        $this->assertEquals('', $response->getBody());
    }

    public function testResponseFromString()
    {
        $mock = new Mock();
        $mock->addResponse(
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/plain; charset=iso-8859-1\r\n" .
            "\r\n" .
            "This is a string"
        );
        $req = new Request2('http://www.example.com/');
        $req->setAdapter($mock);

        $response = $req->send();
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(1, count($response->getHeader()));
        $this->assertEquals('This is a string', $response->getBody());
    }

    public function testResponseFromFile()
    {
        $mock = new Mock();
        $mock->addResponse(fopen(dirname(dirname(__DIR__)) .
                           '/_files/response_headers', 'rb'));

        $req = new Request2('http://www.example.com/');
        $req->setAdapter($mock);

        $response = $req->send();
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(7, count($response->getHeader()));
        $this->assertEquals('Nothing to see here, move along.', $response->getBody());
    }

    public function testResponsesQueue()
    {
        $mock = new Mock();
        $mock->addResponse(
            "HTTP/1.1 301 Over there\r\n" .
            "Location: http://www.example.com/newpage.html\r\n" .
            "\r\n" .
            "The document is over there"
        );
        $mock->addResponse(
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/plain; charset=iso-8859-1\r\n" .
            "\r\n" .
            "This is a string"
        );

        $req = new Request2('http://www.example.com/');
        $req->setAdapter($mock);
        $this->assertEquals(301, $req->send()->getStatus());
        $this->assertEquals(200, $req->send()->getStatus());
        $this->assertEquals(400, $req->send()->getStatus());
    }

    /**
     * Returning URL-specific responses
     * @link http://pear.php.net/bugs/bug.php?id=19276
     */
    public function testRequest19276()
    {
        $mock = new Mock();
        $mock->addResponse(
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/plain; charset=iso-8859-1\r\n" .
            "\r\n" .
            "This is a response from example.org",
            'http://example.org/'
        );
        $mock->addResponse(
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/plain; charset=iso-8859-1\r\n" .
            "\r\n" .
            "This is a response from example.com",
            'http://example.com/'
        );

        $req1 = new Request2('http://localhost/');
        $req1->setAdapter($mock);
        $this->assertEquals(400, $req1->send()->getStatus());

        $req2 = new Request2('http://example.com/');
        $req2->setAdapter($mock);
        print_r($req2->send()->getBody());
        $this->assertStringContainsString('example.com', $req2->send()->getBody());

        $req3 = new Request2('http://example.org');
        $req3->setAdapter($mock);
        $this->assertStringContainsString('example.org', $req3->send()->getBody());
    }

    public function testResponseException()
    {
        $mock = new Mock();
        $mock->addResponse(
            new Request2Exception('Shit happens')
        );
        $req = new Request2('http://www.example.com/');
        $req->setAdapter($mock);
        try {
            $req->send();
        } catch (\Exception $e) {
            $this->assertEquals('Shit happens', $e->getMessage());
            return;
        }
        $this->fail('Expected Request2Exception was not thrown');
    }
}
