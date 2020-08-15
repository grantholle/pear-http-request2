<?php

namespace Tests;

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

use Pear\Http\Request2;
use Pear\Http\Request2\Exceptions\LogicException;
use Pear\Http\Request2\MultipartBody;
use Pear\Net\Url2;

/**
 * Unit test for Request2 class
 */
class Request2Test extends \PHPUnit\Framework\TestCase
{
    public function testConstructorSetsDefaults()
    {
        $url = new Url2('http://www.example.com/foo');
        $req = new Request2($url, Request2::METHOD_POST, ['connect_timeout' => 666]);

        $this->assertEquals($url, $req->getUrl());
        $this->assertEquals(Request2::METHOD_POST, $req->getMethod());
        $this->assertEquals(666, $req->getConfig('connect_timeout'));
    }

    public function testSetUrl()
    {
        $this->expectException(LogicException::class);
        $urlString = 'http://www.example.com/foo/bar.php';
        $url       = new Url2($urlString);

        $req1 = new Request2();
        $req1->setUrl($url);
        $this->assertSame($url, $req1->getUrl());

        $req2 = new Request2();
        $req2->setUrl($urlString);
        $this->assertInstanceOf(Url2::class, $req2->getUrl());
        $this->assertEquals($urlString, $req2->getUrl()->getUrl());

        $req3 = new Request2();
        $req3->setUrl(['This will cause an error']);
    }

    public function testConvertUserinfoToAuth()
    {
        $req = new Request2();
        $req->setUrl('http://foo:b%40r@www.example.com');

        $this->assertEquals('', (string) $req->getUrl()->getUserinfo());
        $this->assertEquals(
            ['user' => 'foo', 'password' => 'b@r', 'scheme' => Request2::AUTH_BASIC],
            $req->getAuth()
        );
    }

    public function testSetMethod()
    {
        $this->expectException(LogicException::class);
        $req = new Request2();
        $req->setMethod(Request2::METHOD_PUT);
        $this->assertEquals(Request2::METHOD_PUT, $req->getMethod());

        $req->setMethod('Invalid method');
    }

    public function testSetAndGetConfig()
    {
        $req = new Request2();
        $this->assertArrayHasKey('connect_timeout', $req->getConfig());

        $req->setConfig(['connect_timeout' => 123]);
        $this->assertEquals(123, $req->getConfig('connect_timeout'));
        try {
            $req->setConfig(['foo' => 'unknown parameter']);
            $this->fail('Expected LogicException was not thrown');
        } catch (LogicException $e) {}

        try {
            $req->getConfig('bar');
            $this->fail('Expected LogicException was not thrown');
        } catch (LogicException $e) {}
    }

    public function testSetProxyAsUrl()
    {
        $req = new Request2();
        $req->setConfig('proxy', 'socks5://foo:bar%25baz@localhost:1080/');

        $this->assertEquals('socks5', $req->getConfig('proxy_type'));
        $this->assertEquals('localhost', $req->getConfig('proxy_host'));
        $this->assertEquals(1080, $req->getConfig('proxy_port'));
        $this->assertEquals('foo', $req->getConfig('proxy_user'));
        $this->assertEquals('bar%baz', $req->getConfig('proxy_password'));
    }

    public function testHeaders()
    {
        $this->expectException(LogicException::class);
        $req = new Request2();
        $autoHeaders = $req->getHeaders();

        $req->setHeader('Foo', 'Bar');
        $req->setHeader('Foo-Bar: value');
        $req->setHeader(['Another-Header' => 'another value', 'Yet-Another: other_value']);
        $this->assertEquals(
            ['foo-bar' => 'value', 'another-header' => 'another value',
            'yet-another' => 'other_value', 'foo' => 'Bar'] + $autoHeaders,
            $req->getHeaders()
        );

        $req->setHeader('FOO-BAR');
        $req->setHeader(['aNOTHER-hEADER']);
        $this->assertEquals(
            ['yet-another' => 'other_value', 'foo' => 'Bar'] + $autoHeaders,
            $req->getHeaders()
        );

        $req->setHeader('Invalid header', 'value');
    }

    public function testBug15937()
    {
        $req = new Request2();
        $autoHeaders = $req->getHeaders();

        $req->setHeader('Expect: ');
        $req->setHeader('Foo', '');
        $this->assertEquals(
            ['expect' => '', 'foo' => ''] + $autoHeaders,
            $req->getHeaders()
        );
    }

    public function testRequest17507()
    {
        $req = new Request2();

        $req->setHeader('accept-charset', 'iso-8859-1');
        $req->setHeader('accept-charset', ['windows-1251', 'utf-8'], false);

        $req->setHeader(['accept' => 'text/html']);
        $req->setHeader(['accept' => 'image/gif'], null, false);

        $headers = $req->getHeaders();

        $this->assertEquals('iso-8859-1, windows-1251, utf-8', $headers['accept-charset']);
        $this->assertEquals('text/html, image/gif', $headers['accept']);
    }

    public function testCookies()
    {
        $this->expectException(LogicException::class);
        $req = new Request2();
        $req->addCookie('name', 'value');
        $req->addCookie('foo', 'bar');
        $headers = $req->getHeaders();
        $this->assertEquals('name=value; foo=bar', $headers['cookie']);

        $req->addCookie('invalid cookie', 'value');
    }

