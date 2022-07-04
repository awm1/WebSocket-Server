<?php declare(strict_types=1);

namespace BabDev\WebSocket\Server;

use BabDev\WebSocket\Server\WAMP\Topic;
use BabDev\WebSocket\Server\WAMP\WAMPConnection;
use BabDev\WebSocket\Server\WAMP\WAMPMessageRequest;

/**
 * The RPC message handler interface defines a handler for incoming RPC WAMP messages.
 */
interface RPCMessageHandler extends MessageHandler
{
    /**
     * Handles an RPC "CALL" WAMP message from the client.
     *
     * @param string $id The unique ID of the RPC, required to send a "CALLERROR" or "CALLRESULT" message
     */
    public function onCall(WAMPConnection $connection, string $id, Topic $topic, WAMPMessageRequest $request, array $params): void;
}
