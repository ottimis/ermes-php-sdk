<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\Http\CurlHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Prove sul trasporto vero, contro un server locale: qui si verifica quello che un finto
 * client non può dimostrare, cioè che i timeout scattino e che gli errori di rete arrivino
 * al chiamante invece di sparire.
 */
final class HttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $cmd  = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(__DIR__ . '/Support/server.php')
        );

        $pipes  = [];
        $server = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($server)) {
            return;
        }

        self::$server  = $server;
        self::$baseUrl = "http://127.0.0.1:{$port}";

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    public function testUnreachableHostReturnsAnErrorQuicklyInsteadOfBlocking(): void
    {
        $client = new CurlHttpClient();

        $started = microtime(true);
        $result  = $client->post('http://127.0.0.1:' . self::closedPort() . '/api/v1/events', ['a' => 1]);
        $elapsed = microtime(true) - $started;

        $this->assertNotNull($result['error'], 'a transport failure must say what went wrong');
        $this->assertSame(0, $result['statusCode']);
        $this->assertSame('', $result['body']);
        $this->assertLessThan(2.0, $elapsed, 'the caller must not wait on a host that is not there');
    }

    public function testSuccessfulCallHasNoError(): void
    {
        $this->requireServer();

        $result = (new CurlHttpClient())->post(
            self::$baseUrl . '/api/v1/events',
            ['topic' => 'contracts.approved'],
            ['Accept: application/json', 'Authorization: Basic abc']
        );

        $this->assertNull($result['error']);
        $this->assertSame(200, $result['statusCode']);

        $echo = json_decode($result['body'], true);
        $this->assertSame('POST', $echo['method']);
        $this->assertSame('{"topic":"contracts.approved"}', $echo['body']);
        $this->assertSame('Basic abc', $echo['auth']);
    }

    public function testHttpErrorStatusIsNotATransportError(): void
    {
        $this->requireServer();

        $result = (new CurlHttpClient())->get(self::$baseUrl . '/status?code=429');

        $this->assertSame(429, $result['statusCode']);
        $this->assertNull($result['error'], 'a 429 is an answer, not a failure to reach the core');
    }

    public function testEmptyResponseBodyIsHandled(): void
    {
        $this->requireServer();

        $result = (new CurlHttpClient())->get(self::$baseUrl . '/empty');

        $this->assertSame(204, $result['statusCode']);
        $this->assertSame('', $result['body']);
        $this->assertNull($result['error']);
    }

    public function testInvalidUtf8IsSubstitutedInsteadOfSendingAnEmptyBody(): void
    {
        $this->requireServer();

        // Un titolo che arriva da una tabella latin1 non deve trasformarsi in un POST senza
        // corpo e in una 400 incomprensibile.
        $result = (new CurlHttpClient())->post(self::$baseUrl . '/echo', ['title' => "Cessazione " . chr(0xE8)]);

        $this->assertNull($result['error']);
        $echo = json_decode($result['body'], true);
        $this->assertNotSame('', $echo['body'], 'the request must carry a body');
        $this->assertSame(['title' => "Cessazione \u{FFFD}"], json_decode($echo['body'], true));
    }

    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        $this->requireServer();

        $result = (new CurlHttpClient())->post(self::$baseUrl . '/echo', ['body' => 'Cessazione è fatta', 'url' => 'https://a/b']);

        $echo = json_decode($result['body'], true);
        $this->assertStringContainsString('Cessazione è fatta', $echo['body']);
        $this->assertStringContainsString('https://a/b', $echo['body']);
    }

    public function testUnserializableDataIsReportedInsteadOfSendingNothing(): void
    {
        $result = (new CurlHttpClient())->post('http://127.0.0.1:1/x', ['handle' => fopen('php://memory', 'r')]);

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('json_encode failed', $result['error']);
        $this->assertSame(0, $result['statusCode']);
    }

    public function testSilentHostHitsTheTimeoutAndReportsIt(): void
    {
        [$socket, $url] = self::silentEndpoint();

        try {
            $started = microtime(true);
            $result  = (new CurlHttpClient(timeoutMs: 300))->get($url);
            $elapsed = microtime(true) - $started;
        } finally {
            fclose($socket);
        }

        $this->assertNotNull($result['error'], 'a core that connects and never answers must not stay silent to the caller');
        $this->assertSame(0, $result['statusCode']);
        $this->assertLessThan(2.0, $elapsed);
    }

    public function testPerCallTimeoutOverridesTheDefault(): void
    {
        [$socket, $url] = self::silentEndpoint();

        try {
            $started = microtime(true);
            $result  = (new CurlHttpClient(timeoutMs: 10_000))->get($url, [], ['timeout_ms' => 300]);
            $elapsed = microtime(true) - $started;
        } finally {
            fclose($socket);
        }

        $this->assertNotNull($result['error']);
        $this->assertLessThan(2.0, $elapsed, 'the per-call timeout must win over the default');
    }

    /**
     * Un socket in ascolto che non risponde mai: il kernel accetta la connessione nel
     * backlog, cURL resta in attesa della risposta e scatta il timeout. È il modo più
     * stabile di provare un core che non risponde, senza tenere occupato il server di prova.
     *
     * @return array{0: resource, 1: string}
     */
    private static function silentEndpoint(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail("cannot open a listening socket: {$errstr}");
        }
        $name = (string) stream_socket_get_name($socket, false);

        return [$socket, 'http://' . $name . '/api/v1/events'];
    }

    private function requireServer(): void
    {
        if (!is_resource(self::$server)) {
            $this->markTestSkipped('the local PHP test server could not be started');
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return 8123;
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function closedPort(): int
    {
        // Una porta libera su localhost rifiuta subito la connessione: è il modo meno
        // instabile di simulare un core irraggiungibile in CI.
        return self::freePort();
    }
}