    public function testPlainBody()
    {
        $this->expectException(LogicException::class);
        $req = new Request2();
        $req->setBody('A string');
        $this->assertEquals('A string', $req->getBody());

        $req->setBody(__DIR__ . '/_files/plaintext.txt', true);
        $headers = $req->getHeaders();
        $this->assertRegexp(
            '!^(text/plain|application/octet-stream)!',
            $headers['content-type']
        );
        $this->assertEquals('This is a test.', fread($req->getBody(), 1024));

        $req->setBody('missing file', true);
    }

    public function testRequest16863()
    {
        $this->expectException(LogicException::class);
        $req = new Request2();
        $req->setBody(fopen(__DIR__ . '/_files/plaintext.txt', 'rb'));
        $headers = $req->getHeaders();
        $this->assertEquals('application/octet-stream', $headers['content-type']);

        $req->setBody(fopen('php://input', 'rb'));
    }

    public function testUrlencodedBody()
    {
        $req = new Request2(null, Request2::METHOD_POST);
        $req->addPostParameter('foo', 'bar');
        $req->addPostParameter(['baz' => 'quux']);
        $req->addPostParameter('foobar', ['one', 'two']);
        $this->assertEquals(
            'foo=bar&baz=quux&foobar%5B0%5D=one&foobar%5B1%5D=two',
            $req->getBody()
        );

        $req->setConfig(['use_brackets' => false]);
        $this->assertEquals(
            'foo=bar&baz=quux&foobar=one&foobar=two',
            $req->getBody()
        );
    }

    public function testRequest15368()
    {
        $req = new Request2(null, Request2::METHOD_POST);
        $req->addPostParameter('foo', 'te~st');
        $this->assertStringContainsString('~', $req->getBody());
    }

    public function testUpload()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('missing file');
        $req = new Request2(null, Request2::METHOD_POST);
        $req->addUpload('upload', __DIR__ . '/_files/plaintext.txt');

        $headers = $req->getHeaders();
        $this->assertEquals('multipart/form-data', $headers['content-type']);

        $req->addUpload('upload_2', 'missing file');
    }

    public function testPropagateUseBracketsToNetURL2()
    {
        $req = new Request2('http://www.example.com/', Request2::METHOD_GET,
                                 ['use_brackets' => false]);
        $req->getUrl()->setQueryVariable('foo', ['bar', 'baz']);
        $this->assertEquals('http://www.example.com/?foo=bar&foo=baz', $req->getUrl()->__toString());

        $req->setConfig('use_brackets', true)->setUrl('http://php.example.com/');
        $req->getUrl()->setQueryVariable('foo', ['bar', 'baz']);

        $this->assertEquals('http://php.example.com/?foo[]=bar&foo[]=baz', $req->getUrl()->__toString());
    }

    public function testSetBodyRemovesPostParameters()
    {
        $req = new Request2('http://www.example.com/', Request2::METHOD_POST);
        $req->addPostParameter('foo', 'bar');
        $req->setBody('');
        $this->assertEquals('', $req->getBody());
    }

    public function testPostParametersPrecedeSetBodyForPost()
    {
        $req = new Request2('http://www.example.com/', Request2::METHOD_POST);
        $req->setBody('Request body');
        $req->addPostParameter('foo', 'bar');

        $this->assertEquals('foo=bar', $req->getBody());

        $req->setMethod(Request2::METHOD_PUT);
        $this->assertEquals('Request body', $req->getBody());
    }

    public function testSetMultipartBody()
    {
        $req = new Request2('http://www.example.com/', Request2::METHOD_POST);
        $body = new MultipartBody(['foo' => 'bar'], []);
        $req->setBody($body);
        $this->assertSame($body, $req->getBody());
    }

    public function testBug17460()
    {
        $req = new Request2('http://www.example.com/', Request2::METHOD_POST);
        $req->addPostParameter('foo', 'bar')
            ->setHeader('content-type', 'application/x-www-form-urlencoded; charset=UTF-8');

        $this->assertEquals('foo=bar', $req->getBody());
    }

    public function testCookieJar()
    {
        $this->expectException(LogicException::class);
        $req = new Request2();
        $this->assertNull($req->getCookieJar());

        $req->setCookieJar();
        $jar = $req->getCookieJar();
        $this->assertInstanceOf(Request2\CookieJar::class, $jar);

        $req2 = new Request2();
        $req2->setCookieJar($jar);
        $this->assertSame($jar, $req2->getCookieJar());

        $req2->setCookieJar(null);
        $this->assertNull($req2->getCookieJar());

        $req2->setCookieJar('foo');
    }

    public function testAddCookieToJar()
    {
        $req = new Request2();
        $req->setCookieJar();

        try {
            $req->addCookie('foo', 'bar');
            $this->fail('Expected Request2_Exception was not thrown');
        } catch (LogicException $e) { }

        $req->setUrl('http://example.com/path/file.php');
        $req->addCookie('foo', 'bar');

        $this->assertArrayNotHasKey('cookie', $req->getHeaders());
        $cookies = $req->getCookieJar()->getAll();
        $this->assertEquals(
            [
                'name'    => 'foo',
                'value'   => 'bar',
                'domain'  => 'example.com',
                'path'    => '/path/',
                'expires' => null,
                'secure'  => false
            ],
            $cookies[0]
        );
    }

    public function testDisallowEmptyUrls()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('none');
        $req = new Request2();
        $req->send();
    }

    public function testDisallowRelativeUrls()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('/foo/bar.php');
        $req = new Request2('/foo/bar.php');
        $req->send();
    }
}
?>