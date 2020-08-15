<?php

namespace Pear\Http\Request2\Adapters;

use Pear\Http\Request2;
use Pear\Http\Request2\Exceptions\ConnectionException;
use Pear\Http\Request2\Exceptions\LogicException;
use Pear\Http\Request2\Exceptions\MessageException;
use Pear\Http\Request2\Exceptions\Request2Exception;
use Pear\Http\Request2\Response;
use Pear\Net\Url2;

/**
 * Adapter for Request2 wrapping around cURL extension
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

// pear-package-only /**
// pear-package-only  * Base class for Request2 adapters
// pear-package-only  */
// pear-package-only require_once 'HTTP/Request2/Adapter.php';

/**
 * Adapter for Request2 wrapping around cURL extension
 *
 * @category HTTP
 * @package  Request2
 * @author   Alexey Borzov <avb@php.net>
 * @license  http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @version  Release: @package_version@
 * @link     http://pear.php.net/package/Request2
 */
class Curl extends Adapter
{
    /**
     * Mapping of header names to cURL options
     * @var  array
     */
    protected static $headerMap = [
        'accept-encoding' => CURLOPT_ENCODING,
        'cookie'          => CURLOPT_COOKIE,
        'referer'         => CURLOPT_REFERER,
        'user-agent'      => CURLOPT_USERAGENT
    ];

    /**
     * Mapping of SSL context options to cURL options
     * @var  array
     */
    protected static $sslContextMap = [
        'ssl_verify_peer' => CURLOPT_SSL_VERIFYPEER,
        'ssl_cafile'      => CURLOPT_CAINFO,
        'ssl_capath'      => CURLOPT_CAPATH,
        'ssl_local_cert'  => CURLOPT_SSLCERT,
        'ssl_passphrase'  => CURLOPT_SSLCERTPASSWD
    ];

    /**
     * Mapping of CURLE_* constants to Exception subclasses and error codes
     * @var  array
     */
    protected static $errorMap = [
        CURLE_UNSUPPORTED_PROTOCOL  => [MessageException::class, Request2Exception::NON_HTTP_REDIRECT],
        CURLE_COULDNT_RESOLVE_PROXY => [ConnectionException::class],
        CURLE_COULDNT_RESOLVE_HOST  => [ConnectionException::class],
        CURLE_COULDNT_CONNECT       => [ConnectionException::class],
        // error returned from write callback
        CURLE_WRITE_ERROR           => [MessageException::class, Request2Exception::NON_HTTP_REDIRECT],
        CURLE_OPERATION_TIMEOUTED   => [MessageException::class, Request2Exception::TIMEOUT],
        CURLE_HTTP_RANGE_ERROR      => [MessageException::class],
        CURLE_SSL_CONNECT_ERROR     => [ConnectionException::class],
        CURLE_LIBRARY_NOT_FOUND     => [LogicException::class, Request2Exception::MISCONFIGURATION],
        CURLE_FUNCTION_NOT_FOUND    => [LogicException::class, Request2Exception::MISCONFIGURATION],
        CURLE_ABORTED_BY_CALLBACK   => [MessageException::class, Request2Exception::NON_HTTP_REDIRECT],
        CURLE_TOO_MANY_REDIRECTS    => [MessageException::class, Request2Exception::TOO_MANY_REDIRECTS],
        CURLE_SSL_PEER_CERTIFICATE  => [ConnectionException::class],
        CURLE_GOT_NOTHING           => [MessageException::class],
        CURLE_SSL_ENGINE_NOTFOUND   => [LogicException::class, Request2Exception::MISCONFIGURATION],
        CURLE_SSL_ENGINE_SETFAILED  => [LogicException::class, Request2Exception::MISCONFIGURATION],
        CURLE_SEND_ERROR            => [MessageException::class],
        CURLE_RECV_ERROR            => [MessageException::class],
        CURLE_SSL_CERTPROBLEM       => [LogicException::class, Request2Exception::INVALID_ARGUMENT],
        CURLE_SSL_CIPHER            => [ConnectionException::class],
        CURLE_SSL_CACERT            => [ConnectionException::class],
        CURLE_BAD_CONTENT_ENCODING  => [MessageException::class],
    ];

    /**
     * Response being received
     * @var  Response
     */
    protected $response;

    /**
     * Whether 'sentHeaders' event was sent to observers
     * @var  boolean
     */
    protected $eventSentHeaders = false;

    /**
     * Whether 'receivedHeaders' event was sent to observers
     * @var boolean
     */
    protected $eventReceivedHeaders = false;

    /**
     * Whether 'sentBoody' event was sent to observers
     * @var boolean
     */
    protected $eventSentBody = false;

