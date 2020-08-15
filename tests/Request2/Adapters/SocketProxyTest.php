<?php

namespace Tests\Request2\Adapters;

use Pear\Http\Request2\Adapters\Socket;

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

/**
 * Unit test for Socket Adapter of HTTP_Request2 working through proxy
 */
class SocketProxyTest extends CommonNetworkTest
{
   /**
    * Configuration for HTTP Request object
    * @var array
    */
    protected $config = [
        'adapter' => Socket::class,
    ];

    protected function setUp(): void
    {
        if (!defined('HTTP_REQUEST2_TESTS_PROXY_HOST') || !HTTP_REQUEST2_TESTS_PROXY_HOST) {
            $this->markTestSkipped('Proxy is not configured');

        } else {
            $this->config += [
                'proxy_host'        => HTTP_REQUEST2_TESTS_PROXY_HOST,
                'proxy_port'        => HTTP_REQUEST2_TESTS_PROXY_PORT,
                'proxy_user'        => HTTP_REQUEST2_TESTS_PROXY_USER,
                'proxy_password'    => HTTP_REQUEST2_TESTS_PROXY_PASSWORD,
                'proxy_auth_scheme' => HTTP_REQUEST2_TESTS_PROXY_AUTH_SCHEME,
                'proxy_type'        => HTTP_REQUEST2_TESTS_PROXY_TYPE
            ];
            parent::setUp();
        }
    }
}
