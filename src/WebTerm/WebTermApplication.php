<?php

namespace MakeDev\Orca\WebTerm;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use MakeDev\Orca\Models\OrcaSession;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use SplObjectStorage;

class WebTermApplication
{
    private SocketServer $server;

    /** @var SplObjectStorage<ConnectionInterface, WebTermConnection> */
    private SplObjectStorage $connections;

    private WebTermTokenService $tokenService;

    private ServerNegotiator $negotiator;

    private int $connectionCount = 0;

    public function __construct(
        private LoopInterface $loop,
        string $host,
        int $port,
    ) {
        $this->connections = new SplObjectStorage;
        $this->tokenService = new WebTermTokenService;
        $this->negotiator = new ServerNegotiator(new RequestVerifier, new HttpFactory);

        $this->server = new SocketServer("{$host}:{$port}", [], $this->loop);
        $this->server->on('connection', fn (ConnectionInterface $conn) => $this->handleRawConnection($conn));
    }

    public function getAddress(): ?string
    {
        return $this->server->getAddress();
    }

    private function handleRawConnection(ConnectionInterface $rawConn): void
    {
        $buffer = '';

        $rawConn->on('data', function (string $data) use ($rawConn, &$buffer): void {
            $buffer .= $data;

            // Wait for complete HTTP headers
            if (! str_contains($buffer, "\r\n\r\n")) {
                return;
            }

            // Parse the HTTP request for WebSocket upgrade
            $request = Message::parseRequest($buffer);
            $buffer = '';

            $response = $this->negotiator->handshake($request);

            if ($response->getStatusCode() !== 101) {
                $rawConn->write(Message::toString($response));
                $rawConn->end();

                return;
            }

            $rawConn->write(Message::toString($response));

            // Extract token from query string
            parse_str($request->getUri()->getQuery(), $params);
            $token = $params['token'] ?? '';

            // Validate and set up WebSocket message handling
            $this->onUpgraded($rawConn, $token);
        });
    }

    private function onUpgraded(ConnectionInterface $rawConn, string $token): void
    {
        $maxConnections = (int) config('orca.webterm.max_connections', 5);

        if ($this->connectionCount >= $maxConnections) {
            $this->sendWsMessage($rawConn, json_encode(['type' => 'error', 'message' => 'Max connections reached']));
            $rawConn->end();

            return;
        }

        if (! $token) {
            $this->sendWsMessage($rawConn, json_encode(['type' => 'error', 'message' => 'Missing token']));
            $rawConn->end();

            return;
        }

        $payload = $this->tokenService->validate($token);

        if (! $payload) {
            $this->sendWsMessage($rawConn, json_encode(['type' => 'error', 'message' => 'Invalid or expired token']));
            $rawConn->end();

            return;
        }

        $session = OrcaSession::find($payload['session_id']);

        if (! $session) {
            $this->sendWsMessage($rawConn, json_encode(['type' => 'error', 'message' => 'Session not found']));
            $rawConn->end();

            return;
        }

        $this->connectionCount++;

        // Create a send callback that wraps data in WebSocket frames
        $sendCallback = function (string $data) use ($rawConn): void {
            $this->sendWsMessage($rawConn, $data);
        };

        $webTermConn = new WebTermConnection($sendCallback, $session, $this->loop);
        $this->connections->attach($rawConn, $webTermConn);

        // Set up WebSocket message parsing via ratchet/rfc6455
        $closeFrameChecker = new CloseFrameChecker;

        $messageBuffer = new MessageBuffer(
            $closeFrameChecker,
            function ($message) use ($webTermConn): void {
                $data = json_decode((string) $message, true);

                if (! is_array($data) || ! isset($data['type'])) {
                    return;
                }

                match ($data['type']) {
                    'input' => $webTermConn->writeToProcess($data['data'] ?? ''),
                    'resize' => $webTermConn->resize((int) ($data['cols'] ?? 120), (int) ($data['rows'] ?? 30)),
                    'heartbeat' => null,
                    default => null,
                };
            },
            function ($frame) use ($rawConn): void {
                // Handle close frame
                if ($frame->getOpcode() === Frame::OP_CLOSE) {
                    $rawConn->end($frame->getContents());
                }
            },
            true,
            null,
            null,
            null,
        );

        // Remove the initial HTTP handler and set up WebSocket frame parsing
        $rawConn->removeAllListeners('data');
        $rawConn->on('data', function (string $data) use ($messageBuffer): void {
            $messageBuffer->onData($data);
        });

        $rawConn->on('close', function () use ($rawConn, $webTermConn): void {
            $webTermConn->terminate();
            $this->connections->detach($rawConn);
            $this->connectionCount--;

            echo "[WebTerm] Connection closed\n";
        });

        $rawConn->on('error', function (\Throwable $e) use ($rawConn, $webTermConn): void {
            echo "[WebTerm] Error: {$e->getMessage()}\n";
            $webTermConn->terminate();
            $this->connections->detach($rawConn);
            $this->connectionCount--;
            $rawConn->close();
        });

        $webTermConn->start();

        echo "[WebTerm] Connection opened for session {$session->id}\n";
    }

    private function sendWsMessage(ConnectionInterface $conn, string $data): void
    {
        $frame = new Frame($data, true, Frame::OP_TEXT);
        $conn->write($frame->getContents());
    }
}