    /**
     * Position within request body
     * @var  integer
     * @see  callbackReadBody()
     */
    protected $position = 0;

    /**
     * Information about last transfer, as returned by curl_getinfo()
     * @var  array
     */
    protected $lastInfo;

    /**
     * Creates a subclass of Request2Exception from curl error data
     *
     * @param resource $ch curl handle
     *
     * @return Request2Exception
     */
    protected static function wrapCurlError($ch)
    {
        $nativeCode = curl_errno($ch);
        $message    = 'Curl error: ' . curl_error($ch);
        if (!isset(self::$errorMap[$nativeCode])) {
            return new Request2Exception($message, 0, $nativeCode);
        } else {
            $class = self::$errorMap[$nativeCode][0];
            $code  = empty(self::$errorMap[$nativeCode][1])
                     ? 0 : self::$errorMap[$nativeCode][1];
            return new $class($message, $code, $nativeCode);
        }
    }

    /**
     * Sends request to the remote server and returns its response
     *
     * @param Request2 $request HTTP request message
     *
     * @return   Response
     * @throws   Request2Exception
     */
    public function sendRequest(Request2 $request)
    {
        if (!extension_loaded('curl')) {
            throw new LogicException(
                'cURL extension not available', Request2Exception::MISCONFIGURATION
            );
        }

        $this->request              = $request;
        $this->response             = null;
        $this->position             = 0;
        $this->eventSentHeaders     = false;
        $this->eventReceivedHeaders = false;
        $this->eventSentBody        = false;

        try {
            if (false === curl_exec($ch = $this->createCurlHandle())) {
                throw self::wrapCurlError($ch);
            }
        } finally {
            if (isset($ch)) {
                $this->lastInfo = curl_getinfo($ch);
                if (CURLE_OK !== curl_errno($ch)) {
                    $this->request->setLastEvent('warning', curl_error($ch));
                }
                curl_close($ch);
            }
            $response = $this->response;
            unset($this->request, $this->requestBody, $this->response);
        }

        if ($jar = $request->getCookieJar()) {
            $jar->addCookiesFromResponse($response);
        }

        if (0 < $this->lastInfo['size_download']) {
            $request->setLastEvent('receivedBody', $response);
        }
        return $response;
    }

    /**
     * Returns information about last transfer
     *
     * @return   array   associative array as returned by curl_getinfo()
     */
    public function getInfo()
    {
        return $this->lastInfo;
    }

