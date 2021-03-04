<?php

namespace Tests\Request2\Adapters;

/**
 * Unit tests for HTTP_Request2 package
 *
 * PHP version 5
 *
 * LICENSE
 *
 * This source file is subject to BSD 3-Clause License that is bundled
 * with this package in the file LICENSE and available at the URL
 * https://raw.github.com/pear/HTTP_Request2/trunk/docs/LICENSE
 *
 * @category  HTTP
 * @package   HTTP_Request2
 * @author    Alexey Borzov <avb@php.net>
 * @copyright 2008-2020 Alexey Borzov <avb@php.net>
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link      http://pear.php.net/package/HTTP_Request2
 */

use Pear\Http\Request2;
use Pear\Http\Request2\Exceptions\MessageException;
use Pear\Http\Request2\Exceptions\Request2Exception;
use Pear\Http\Request2\MultipartBody;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../NetworkConfig.php';

class SlowpokeBody extends MultipartBody
{

    protected $doSleep;

    public function rewind()
    {
        $this->doSleep = true;
        parent::rewind();
    }

    public function read($length)
    {
        if ($this->doSleep) {
            sleep(3);
            $this->doSleep = false;
        }
        return parent::read($length);
    }
}

class HeaderObserver implements \SplObserver
{
    public $headers;

    public function update(\SplSubject $subject)
    {
        /* @var $subject Request2 */
        $event = $subject->getLastEvent();

        // force a timeout when writing request body
        if ('sentHeaders' == $event['name']) {
            $this->headers = $event['data'];
        }
    }
}

class EventSequenceObserver implements \SplObserver
{
    private $_watched = [];

    public $sequence = [];

    public function __construct(array $watchedEvents = [])
    {
        if (!empty($watchedEvents)) {
            $this->_watched = $watchedEvents;
        }
    }

    public function update(\SplSubject $subject)
    {
        /* @var $subject Request2 */
        $event = $subject->getLastEvent();

        if ($event['name'] !== end($this->sequence)
            && (empty($this->_watched) || in_array($event['name'], $this->_watched, true))
        ) {
            $this->sequence[] = $event['name'];
        }
    }
}


/**
 * Tests for HTTP_Request2 package that require a working webserver
 *
 * The class contains some common tests that should be run for all Adapters,
 * it is extended by their unit tests.
 *
 * You need to properly set up this test suite, refer to NetworkConfig.php.dist
 */
abstract class CommonNetworkTest extends TestCase
{
   /**
    * HTTP Request object
    * @var Request2
    */
    protected $request;

   /**
    * Base URL for remote test files
    * @var string
    */
    protected $baseUrl;

   /**
    * Configuration for HTTP Request object
    * @var array
    */
    protected $config = [];

    protected function setUp(): void
    {
        if (!defined('HTTP_REQUEST2_TESTS_BASE_URL') || !HTTP_REQUEST2_TESTS_BASE_URL) {
            $this->markTestSkipped('Base URL is not configured');
        } else {
            $this->baseUrl = rtrim(HTTP_REQUEST2_TESTS_BASE_URL, '/') . '/';
            $name = strtolower(preg_replace('/^test/i', '', $this->getName())) . '.php';

            $this->request = new Request2(
                $this->baseUrl . $name, Request2::METHOD_GET, $this->config
            );
        }
    }

   /**
    * Tests possibility to send GET parameters
    *
    * NB: Currently there are problems with Net_URL2::setQueryVariables(), thus
    * array structure is simple: http://pear.php.net/bugs/bug.php?id=18267
    */
    public function testGetParameters()
    {
        $data = [
            'bar' => [
                'key' => 'value'
            ],
            'foo' => 'some value',
            'numbered' => ['first', 'second']
        ];

        $this->request->getUrl()->setQueryVariables($data);
        $response = $this->request->send();
        $this->assertEquals(serialize($data), $response->getBody());
    }

