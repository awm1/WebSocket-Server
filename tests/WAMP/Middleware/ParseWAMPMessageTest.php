<?php declare(strict_types=1);

namespace BabDev\WebSocket\Server\Tests\WAMP\Middleware;

use BabDev\WebSocket\Server\Connection;
use BabDev\WebSocket\Server\Connection\ArrayAttributeStore;
use BabDev\WebSocket\Server\Server;
use BabDev\WebSocket\Server\WAMP\Exception\InvalidMessage;
use BabDev\WebSocket\Server\WAMP\Exception\UnsupportedMessageType;
use BabDev\WebSocket\Server\WAMP\MessageType;
use BabDev\WebSocket\Server\WAMP\Middleware\ParseWAMPMessage;
use BabDev\WebSocket\Server\WAMP\Topic;
use BabDev\WebSocket\Server\WAMP\TopicRegistry;
use BabDev\WebSocket\Server\WAMP\WAMPConnection;
use BabDev\WebSocket\Server\WAMPServerMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ParseWAMPMessageTest extends TestCase
{
    private MockObject&WAMPServerMiddleware $decoratedMiddleware;

    private MockObject&TopicRegistry $topicRegistry;

    private ParseWAMPMessage $middleware;

    protected function setUp(): void
    {
        $this->decoratedMiddleware = $this->createMock(WAMPServerMiddleware::class);
        $this->topicRegistry = $this->createMock(TopicRegistry::class);

        $this->middleware = new ParseWAMPMessage($this->decoratedMiddleware, $this->topicRegistry);
    }

    public function testGetSubProtocols(): void
    {
        $this->decoratedMiddleware->expects($this->once())
            ->method('getSubProtocols')
            ->willReturn(['ws']);

        $this->assertSame(
            ['ws', 'wamp'],
            $this->middleware->getSubProtocols(),
        );
    }

    #[TestDox('Handles a new connection being opened')]
    public function testOnOpen(): void
    {
        $attributeStore = new ArrayAttributeStore();

        $sentData = null;

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send')
            ->willReturnCallback(static function (mixed $data) use (&$sentData): void {
                $sentData = $data;
            });

        $this->middleware->onOpen($connection);

        $this->assertJson($sentData);

        /** @var array<int, mixed> $data */
        $data = json_decode((string) $sentData, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(MessageType::WELCOME, $data[0], 'When a connection is opened, a welcome message should be sent to the connected client.');
        $this->assertSame($attributeStore->get('wamp.session_id'), $data[1], 'The welcome message should specify the session ID generated by the middleware.');
        $this->assertSame(1, $data[2], 'The correct WAMP version should be specified in the welcome message.');
        $this->assertSame(Server::VERSION, $data[3], 'The server identity should be provided by default.');
    }

    #[TestDox('Handles incoming data on the connection for a WAMP "PREFIX" message')]
    public function testOnMessageForPrefixMessage(): void
    {
        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(4))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $prefix = 'testing';
        $uri = 'https://example.com/testing';

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::PREFIX, $prefix, $uri], \JSON_THROW_ON_ERROR));

        $this->assertSame(
            [$prefix => $uri],
            $attributeStore->get('wamp.prefixes'),
            "The prefix should be added to the connection's attributes.",
        );
    }

    /**
     * @return \Generator<string, array>
     */
    public static function dataCallMessage(): \Generator
    {
        yield 'Parameters as separate values' => [2, 'a', 'b'];

        yield 'Parameters as unkeyed array' => [2, ['a', 'b']];

        yield 'Parameters as keyed array' => [2, ['hello' => 'world', 'herp' => 'derp']];

        yield 'Parameters with multiple types when an array is not the first parameter' => [2, 'hi', ['hello', 'world']];

        yield 'Parameters with multiple types when an array is the first parameter' => [2, ['hello', 'world'], 'hi'];
    }

    #[DataProvider('dataCallMessage')]
    #[TestDox('Handles incoming data on the connection for a WAMP "CALL" message')]
    public function testOnMessageForCallMessage(mixed ...$args): void
    {
        /** @var int<0, max> $paramCount */
        $paramCount = array_shift($args);

        $uri = 'https://example.com/testing/'.random_int(1, 1000);
        $callId = uniqid();

        $message = [MessageType::CALL, $callId, $uri, ...$args];

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->decoratedMiddleware->expects($this->once())
            ->method('onCall')
            ->with($this->isInstanceOf(WAMPConnection::class), $callId, $uri, $this->isType('array'))
            ->willReturnCallback(static function (Connection $connection, string $id, string $resolvedUri, array $params) use ($paramCount): void {
                self::assertCount($paramCount, $params);
            });

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode($message, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection for a WAMP "SUBSCRIBE" message')]
    public function testOnMessageForSubscribeMessage(): void
    {
        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->topicRegistry->expects($this->once())
            ->method('has')
            ->with($uri)
            ->willReturn(false);

        $this->topicRegistry->expects($this->once())
            ->method('add')
            ->with($this->isInstanceOf(Topic::class));

        $this->decoratedMiddleware->expects($this->once())
            ->method('onSubscribe')
            ->with($this->isInstanceOf(WAMPConnection::class), $this->isInstanceOf(Topic::class));

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::SUBSCRIBE, $uri], \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection for a WAMP "UNSUBSCRIBE" message')]
    public function testOnMessageForUnsubscribeMessage(): void
    {
        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->topicRegistry->expects($this->once())
            ->method('has')
            ->with($uri)
            ->willReturn(false);

        $this->topicRegistry->expects($this->once())
            ->method('add')
            ->with($this->isInstanceOf(Topic::class));

        $this->decoratedMiddleware->expects($this->once())
            ->method('onUnsubscribe')
            ->with($this->isInstanceOf(WAMPConnection::class), $this->isInstanceOf(Topic::class));

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::UNSUBSCRIBE, $uri], \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection for a WAMP "PUBLISH" message with string payload and no extra params')]
    public function testOnMessageForPublishMessageWithStringPayloadNoExtraParams(): void
    {
        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->topicRegistry->expects($this->once())
            ->method('has')
            ->with($uri)
            ->willReturn(false);

        $this->topicRegistry->expects($this->once())
            ->method('add')
            ->with($this->isInstanceOf(Topic::class));

        $event = 'Simple event payload';

        $this->decoratedMiddleware->expects($this->once())
            ->method('onPublish')
            ->with($this->isInstanceOf(WAMPConnection::class), $this->isInstanceOf(Topic::class), $event, [], []);

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::PUBLISH, $uri, $event], \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection for a WAMP "PUBLISH" message with array payload and no extra params')]
    public function testOnMessageForPublishMessageWithArrayPayloadNoExtraParams(): void
    {
        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->topicRegistry->expects($this->once())
            ->method('has')
            ->with($uri)
            ->willReturn(false);

        $this->topicRegistry->expects($this->once())
            ->method('add')
            ->with($this->isInstanceOf(Topic::class));

        $event = ['hello' => 'world', 'herp' => 'derp'];

        $this->decoratedMiddleware->expects($this->once())
            ->method('onPublish')
            ->with($this->isInstanceOf(WAMPConnection::class), $this->isInstanceOf(Topic::class), $event, [], []);

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::PUBLISH, $uri, $event], \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection for a WAMP "PUBLISH" message with the "excludeMe" param')]
    public function testOnMessageForPublishMessageWithExcludeMeParam(): void
    {
        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->topicRegistry->expects($this->once())
            ->method('has')
            ->with($uri)
            ->willReturn(false);

        $this->topicRegistry->expects($this->once())
            ->method('add')
            ->with($this->isInstanceOf(Topic::class));

        $event = 'Simple event payload';

        $excludedSessions = [];

        $this->decoratedMiddleware->expects($this->once())
            ->method('onPublish')
            ->willReturnCallback(static function (Connection $connection, Topic $topic, array|string $event, array $exclude, array $eligible) use (&$excludedSessions): void {
                $excludedSessions = $exclude;
            });

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::PUBLISH, $uri, $event, true], \JSON_THROW_ON_ERROR));

        $this->assertSame(
            [$attributeStore->get('wamp.session_id')],
            $excludedSessions,
            'When the "excludeMe" param is provided, the user\'s connection should be in the exclude list.'
        );
    }

    #[TestDox('Handles incoming data on the connection for a WAMP "PUBLISH" message with a list of excluded sessions')]
    public function testOnMessageForPublishMessageWithListOfExcludedSessions(): void
    {
        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->topicRegistry->expects($this->once())
            ->method('has')
            ->with($uri)
            ->willReturn(false);

        $this->topicRegistry->expects($this->once())
            ->method('add')
            ->with($this->isInstanceOf(Topic::class));

        $event = 'Simple event payload';
        $exclude = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];

        $excludedSessions = [];

        $this->decoratedMiddleware->expects($this->once())
            ->method('onPublish')
            ->willReturnCallback(static function (Connection $connection, Topic $topic, array|string $event, array $exclude, array $eligible) use (&$excludedSessions): void {
                $excludedSessions = $exclude;
            });

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::PUBLISH, $uri, $event, $exclude], \JSON_THROW_ON_ERROR));

        $this->assertSame(
            $exclude,
            $excludedSessions,
            'The list of excluded sessions should be forwarded to inner middleware.'
        );
    }

    #[TestDox('Handles incoming data on the connection for a WAMP "PUBLISH" message with a list of eligible sessions')]
    public function testOnMessageForPublishMessageWithListOfEligibleSessions(): void
    {
        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->topicRegistry->expects($this->once())
            ->method('has')
            ->with($uri)
            ->willReturn(false);

        $this->topicRegistry->expects($this->once())
            ->method('add')
            ->with($this->isInstanceOf(Topic::class));

        $event = 'Simple event payload';
        $eligible = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];

        $eligibleSessions = [];

        $this->decoratedMiddleware->expects($this->once())
            ->method('onPublish')
            ->willReturnCallback(static function (Connection $connection, Topic $topic, array|string $event, array $exclude, array $eligible) use (&$eligibleSessions): void {
                $eligibleSessions = $eligible;
            });

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::PUBLISH, $uri, $event, [], $eligible], \JSON_THROW_ON_ERROR));

        $this->assertSame(
            $eligible,
            $eligibleSessions,
            'The list of eligible sessions should be forwarded to inner middleware.'
        );
    }

    #[TestDox('Handles incoming data on the connection for an unsupported WAMP message type')]
    public function testOnMessageForUnsupportedMessageType(): void
    {
        $this->expectException(UnsupportedMessageType::class);

        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $event = 'Simple event payload';

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::EVENT, $uri, $event], \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection with a message body that does not decode into an array')]
    public function testOnMessageWithNonArrayMessageType(): void
    {
        $this->expectException(InvalidMessage::class);

        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode($uri, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection with a message body that decodes into an associative array')]
    public function testOnMessageWithAssociativeArrayMessageType(): void
    {
        $this->expectException(InvalidMessage::class);

        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode(['uri' => $uri], \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection with a message body that includes a topic URI that is not a string')]
    public function testOnMessageWithNonStringTopicUri(): void
    {
        $this->expectException(InvalidMessage::class);

        $uri = 'https://example.com/testing/'.random_int(1, 1000);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, json_encode([MessageType::PUBLISH, [$uri], 'Testing'], \JSON_THROW_ON_ERROR));
    }

    #[TestDox('Handles incoming data on the connection with invalid JSON')]
    public function testOnMessageWithInvalidJson(): void
    {
        $this->expectException(InvalidMessage::class);

        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->middleware->onOpen($connection);
        $this->middleware->onMessage($connection, '"[7,["https:\/\/example.com\/testing\/255"],"Testing]"');
    }

    #[TestDox('Closes the connection')]
    public function testOnClose(): void
    {
        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $this->decoratedMiddleware->expects($this->once())
            ->method('onClose')
            ->with($this->isInstanceOf(WAMPConnection::class));

        $this->middleware->onOpen($connection);
        $this->middleware->onClose($connection);
    }

    #[TestDox('Handles an error')]
    public function testOnError(): void
    {
        $attributeStore = new ArrayAttributeStore();

        /** @var MockObject&Connection $connection */
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('getAttributeStore')
            ->willReturn($attributeStore);

        $connection->expects($this->once())
            ->method('send');

        $error = new \Exception('Testing');

        $this->decoratedMiddleware->expects($this->once())
            ->method('onError')
            ->with($this->isInstanceOf(WAMPConnection::class), $error);

        $this->middleware->onOpen($connection);
        $this->middleware->onError($connection, $error);
    }

    #[TestDox('The server identity can be managed')]
    public function testServerIdentity(): void
    {
        $newIdentity = 'Test-Identity/4.2';

        $this->assertSame(Server::VERSION, $this->middleware->getServerIdentity());

        $this->middleware->setServerIdentity($newIdentity);

        $this->assertSame($newIdentity, $this->middleware->getServerIdentity());
    }
}
