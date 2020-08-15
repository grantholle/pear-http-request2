<?php

namespace Tests\Request2\Adapters;

use PHPUnit\Framework\TestCase;

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
 * Shows a skipped test if networked tests are not configured
 */
//class SocketTest extends TestCase
//{
//    public function testSocketAdapter()
//    {
//        $this->markTestSkipped('Socket Adapter tests need base URL configured.');
//    }
//}
//
///**
// * Shows a skipped test if proxy is not configured
// */
//class SocketProxyTest extends TestCase
//{
//    public function testSocketAdapterWithProxy()
//    {
//        $this->markTestSkipped('Socket Adapter proxy tests need base URL and proxy configured');
//    }
//}
//
///**
// * Shows a skipped test if networked tests are not configured or cURL extension is unavailable
// */
//class CurlTest extends TestCase
//{
//    public function testCurlAdapter()
//    {
//        $this->markTestSkipped('Curl Adapter tests need base URL configured and curl extension available');
//    }
//}