    public function testPostParameters()
    {
        $data = [
            'bar' => [
                'key' => 'some other value'
            ],
            'baz' => [
                'key1' => [
                    'key2' => 'yet another value'
                ]
            ],
            'foo' => 'some value',
            'indexed' => ['first', 'second']
        ];
        $events = [
            'sentHeaders', 'sentBodyPart', 'sentBody', 'receivedHeaders', 'receivedBodyPart', 'receivedBody'
        ];
        $observer = new EventSequenceObserver($events);

        $this->request->setMethod(Request2::METHOD_POST)
            ->setHeader('Accept-Encoding', 'identity')
            ->addPostParameter($data)
            ->attach($observer);

        $response = $this->request->send();
        $this->assertEquals(serialize($data), $response->getBody());
        $this->assertEquals($events, $observer->sequence);
    }

    public function testUploads()
    {
        $this->request->setMethod(Request2::METHOD_POST)
            ->addUpload('foo', dirname(dirname(__DIR__)) . '/_files/empty.gif', 'picture.gif', 'image/gif')
            ->addUpload('bar', [
                [dirname(dirname(__DIR__)) . '/_files/empty.gif', null, 'image/gif'],
                [dirname(dirname(__DIR__)) . '/_files/plaintext.txt', 'secret.txt', 'text/x-whatever']
            ]);

        $response = $this->request->send();
        $this->assertStringContainsString("foo picture.gif image/gif 43", $response->getBody());
        $this->assertStringContainsString("bar[0] empty.gif image/gif 43", $response->getBody());
        $this->assertStringContainsString("bar[1] secret.txt text/x-whatever 15", $response->getBody());
    }

    public function testRawPostData()
    {
        $data = 'Nothing to see here, move along';

        $this->request->setMethod(Request2::METHOD_POST)
            ->setBody($data);
        $response = $this->request->send();
        $this->assertEquals($data, $response->getBody());
    }

    public function testCookies()
    {
        $cookies = [
            'CUSTOMER'    => 'WILE_E_COYOTE',
            'PART_NUMBER' => 'ROCKET_LAUNCHER_0001'
        ];

        foreach ($cookies as $k => $v) {
            $this->request->addCookie($k, $v);
        }
        $response = $this->request->send();
        $this->assertEquals(serialize($cookies), $response->getBody());
    }

    public function testTimeout()
    {
        $this->request->setConfig('timeout', 2);
        try {
            $this->request->send();
            $this->fail('Expected Request2Exception was not thrown');
        } catch (MessageException $e) {
            $this->assertEquals(Request2Exception::TIMEOUT, $e->getCode());
        }
    }

    public function testTimeoutInRequest()
    {
        $this->request->setConfig('timeout', 2)
            ->setUrl($this->baseUrl . 'postparameters.php')
            ->setBody(new SlowpokeBody(['foo' => 'some value'], []));
        try {
            $this->request->send();
            $this->fail('Expected MessageException was not thrown');
        } catch (MessageException $e) {
            $this->assertEquals(Request2Exception::TIMEOUT, $e->getCode());
        }
    }

    public function testBasicAuth()
    {
        $this->request->getUrl()->setQueryVariables([
            'user' => 'luser',
            'pass' => 'qwerty'
        ]);
        $wrong = clone $this->request;

        $this->request->setAuth('luser', 'qwerty');
        $response = $this->request->send();
        $this->assertEquals(200, $response->getStatus());

        $wrong->setAuth('luser', 'password');
        $response = $wrong->send();
        $this->assertEquals(401, $response->getStatus());
    }

    public function testDigestAuth()
    {
        $this->request->getUrl()->setQueryVariables([
            'user' => 'luser',
            'pass' => 'qwerty'
        ]);
        $wrong = clone $this->request;
        $observer = new EventSequenceObserver(['sentHeaders', 'receivedHeaders']);

        $this->request->setAuth('luser', 'qwerty', Request2::AUTH_DIGEST)
            ->attach($observer);
        $response = $this->request->send();
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(
            ['sentHeaders', 'receivedHeaders', 'sentHeaders', 'receivedHeaders'],
            $observer->sequence
        );

        $wrong->setAuth('luser', 'password', Request2::AUTH_DIGEST);
        $response = $wrong->send();
        $this->assertEquals(401, $response->getStatus());
    }

