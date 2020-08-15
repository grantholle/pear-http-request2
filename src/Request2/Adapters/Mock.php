<?php

namespace Pear\Http\Request2\Adapters;

use Pear\Http\Request2;
use Pear\Http\Request2\Exceptions\Exception;
use Pear\Http\Request2\Exceptions\Request2Exception;
use Pear\Http\Request2\Response;

/**
 * Mock adapter intended for testing
 *
 * PHP version 5
 *
 * LICENSE
 *
 * This source file is subject to BSD 3-Clause License that is bundled
 * with this package in the file LICENSE and available at the URL
 * https://raw.github.com/pear/Request2 /trunk/docs/LICENSE
 *
 * @category  HTTP
 * @package   Request2
 * @author    Alexey Borzov <avb@php.net>
 * @copyright 2008-2020 Alexey Borzov <avb@php.net>
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link      http://pear.php.net/package/Request2
 */

// pear-package-only /**
// pear-package-only  * Base class for Request2 adapters
// pear-package-only  */
// pear-package-only require_once 'HTTP/Request2/Adapter.php';

/**
 * Mock adapter intended for testing
 *
 * Can be used to test applications depending on Request2 package without
 * actually performing any HTTP requests. This adapter will return responses
 * previously added via addResponse
 * <code>
 * $mock = new Request2 _Adapter_Mock();
 * $mock->addResponse("HTTP/1.1 ... ");
 *
 * $request = new Request2 ();
 * $request->setAdapter($mock);
 *
 * // This will return the response set above
 * $response = $req->send();
 * </code>
 *
 * @category HTTP
 * @package  Request2
 * @author   Alexey Borzov <avb@php.net>
 * @license  http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @version  Release: @package_version@
 * @link     http://pear.php.net/package/Request2
 */
class Mock extends Adapter
{
    /**
     * A queue of responses to be returned by sendRequest()
     * @var  array
     */
    protected $responses = [];

    /**
     * Returns the next response from the queue built by addResponse
     *
     * Only responses without explicit URLs or with URLs equal to request URL
     * will be considered. If matching response is not found or the queue is
     * empty then default empty response with status 400 will be returned,
     * if an Exception object was added to the queue it will be thrown.
     *
     * @param Request2 $request HTTP request message
     * @return   Response
     * @throws   Exception
     */
    public function sendRequest(Request2 $request)
    {
        $requestUrl = (string)$request->getUrl();
        $response   = null;
        foreach ($this->responses as $k => $v) {
            if (!$v[1] || $requestUrl == $v[1]) {
                $response = $v[0];
                array_splice($this->responses, $k, 1);
                break;
            }
        }
        if (!$response) {
            return self::createResponseFromString("HTTP/1.1 400 Bad Request\r\n\r\n");

        } elseif ($response instanceof Response) {
            return $response;

        } else {
            // rethrow the exception
            $class   = get_class($response);
            $message = $response->getMessage();
            $code    = $response->getCode();
            throw new $class($message, $code);
        }
    }

    /**
     * Adds response to the queue
     *
     * @param mixed $response either a string, a pointer to an open file, an instance of Response or Exception
     * @param string|null $url A request URL this response should be valid for (see {@link http://pear.php.net/bugs/bug.php?id=19276})
     * @throws Request2Exception
     */
    public function addResponse($response, string $url = null)
    {
        if (is_string($response)) {
            $response = self::createResponseFromString($response);
        } elseif (is_resource($response)) {
            $response = self::createResponseFromFile($response);
        } elseif (
            !$response instanceof Response &&
            !$response instanceof Exception
        ) {
            throw new Request2Exception('Parameter is not a valid response');
        }

        $this->responses[] = [$response, $url];
    }

    /**
     * Creates a new Response object from a string
     *
     * @param string $str string containing HTTP response message
     * @return   Response
     * @throws   Request2Exception
     */
    public static function createResponseFromString($str)
    {
        $parts       = preg_split('!(\r?\n){2}!m', $str, 2);
        $headerLines = explode("\n", $parts[0]);
        $response    = new Response(array_shift($headerLines));
        foreach ($headerLines as $headerLine) {
            $response->parseHeaderLine($headerLine);
        }
        $response->parseHeaderLine('');
        if (isset($parts[1])) {
            $response->appendBody($parts[1]);
        }
        return $response;
    }

    /**
     * Creates a new Response object from a file
     *
     * @param resource $fp file pointer returned by fopen()
     * @return   Response
     * @throws   Request2Exception
     */
    public static function createResponseFromFile($fp)
    {
        $response = new Response(fgets($fp));
        do {
            $headerLine = fgets($fp);
            $response->parseHeaderLine($headerLine);
        } while ('' != trim($headerLine));

        while (!feof($fp)) {
            $response->appendBody(fread($fp, 8192));
        }
        return $response;
    }
}
