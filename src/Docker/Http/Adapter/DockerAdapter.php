<?php

namespace Docker\Http\Adapter;

use Docker\Exception\APIException;
use Docker\Http\Stream\AttachStream;
use Docker\Http\Stream\ChunkedStream;

use GuzzleHttp\Adapter\AdapterInterface;
use GuzzleHttp\Adapter\TransactionInterface;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;

class DockerAdapter implements AdapterInterface
{
    const CHUNK_SIZE = 8192;

    /** @var string */
    private $entrypoint;

    /** @var MessageFactoryInterface */
    private $messageFactory;

    public function __construct(MessageFactoryInterface $messageFactory, $entrypoint)
    {
        $this->entrypoint     = $entrypoint;
        $this->messageFactory = $messageFactory;

        stream_filter_register('chunk', '\Docker\Http\Stream\Filter\Chunk');
    }

    /**
     * Transfers an HTTP request and populates a response
     *
     * @param TransactionInterface $transaction Transaction abject to populate
     *
     * @return ResponseInterface
     */
    public function send(TransactionInterface $transaction)
    {
        // HTTP/1.1 streams using the PHP stream wrapper require a
        // Connection: close header. Setting here so that it is added before
        // emitting the request.before_send event.
        $request = $transaction->getRequest();
        if ($request->getProtocolVersion() == '1.1' &&
            !$request->hasHeader('Connection')
        ) {
            $transaction->getRequest()->setHeader('Connection', 'close');
        }

        try {
            RequestEvents::emitBefore($transaction);
            if (!$transaction->getResponse()) {
                $this->createResponse($transaction);
                RequestEvents::emitComplete($transaction);
            }

            return $transaction->getResponse();
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new APIException($e->getResponse()->getBody()->__toString(), $e->getRequest(), $e->getResponse(), $e);
            }

            throw $e;
        }
    }

    private function createResponse(TransactionInterface $transaction)
    {
        $errorNo  = null;
        $errorMsg = null;

        $request = $transaction->getRequest();

        if ($request->getConfig()['stream']) {
            $request->setHeader('Transfer-Encoding', 'chunked');
        } elseif ($request->getBody() !== null) {
            $request->setHeader('Content-Length', $request->getBody()->getSize());
        }

        $socket = @stream_socket_client($this->entrypoint, $errorNo, $errorMsg, $this->getDefaultTimeout($transaction));
        if(!$socket) {
            throw new RequestException(sprintf('Cannot open socket connection: %s [code %d]', $errorMsg, $errorNo), $request);
        }

        // Write headers
        $isWrite = $this->fwrite($socket, $this->getRequestHeaderAsString($request));

        // Write body if set
        if ($request->getBody() !== null && $isWrite !== false) {
            $stream = $request->getBody();
            $filter = null;

            if ($request->getHeader('Transfer-Encoding') == 'chunked') {
                $filter = stream_filter_prepend($socket, 'chunk', STREAM_FILTER_WRITE);
            }

            while (!$stream->eof() && $isWrite) {
                $isWrite = $this->fwrite($socket, $stream->read(self::CHUNK_SIZE));
            }

            if ($request->getHeader('Transfer-Encoding') == 'chunked') {
                stream_filter_remove($filter);

                if (false !== $isWrite) {
                    $isWrite = $this->fwrite($socket, "0\r\n\r\n");
                }
            }
        }

        stream_set_timeout($socket, $this->getDefaultTimeout($transaction));

        // Response should be available, extract headers
        do {
            $response = $this->getResponseWithHeaders($socket);
        } while($response !== null && $response->getStatusCode() == 100);

        //Check timeout
        $metadata = stream_get_meta_data($socket);

        if ($metadata['timed_out']) {
            throw new RequestException('Timed out while reading socket', $request, $response);
        }

        if (false === $isWrite) {
            // When an error happen and no response it is most probably due to TLS configuration
            if ($response === null) {
                throw new RequestException('Error while sending request (Broken Pipe), check your TLS configuration and logs in docker daemon for more information ', $request);
            }

            throw new RequestException('Error while sending request (Broken Pipe)', $request, $response);
        }

        if (null == $response) {
            throw new RequestException('No response could be parsed: check server log', $request);
        }

        $this->setResponseStream($response, $socket);
        $transaction->setResponse($response);

        return $response;
    }

    private function getResponseWithHeaders($stream)
    {
        $headers = array();

        while (($line = fgets($stream)) !== false) {

            if (rtrim($line) === '') {
                break;
            }

            $headers[] = trim($line);
        }

        $parts = explode(' ', array_shift($headers), 3);

        if (count($parts) <= 1) {
            return null;
        }

        $options = ['protocol_version' => substr($parts[0], -3)];
        if (isset($parts[2])) {
            $options['reason_phrase'] = $parts[2];
        }

        // Set the size on the stream if it was returned in the response
        $responseHeaders = [];
        foreach ($headers as $header) {
            $headerParts = explode(':', $header, 2);
            $responseHeaders[trim($headerParts[0])] = isset($headerParts[1])
                ? trim($headerParts[1])
                : '';
        }

        $response = new Response($parts[1], $responseHeaders, null, $options);

        return $response;
    }

    private function setResponseStream(Response $response, $socket)
    {
        if ($response->getHeader('Transfer-Encoding') == "chunked") {
            $stream = new ChunkedStream($socket);
        } elseif ($response->getHeader('Content-Type') == "application/vnd.docker.raw-stream") {
            $stream = new AttachStream($socket);
        } else {
            $stream = new Stream($socket);
        }

        $response->setBody($stream);
    }

    private function getDefaultTimeout(TransactionInterface $transaction)
    {
        $timeout = $transaction->getRequest()->getConfig()->get('timeout');

        if ($timeout !== null) {
            return $timeout;
        }

        $timeout = $transaction->getClient()->getDefaultOption('timeout');

        if (null == $timeout) {
            $timeout = ini_get('default_socket_timeout');
        }

        return $timeout;
    }

    private function getRequestHeaderAsString(RequestInterface $request)
    {
        $message  = vsprintf('%s %s HTTP/%s', [
                strtoupper($request->getMethod()),
                $request->getUrl(),
                $request->getProtocolVersion()
            ])."\r\n";

        foreach ($request->getHeaders() as $name => $values) {
            $message .= $name . ': ' . implode(', ', $values) . "\r\n";
        }

        $message .= "\r\n";

        return $message;
    }

    /**
     * Replace fwrite behavior as api is broken in PHP
     *
     * @see https://secure.phabricator.com/rPHU69490c53c9c2ef2002bc2dd4cecfe9a4b080b497
     *
     * @param resource $stream The stream resource
     * @param string   $bytes  Bytes written in the stream
     *
     * @return bool|int false if pipe is broken, number of bytes written otherwise
     */
    private function fwrite($stream, $bytes)
    {
        if (!strlen($bytes)) {
            return 0;
        }

        $result = @fwrite($stream, $bytes);
        if ($result !== 0) {
            // In cases where some bytes are witten (`$result > 0`) or
            // an error occurs (`$result === false`), the behavior of fwrite() is
            // correct. We can return the value as-is.
            return $result;
        }

        // If we make it here, we performed a 0-length write. Try to distinguish
        // between EAGAIN and EPIPE. To do this, we're going to `stream_select()`
        // the stream, write to it again if PHP claims that it's writable, and
        // consider the pipe broken if the write fails.

        $read = array();
        $write = array($stream);
        $except = array();

        @stream_select($read, $write, $except, 0);

        if (!$write) {
            // The stream isn't writable, so we conclude that it probably really is
            // blocked and the underlying error was EAGAIN. Return 0 to indicate that
            // no data could be written yet.
            return 0;
        }

        // If we make it here, PHP **just** claimed that this stream is writable, so
        // perform a write. If the write also fails, conclude that these failures are
        // EPIPE or some other permanent failure.
        $result = @fwrite($stream, $bytes);
        if ($result !== 0) {
            // The write worked or failed explicitly. This value is fine to return.
            return $result;
        }

        // We performed a 0-length write, were told that the stream was writable, and
        // then immediately performed another 0-length write. Conclude that the pipe
        // is broken and return `false`.
        return false;
    }
}