    /**
     * Creates a new cURL handle and populates it with data from the request
     *
     * @return   resource    a cURL handle, as created by curl_init()
     * @throws   LogicException()
     * @throws   Request2_NotImplementedException
     */
    protected function createCurlHandle()
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            // setup write callbacks
            CURLOPT_HEADERFUNCTION => [$this, 'callbackWriteHeader'],
            CURLOPT_WRITEFUNCTION  => [$this, 'callbackWriteBody'],
            // buffer size
            CURLOPT_BUFFERSIZE     => $this->request->getConfig('buffer_size'),
            // connection timeout
            CURLOPT_CONNECTTIMEOUT => $this->request->getConfig('connect_timeout'),
            // save full outgoing headers, in case someone is interested
            CURLINFO_HEADER_OUT    => true,
            // request url
            CURLOPT_URL            => $this->request->getUrl()->getUrl()
        ]);

        // set up redirects
        if (!$this->request->getConfig('follow_redirects')) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        } else {
            if (!@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true)) {
                throw new LogicException(
                    'Redirect support in curl is unavailable due to open_basedir or safe_mode setting',
                    Request2Exception::MISCONFIGURATION
                );
            }
            curl_setopt($ch, CURLOPT_MAXREDIRS, $this->request->getConfig('max_redirects'));
            // limit redirects to http(s), works in 5.2.10+
            if (defined('CURLOPT_REDIR_PROTOCOLS')) {
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            // works in 5.3.2+, http://bugs.php.net/bug.php?id=49571
            if ($this->request->getConfig('strict_redirects') && defined('CURLOPT_POSTREDIR')) {
                curl_setopt($ch, CURLOPT_POSTREDIR, 3);
            }
        }

        // set local IP via CURLOPT_INTERFACE (request #19515)
        if ($ip = $this->request->getConfig('local_ip')) {
            curl_setopt($ch, CURLOPT_INTERFACE, $ip);
        }

        // request timeout
        if ($timeout = $this->request->getConfig('timeout')) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }

        // set HTTP version
        switch ($this->request->getConfig('protocol_version')) {
        case '1.0':
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
            break;
        case '1.1':
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }

        // set request method
        switch ($this->request->getMethod()) {
        case Request2::METHOD_GET:
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            break;
        case Request2::METHOD_POST:
            curl_setopt($ch, CURLOPT_POST, true);
            break;
        case Request2::METHOD_HEAD:
            curl_setopt($ch, CURLOPT_NOBODY, true);
            break;
        case Request2::METHOD_PUT:
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            break;
        default:
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->request->getMethod());
        }

        // set proxy, if needed
        if ($host = $this->request->getConfig('proxy_host')) {
            if (!($port = $this->request->getConfig('proxy_port'))) {
                throw new LogicException(
                    'Proxy port not provided', Request2Exception::MISSING_VALUE
                );
            }
            curl_setopt($ch, CURLOPT_PROXY, $host . ':' . $port);
            if ($user = $this->request->getConfig('proxy_user')) {
                curl_setopt(
                    $ch, CURLOPT_PROXYUSERPWD,
                    $user . ':' . $this->request->getConfig('proxy_password')
                );
                switch ($this->request->getConfig('proxy_auth_scheme')) {
                case Request2::AUTH_BASIC:
                    curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
                    break;
                case Request2::AUTH_DIGEST:
                    curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_DIGEST);
                }
            }
            if ($type = $this->request->getConfig('proxy_type')) {
                switch ($type) {
                case 'http':
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                    break;
                case 'socks5':
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                    break;
                default:
                    throw new Request2_NotImplementedException(
                        "Proxy type '{$type}' is not supported"
                    );
                }
            }
        }

        // set authentication data
        if ($auth = $this->request->getAuth()) {
            curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ':' . $auth['password']);
            switch ($auth['scheme']) {
            case Request2::AUTH_BASIC:
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                break;
            case Request2::AUTH_DIGEST:
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            }
        }

        // set SSL options
        foreach ($this->request->getConfig() as $name => $value) {
            if ('ssl_verify_host' == $name && null !== $value) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $value? 2: 0);
            } elseif (isset(self::$sslContextMap[$name]) && null !== $value) {
                curl_setopt($ch, self::$sslContextMap[$name], $value);
            }
        }

        $headers = $this->request->getHeaders();
        // make cURL automagically send proper header
        if (!isset($headers['accept-encoding'])) {
            $headers['accept-encoding'] = '';
        }

        if (($jar = $this->request->getCookieJar())
            && ($cookies = $jar->getMatching($this->request->getUrl(), true))
        ) {
            $headers['cookie'] = (empty($headers['cookie'])? '': $headers['cookie'] . '; ') . $cookies;
        }

        // set headers having special cURL keys
        foreach (self::$headerMap as $name => $option) {
            if (isset($headers[$name])) {
                curl_setopt($ch, $option, $headers[$name]);
                unset($headers[$name]);
            }
        }

        $this->calculateRequestLength($headers);
        if (isset($headers['content-length']) || isset($headers['transfer-encoding'])) {
            $this->workaroundPhpBug47204($ch, $headers);
        }

        // set headers not having special keys
        $headersFmt = [];
        foreach ($headers as $name => $value) {
            $canonicalName = implode('-', array_map('ucfirst', explode('-', $name)));
            $headersFmt[]  = $canonicalName . ': ' . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headersFmt);

        return $ch;
    }

    /**
     * Workaround for PHP bug #47204 that prevents rewinding request body
     *
     * The workaround consists of reading the entire request body into memory
     * and setting it as CURLOPT_POSTFIELDS, so it isn't recommended for large
     * file uploads, use Socket adapter instead.
     *
     * @param resource $ch       cURL handle
     * @param array    &$headers Request headers
     */
    protected function workaroundPhpBug47204($ch, &$headers)
    {
        // no redirects, no digest auth -> probably no rewind needed
        // also apply workaround only for POSTs, othrerwise we get
        // https://pear.php.net/bugs/bug.php?id=20440 for PUTs
        if (!$this->request->getConfig('follow_redirects')
            && (!($auth = $this->request->getAuth())
                || Request2::AUTH_DIGEST != $auth['scheme'])
            || Request2::METHOD_POST !== $this->request->getMethod()
        ) {
            curl_setopt($ch, CURLOPT_READFUNCTION, [$this, 'callbackReadBody']);

        } else {
            // rewind may be needed, read the whole body into memory
            if ($this->requestBody instanceof Request2\MultipartBody) {
                $this->requestBody = $this->requestBody->__toString();

            } elseif (is_resource($this->requestBody)) {
                $fp = $this->requestBody;
                $this->requestBody = '';
                while (!feof($fp)) {
                    $this->requestBody .= fread($fp, 16384);
                }
            }
            // curl hangs up if content-length is present
            unset($headers['content-length']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->requestBody);
        }
    }

    /**
     * Callback function called by cURL for reading the request body
     *
     * @param resource $ch     cURL handle
     * @param resource $fd     file descriptor (not used)
     * @param integer  $length maximum length of data to return
     *
     * @return   string      part of the request body, up to $length bytes
     */
    protected function callbackReadBody($ch, $fd, $length)
    {
        if (!$this->eventSentHeaders) {
            $this->request->setLastEvent(
                'sentHeaders', curl_getinfo($ch, CURLINFO_HEADER_OUT)
            );
            $this->eventSentHeaders = true;
        }
        if (in_array($this->request->getMethod(), self::$bodyDisallowed)
            || 0 == $this->contentLength || $this->position >= $this->contentLength
        ) {
            return '';
        }
        if (is_string($this->requestBody)) {
            $string = substr($this->requestBody, $this->position, $length);
        } elseif (is_resource($this->requestBody)) {
            $string = fread($this->requestBody, $length);
        } else {
            $string = $this->requestBody->read($length);
        }
        $this->request->setLastEvent('sentBodyPart', strlen($string));
        $this->position += strlen($string);
        return $string;
    }

    /**
     * Callback function called by cURL for saving the response headers
     *
     * @param resource $ch cURL handle
     * @param string $string response header (with trailing CRLF)
     *
     * @return   integer     number of bytes saved
     * @throws MessageException
     * @see      Response::parseHeaderLine()
     */
    protected function callbackWriteHeader($ch, $string)
    {
        if (!$this->eventSentHeaders
            // we may receive a second set of headers if doing e.g. digest auth
            // but don't bother with 100-Continue responses (bug #15785)
            || $this->eventReceivedHeaders && $this->response->getStatus() >= 200
        ) {
            $this->request->setLastEvent(
                'sentHeaders', curl_getinfo($ch, CURLINFO_HEADER_OUT)
            );
        }
        if (!$this->eventSentBody) {
            $upload = curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);
            // if body wasn't read by the callback, send event with total body size
            if ($upload > $this->position) {
                $this->request->setLastEvent(
                    'sentBodyPart', $upload - $this->position
                );
            }
            if ($upload > 0) {
                $this->request->setLastEvent('sentBody', $upload);
            }
        }
        $this->eventSentHeaders = true;
        $this->eventSentBody    = true;

        if ($this->eventReceivedHeaders || empty($this->response)) {
            $this->eventReceivedHeaders = false;
            $this->response             = new Response(
                $string, false, curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)
            );

        } else {
            $this->response->parseHeaderLine($string);
            if ('' == trim($string)) {
                // don't bother with 100-Continue responses (bug #15785)
                if (200 <= $this->response->getStatus()) {
                    $this->request->setLastEvent('receivedHeaders', $this->response);
                }

                if ($this->request->getConfig('follow_redirects') && $this->response->isRedirect()) {
                    $redirectUrl = new Url2($this->response->getHeader('location'));

                    // for versions lower than 5.2.10, check the redirection URL protocol
                    if (!defined('CURLOPT_REDIR_PROTOCOLS') && $redirectUrl->isAbsolute()
                        && !in_array($redirectUrl->getScheme(), ['http', 'https'])
                    ) {
                        return -1;
                    }

                    if ($jar = $this->request->getCookieJar()) {
                        $jar->addCookiesFromResponse($this->response);
                        if (!$redirectUrl->isAbsolute()) {
                            $redirectUrl = $this->request->getUrl()->resolve($redirectUrl);
                        }
                        if ($cookies = $jar->getMatching($redirectUrl, true)) {
                            curl_setopt($ch, CURLOPT_COOKIE, $cookies);
                        }
                    }
                }
                $this->eventReceivedHeaders = true;
                $this->eventSentBody        = false;
            }
        }
        return strlen($string);
    }

    /**
     * Callback function called by cURL for saving the response body
     *
     * @param resource $ch     cURL handle (not used)
     * @param string   $string part of the response body
     *
     * @return   integer     number of bytes saved
     * @throws   MessageException
     * @see      Response::appendBody()
     */
    protected function callbackWriteBody($ch, $string)
    {
        // cURL calls WRITEFUNCTION callback without calling HEADERFUNCTION if
        // response doesn't start with proper HTTP status line (see bug #15716)
        if (empty($this->response)) {
            throw new MessageException(
                "Malformed response: {$string}",
                Request2Exception::MALFORMED_RESPONSE
            );
        }
        if ($this->request->getConfig('store_body')) {
            $this->response->appendBody($string);
        }
        $this->request->setLastEvent('receivedBodyPart', $string);
        return strlen($string);
    }
}
?>