    public function testRedirectsDefault()
    {
        $observer = new EventSequenceObserver(['sentHeaders', 'sentBodyPart', 'sentBody', 'receivedHeaders']);
        $this->request->setUrl($this->baseUrl . 'redirects.php')
            ->setConfig(['follow_redirects' => true, 'strict_redirects' => false])
            ->setMethod(Request2::METHOD_POST)
            ->addPostParameter('foo', 'foo value')
            ->attach($observer);

        $response = $this->request->send();
        $this->assertStringContainsString('Method=GET', $response->getBody());
        $this->assertStringNotContainsString('foo', $response->getBody());
        $this->assertEquals($this->baseUrl . 'redirects.php?redirects=0', $response->getEffectiveUrl());
        $this->assertEquals(
            ['sentHeaders', 'sentBodyPart', 'sentBody', 'receivedHeaders', 'sentHeaders', 'receivedHeaders'],
            $observer->sequence
        );
    }

    public function testRedirectsStrict()
    {
        $observer = new EventSequenceObserver(['sentHeaders', 'sentBodyPart', 'sentBody', 'receivedHeaders']);
        $this->request->setUrl($this->baseUrl . 'redirects.php')
            ->setConfig(['follow_redirects' => true, 'strict_redirects' => true])
            ->setMethod(Request2::METHOD_POST)
            ->addPostParameter('foo', 'foo value')
            ->attach($observer);

        $response = $this->request->send();
        $this->assertStringContainsString('Method=POST', $response->getBody());
        $this->assertStringContainsString('foo', $response->getBody());
        $this->assertEquals(
            ['sentHeaders', 'sentBodyPart', 'sentBody', 'receivedHeaders',
                  'sentHeaders', 'sentBodyPart', 'sentBody', 'receivedHeaders'],
            $observer->sequence
        );
    }

    public function testRedirectsLimit()
    {
        $this->request->setUrl($this->baseUrl . 'redirects.php?redirects=4')
            ->setConfig(['follow_redirects' => true, 'max_redirects' => 2]);

        try {
            $this->request->send();
            $this->fail('Expected Request2Exception was not thrown');
        } catch (MessageException $e) {
            $this->assertEquals(Request2Exception::TOO_MANY_REDIRECTS, $e->getCode());
        }
    }

    public function testRedirectsRelative()
    {
        $this->request->setUrl($this->baseUrl . 'redirects.php?special=relative')
            ->setConfig(['follow_redirects' => true]);

        $response = $this->request->send();
        $this->assertStringContainsString('did relative', $response->getBody());
    }

    public function testRedirectsNonHTTP()
    {
        $this->request->setUrl($this->baseUrl . 'redirects.php?special=ftp')
            ->setConfig(['follow_redirects' => true]);

        try {
            $this->request->send();
            $this->fail('Expected Request2Exception was not thrown');
        } catch (MessageException $e) {
            $this->assertEquals(Request2Exception::NON_HTTP_REDIRECT, $e->getCode());
        }
    }

    public function testCookieJar()
    {
        $this->request->setUrl($this->baseUrl . 'setcookie.php?name=cookie_name&value=cookie_value');
        $req2 = clone $this->request;

        $this->request->setCookieJar()->send();
        $jar = $this->request->getCookieJar();
        $jar->store(
            ['name' => 'foo', 'value' => 'bar'],
            $this->request->getUrl()
        );

        $response = $req2->setUrl($this->baseUrl . 'cookies.php')->setCookieJar($jar)->send();
        $this->assertEquals(
            serialize(['cookie_name' => 'cookie_value', 'foo' => 'bar']),
            $response->getBody()
        );
    }

    public function testCookieJarAndRedirect()
    {
        $this->request->setUrl($this->baseUrl . 'redirects.php?special=cookie')
            ->setConfig('follow_redirects', true)
            ->setCookieJar();

        $response = $this->request->send();
        $this->assertEquals(serialize(['cookie_on_redirect' => 'success']), $response->getBody());
    }

