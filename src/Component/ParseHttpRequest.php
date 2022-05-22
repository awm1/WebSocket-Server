<?php declare(strict_types=1);

namespace BabDev\WebSocket\Server\Component;

use BabDev\WebSocket\Server\Connection;
use BabDev\WebSocket\Server\Connection\ClosesConnectionWithResponse;
use BabDev\WebSocket\Server\Http\GuzzleRequestParser;
use BabDev\WebSocket\Server\Http\RequestParser;
use BabDev\WebSocket\Server\RawDataServerMiddleware;
use BabDev\WebSocket\Server\RequestAwareServerMiddleware;
use Psr\Http\Message\RequestInterface;

/**
 * The parse HTTP request server component transforms the incoming HTTP request into a {@see RequestInterface} object
 * and forwards the message to the request-aware server middleware.
 */
final class ParseHttpRequest implements RawDataServerMiddleware
{
    use ClosesConnectionWithResponse;

    public function __construct(
        private readonly RequestAwareServerMiddleware $component,
        private readonly RequestParser $requestParser = new GuzzleRequestParser(),
    ) {
    }

    /**
     * Handles a new connection to the server.
     */
    public function onOpen(Connection $connection): void
    {
        $connection->getAttributeStore()->set('http.headers_received', false);
    }

    /**
     * Handles incoming data on the connection.
     */
    public function onMessage(Connection $connection, string $data): void
    {
        if (true === $connection->getAttributeStore()->get('http.headers_received')) {
            $this->component->onMessage($connection, $data);

            return;
        }

        try {
            if (null === ($request = $this->requestParser->parse($connection, $data))) {
                return;
            }
        } catch (\OverflowException) {
            $this->close($connection, 413);

            return;
        }

        $connection->getAttributeStore()->set('http.headers_received', true);

        $this->component->onOpen($connection, $request);
    }

    /**
     * Reacts to a connection being closed.
     */
    public function onClose(Connection $connection): void
    {
        if (true === $connection->getAttributeStore()->get('http.headers_received')) {
            $this->component->onClose($connection);
        }
    }

    /**
     * Reacts to an unhandled Throwable.
     */
    public function onError(Connection $connection, \Throwable $throwable): void
    {
        if (true === $connection->getAttributeStore()->get('http.headers_received')) {
            $this->component->onError($connection, $throwable);
        } else {
            $this->close($connection, 500);
        }
    }
}
