<?php

namespace Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Zkteco\Protocol\Contracts\Transport;
use Zkteco\Protocol\Exception\ConnectionException;
use Zkteco\Protocol\Protocol\Command;
use Zkteco\Protocol\Session\Session;
use Zkteco\Protocol\Value\DeviceConfig;
use Zkteco\Protocol\Value\Reply;

/**
 * Session lifecycle and the dirty-tracking that fixed a real data bug.
 *
 * Context, because this looks like bookkeeping and is not: after CMD_PREPARE_DATA
 * the device sends every announced byte whether or not anyone is still reading. If
 * a second command goes out on the same session, the leftovers arrive first — as
 * well-formed CMD_DATA packets indistinguishable from the new command's reply.
 *
 * That produced three symptoms in production: "fingerprints unknown" for every
 * employee, a probe reporting 0 users on a device holding 19, and template slot
 * probes shifted by exactly one request.
 *
 * `markDirty()` / `ensureClean()` are the fix. These tests exist so nobody removes
 * them as dead weight.
 */
class SessionLifecycleTest extends TestCase
{
    private function config(): DeviceConfig
    {
        return new DeviceConfig(host: '192.0.2.10');
    }

    private function session(FakeTransport $transport): Session
    {
        return new Session($transport, $this->config());
    }

    public function test_opening_completes_the_handshake_and_adopts_the_devices_session_id(): void
    {
        $transport = new FakeTransport(sessionIdToIssue: 4242);
        $session = $this->session($transport);

        $session->open();

        $this->assertTrue($session->isOpen());
        $this->assertSame([Command::CONNECT], $transport->sentCommands);
        $this->assertSame(4242, $transport->sessionId(), 'the device assigns the session id, not us');
    }

    public function test_a_refused_handshake_throws_and_closes_rather_than_leaving_a_half_open_link(): void
    {
        $transport = new FakeTransport(connectReply: Command::ACK_ERROR);
        $session = $this->session($transport);

        try {
            $session->open();
            $this->fail('a refused handshake must throw');
        } catch (ConnectionException $e) {
            $this->assertFalse($transport->isOpen(), 'the socket must not be left open');
            $this->assertTrue($e->isRetryable(), 'a refused connection is worth retrying');
        }
    }

    public function test_closing_says_goodbye_but_survives_a_device_that_has_already_gone(): void
    {
        $transport = new FakeTransport();
        $session = $this->session($transport);
        $session->open();

        $transport->throwOnSend = true;   // device vanished mid-close
        $session->close();

        $this->assertFalse($transport->isOpen(), 'the socket must close even if EXIT fails');
        $this->assertFalse($session->isOpen());
    }

    public function test_closing_an_already_closed_session_is_a_no_op(): void
    {
        $session = $this->session(new FakeTransport());

        $session->close();   // never opened

        $this->assertFalse($session->isOpen());
    }

    // --- Dirty tracking ----------------------------------------------------

    public function test_a_new_session_is_clean(): void
    {
        $session = $this->session(new FakeTransport());
        $session->open();

        $this->assertFalse($session->isDirty());
    }

    public function test_mark_dirty_records_that_the_device_may_still_be_streaming(): void
    {
        $session = $this->session(new FakeTransport());
        $session->open();

        $session->markDirty();

        $this->assertTrue($session->isDirty());
    }

    /**
     * The heart of the fix: a dirty session is torn down and rebuilt before the
     * next command, so leftover CMD_DATA packets cannot be mistaken for its reply.
     */
    public function test_ensure_clean_reopens_a_dirty_session(): void
    {
        $transport = new FakeTransport();
        $session = $this->session($transport);
        $session->open();

        $openings = $transport->openCount;
        $session->markDirty();
        $session->ensureClean();

        $this->assertSame($openings + 1, $transport->openCount, 'a dirty session must be rebuilt');
        $this->assertFalse($session->isDirty());
        $this->assertTrue($session->isOpen());
    }

    /** The common path must stay cheap, or every command pays for a handshake. */
    public function test_ensure_clean_does_nothing_when_the_session_is_clean(): void
    {
        $transport = new FakeTransport();
        $session = $this->session($transport);
        $session->open();

        $openings = $transport->openCount;
        $session->ensureClean();

        $this->assertSame($openings, $transport->openCount);
    }

    public function test_reopen_gets_a_fresh_session_id_and_clears_the_dirty_flag(): void
    {
        $transport = new FakeTransport(sessionIdToIssue: 100);
        $session = $this->session($transport);
        $session->open();
        $session->markDirty();

        $transport->sessionIdToIssue = 200;
        $session->reopen();

        $this->assertSame(200, $transport->sessionId());
        $this->assertFalse($session->isDirty());
    }

    /**
     * Two consecutive bulk reads, which is the exact sequence that returned the
     * first read's data for the second request.
     */
    public function test_two_bulk_reads_each_get_their_own_session(): void
    {
        $transport = new FakeTransport();
        $session = $this->session($transport);
        $session->open();

        $firstOpenings = $transport->openCount;

        // Read one: reopen first, take the bytes, then flag the stream as draining.
        $session->reopen();
        $session->markDirty();

        // Read two: must not reuse the session the first read left dirty.
        $session->ensureClean();

        $this->assertSame(
            $firstOpenings + 2,
            $transport->openCount,
            'each bulk read needs a session of its own',
        );
        $this->assertFalse($session->isDirty());
    }
}

/**
 * A Transport that answers from memory.
 *
 * The whole point of the Transport interface is that everything above it is
 * protocol logic and everything below is sockets — so the session lifecycle can be
 * tested exhaustively with no device on the network.
 */
class FakeTransport implements Transport
{
    public int $openCount = 0;
    public bool $throwOnSend = false;
    /** @var list<int> */
    public array $sentCommands = [];

    private bool $open = false;
    private int $sessionId = 0;

    public function __construct(
        public int $sessionIdToIssue = 4242,
        private readonly int $connectReply = Command::ACK_OK,
    ) {
    }

    public function open(): void
    {
        $this->open = true;
        $this->openCount++;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function send(int $command, string $data = ''): Reply
    {
        if ($this->throwOnSend) {
            throw new \RuntimeException('device went away');
        }

        $this->sentCommands[] = $command;

        return new Reply(
            command: $command === Command::CONNECT ? $this->connectReply : Command::ACK_OK,
            checksum: 0,
            sessionId: $this->sessionIdToIssue,
            replyId: 0,
            data: '',
        );
    }

    public function sendRaw(string $packet): void
    {
    }

    public function readUnsolicited(float $pollSeconds = 0.5): ?Reply
    {
        return null;
    }

    public function setReadTimeout(int $seconds, int $microseconds = 0): void
    {
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    public function setSessionId(int $sessionId): void
    {
        $this->sessionId = $sessionId;
    }
}