    /**
     * @link http://pear.php.net/bugs/bug.php?id=20125
     */
    public function testChunkedRequest()
    {
        $data = [
            'long'      => str_repeat('a', 1000),
            'very_long' => str_repeat('b', 2000)
        ];

        $this->request->setMethod(Request2::METHOD_POST)
                      ->setUrl($this->baseUrl . 'postparameters.php')
                      ->setConfig('buffer_size', 512)
                      ->setHeader('Transfer-Encoding', 'chunked')
                      ->addPostParameter($data);

        $response = $this->request->send();
        $this->assertEquals(serialize($data), $response->getBody());
    }

    /**
     * @link http://pear.php.net/bugs/bug.php?id=19233
     * @link http://pear.php.net/bugs/bug.php?id=15937
     */
    public function testPreventExpectHeader()
    {
        $fp       = fopen(dirname(dirname(__DIR__)) . '/_files/bug_15305', 'rb');
        $observer = new HeaderObserver();
        $body     = new MultipartBody(
            [],
            [
                'upload' => [
                    'fp'       => $fp,
                    'filename' => 'bug_15305',
                    'type'     => 'application/octet-stream',
                    'size'     => 16338
                ]
            ]
        );

        $this->request->setMethod(Request2::METHOD_POST)
                      ->setUrl($this->baseUrl . 'uploads.php')
                      ->setHeader('Expect', '')
                      ->setBody($body)
                      ->attach($observer);

        $response = $this->request->send();
        $this->assertStringNotContainsString('Expect:', $observer->headers);
        $this->assertStringContainsString('upload bug_15305 application/octet-stream 16338', $response->getBody());
    }

    public function testDownloadObserverWithPlainBody()
    {
        $fp       = fopen('php://memory', 'r+');
        $observer = new Request2\Observers\UncompressingDownload($fp);

        $this->request->setConfig('store_body', false)
                      ->setUrl($this->baseUrl . 'download.php')
                      ->attach($observer);

        $this->request->send();
        rewind($fp);
        $this->assertEquals(str_repeat('0123456789abcdef', 128), fread($fp, 8192));
    }

    public function testDownloadObserverWithGzippedBody()
    {
        $fp       = fopen('php://memory', 'r+');
        $observer = new Request2\Observers\UncompressingDownload($fp);

        $this->request->setConfig('store_body', false)
                      ->attach($observer);

        $normal = clone $this->request;
        $normal->setUrl($this->baseUrl . 'download.php?gzip')
               ->send();

        $slow = clone $this->request;
        $slow->setUrl($this->baseUrl . 'download.php?gzip&slowpoke')
             ->send();

        rewind($fp);
        $this->assertEquals(str_repeat('0123456789abcdef', 256), fread($fp, 8192));
    }

    public function testDownloadObserverEnforcesSizeLimit()
    {
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Body length limit');
        $fp       = fopen('php://memory', 'r+');
        $observer = new Request2\Observers\UncompressingDownload($fp, 1000);

        $this->request->setConfig('store_body', false)
                      ->setUrl($this->baseUrl . 'download.php?gzip')
                      ->attach($observer);

        $this->request->send();
    }

    public function testIncompleteBody()
    {
        $events = ['receivedBodyPart', 'receivedBody'];
        $this->request->setHeader('Accept-Encoding', 'identity');

        $plain = clone $this->request;
        $plain->attach($observer = new EventSequenceObserver($events));
        $response = $plain->send();
        $this->assertEquals('This is a test', $response->getBody());
        $this->assertEquals($events, $observer->sequence);

        $chunked = clone $this->request;
        $chunked->getUrl()->setQueryVariable('chunked', 'yep');
        $chunked->attach($observer = new EventSequenceObserver($events));
        $response = $chunked->send();
        $this->assertStringContainsString('This is a test', $response->getBody());
        $this->assertEquals($events, $observer->sequence);
    }
}