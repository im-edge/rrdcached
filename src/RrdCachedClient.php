<?php

namespace IMEdge\RrdCached;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Exception;
use IMEdge\RrdStructure\DsList;
use IMEdge\RrdStructure\RraSet;
use IMEdge\RrdStructure\RrdInfo;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use RuntimeException;

use function Amp\Socket\connect;
use function array_shift;
use function count;
use function ctype_digit;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function preg_replace;
use function rtrim;
use function sort;
use function strpos;
use function substr;

class RrdCachedClient
{
    public const ON_CLOSE = 'close';

    protected ?DeferredFuture $currentBatch = null;
    /** @var DeferredFuture[] */
    protected array $pending = [];
    protected array $pendingCommands = [];
    protected string $buffer = '';
    protected array $bufferLines = [];
    protected ?array $availableCommands = null;
    protected ?Socket $socket = null;
    protected ?DeferredCancellation $connectionCanceller = null;
    protected bool $debugCommunication = false;

    public function __construct(
        protected string $socketFile,
        protected string $dataDir,
        protected readonly ?LoggerInterface $logger = null
    ) {
    }

    public function debugCommunication(bool $debug = true): void
    {
        $this->debugCommunication = $debug;
    }

    public function close(): void
    {
        if ($this->connectionCanceller) {
            $this->connectionCanceller->cancel();
            $this->connectionCanceller = null;
        }
        $this->socket?->close();
        $this->socket = null;
        $this->rejectAllPending(new Exception('RrdCached client closed'));
    }

    /**
     * When resolved usually returns 'PONG'
     */
    public function ping(): string
    {
        $result = $this->send(RrdCachedCommand::PING);
        assert(is_string($result));

        return $result;
    }

    public function stats(): RrdCachedStats
    {
        return RrdCachedStats::parse($this->send(RrdCachedCommand::STATS));
    }

    /**
     * When resolved returns true on success, otherwise an Array with errors
     *
     * The error Array uses the original line number starting from 1 as a key,
     * giving each individual error as it's value
     *
     * Might look like this:
     * <code>
     * [
     *     4 => 'Can\'t use \'flushall\' here.',
     *     9 => 'No such file: /path/to/file.rrd'
     * ];
     * </code>
     *
     * @param array<int, string> $commands
     * @return array<int, string>|bool
     */
    public function batch(array $commands, ?Cancellation $cancellation = null): array|bool
    {
        if (empty($commands)) {
            throw new RuntimeException('Cannot run BATCH with no command');
        }
        if ($this->currentBatch) {
            $this->currentBatch->getFuture()->await();
            // $this->logger->warning(
            //     'RRDCacheD: a BATCH is already in progress, queuing up.'
            //     . ' This could be a bug, please let us know!'
            // );
        }
        $this->currentBatch = new DeferredFuture();

        // TODO: If a command manages it to be transmitted between "BATCH" and
        // it's commands, this could be an undesired race condition. We should
        // either combine both strings and parse two results - or implement some
        // other blocking logic.
        $commands = implode("\n", $commands) . "\n.";

        if (! $this->send(RrdCachedCommand::BATCH)) {
            throw new RuntimeException('Failed to start RrdCached BATCH');
        }
        // BATCH gives: 0 Go ahead.  End with dot '.' on its own line.
        $result = $this->send($commands);
        $this->currentBatch = null;
        if ($result === 'errors' || $result === true) { // TODO: either one or the other
            // Was: '0 errors'
            return true;
        }
        if (is_string($result)) {
            $this->logger?->notice('Unknown positive result when starting a batch string: ' . $result);
            // Well... unknown string, but anyway: no error
            return true;
        }
        if (is_array($result)) {
            $res = [];
            foreach ($result as $line) {
                assert(is_string($line));
                if (preg_match('/^(\d+)\s(.+)$/', $line, $match)) {
                    $res[(int) $match[1]] = $match[2];
                } else {
                    throw new RuntimeException(
                        'Unexpected result from BATCH: ' . implode('\\n', $result)
                    );
                }
            }

            return $res;
        }

        throw new RuntimeException('Unexpected result from BATCH: ' . \var_export($result, true));
    }

    /**
     * When resolved returns true on success
     *
     * This doesn't mean that all files have been flushed, but that FLUSHALL has
     * successfully been started.
     */
    public function flushAll(): bool
    {
        $this->send(RrdCachedCommand::FLUSH_ALL);
        // $result is 'Started flush.'
        return true;
    }

    public function first(string $file, int $rra = 0): int
    {
        $file = static::quoteFilename($file);
        return (int) $this->send(RrdCachedCommand::FIRST . " $file $rra");
    }

    /**
     *
     * Might give:
     *
     *     -1 Error: rrdcached: Invalid timestamp returned
     */
    public function last(string $file): int
    {
        $file = static::quoteFilename($file);
        return (int) $this->send(RrdCachedCommand::LAST . " $file");
    }

    public function flush(string $file): bool
    {
        $file = static::quoteFilename($file);

        $result = $this->send(RrdCachedCommand::FLUSH . " $file");
        // $result is 'Successfully flushed <path>/<filename>.rrd.'
        return true;
    }

    public function forget(string $file): bool
    {
        $file = static::quoteFilename($file);

        try {
            $result = $this->send(RrdCachedCommand::FORGET . " $file");
            // $result is 'Gone!'
            $this->logger?->debug("Forgot $file: $result");
            return true;
        } catch (Exception $e) {
            $this->logger?->notice("Failed to forget $file: " . $e->getMessage());
            return false;
        }
    }

    public function flushAndForget(string $file): bool
    {
        $file = static::quoteFilename($file);

        $this->flush($file);
        return $this->forget($file);
    }

    /**
     * @return string[]
     */
    public function pending(string $file): array
    {
        $file = static::quoteFilename($file);
        $result = $this->send(RrdCachedCommand::PENDING . " $file");
        if (is_array($result)) {
            return $result;
        }

        // '0 updates pending', so $result is 'updates pending'
        return [];
    }

    public function info(string $file): RrdInfo
    {
        return RrdInfo::parseLines($this->rawInfo($file), $this->dataDir);
    }

    public function rawInfo(string $file): array
    {
        $file = static::quoteFilename($file);

        return $this->send(RrdCachedCommand::INFO . " $file");
    }

    public function tune(string $file, ...$parameters): bool
    {
        $result = $this->send(implode(' ', array_merge(
            [RrdCachedCommand::TUNE, static::quoteFilename($file)],
            $parameters
        )));

        if (is_bool($result)) {
            $this->logger->debug('tune gives a boolean, TODO: remove this check');
        } else {
            $this->logger->debug('tune gives no boolean, TODO: fix this: ' . var_export($result, true));
        }

        return true;
    }

    protected function createFile(
        string $filename,
        int $step,
        int $start,
        DsList $dsList,
        RraSet $rraSet
    ): bool {
        return $this->send(\sprintf(
            RrdCachedCommand::CREATE . " %s -s %d -b %d %s %s",
            static::quoteFilename($filename),
            $step,
            $start,
            $dsList,
            $rraSet
        ));
    }

    public static function quoteFilename(string $filename): string
    {
        // TODO: do we need to escape/quote here?
        return \addcslashes($filename, ' ');
        return "'" . addcslashes($filename, "'") . "'";
    }

    /**
     * @return string[]
     */
    public function listAvailableCommands(): array
    {
        return $this->availableCommands ??= RrdCachedResultParser::extractAvailableCommandsFromHelp(
            RrdCachedResultParser::extractAvailableCommandsFromHelp($this->send(RrdCachedCommand::HELP))
        );
    }

    public function hasCommand(string $commandName): bool
    {
        return in_array($commandName, $this->listAvailableCommands());
    }

    /**
     * @return string[]
     */
    public function listFiles(string $directory = '/'): array
    {
        $result = $this->send(RrdCachedCommand::LIST . " $directory");
        sort($result);

        return $result;
    }

    /**
     * @return string[]
     */
    public function listRecursive(string $directory = '/'): array
    {
        $result = $this->send(RrdCachedCommand::LIST_RECURSIVE . " $directory");
        sort($result);

        return $result;
    }

    public function quit(): void
    {
        if ($this->socket === null) {
            return;
        }
        $deferred = new DeferredFuture();
        $this->socket->onClose(fn () => $deferred->complete());
        try {
            $this->socket->write(RrdCachedCommand::QUIT);
        } catch (ClosedException $e) {
            return;
        } catch (StreamException $e) {
            $this->close();
            return;
        }

        $deferred->getFuture()->await();
    }

    /**
     * @param string $command
     * @param Cancellation|null $canceller
     * @return array<string|bool>|string|bool
     * @throws StreamException
     * @throws CancelledException
     */
    public function send(string $command, ?Cancellation $canceller = null): string|array|bool
    {
        // TODO: reject commands with "\n"?
        $socket = $this->getSocket();
        $command = rtrim($command, "\n");
        $socket->write("$command\n");

        $this->pending[] = $deferred = new DeferredFuture();
        $this->pendingCommands[] = $command;
        if ($this->debugCommunication) {
            foreach (explode("\n", $command) as $l) {
                $this->logger?->debug("rrdCached > $l");
            }
        }

        return $deferred->getFuture()->await($canceller);
    }

    /**
     * @throws ConnectException
     * @throws CancelledException
     */
    protected function getSocket(): Socket
    {
        if ($this->socket === null) {
            $this->connectionCanceller = new DeferredCancellation();
            $socket = connect('unix://' . $this->socketFile);
            $this->logger?->notice('RRDCacheD Client connected to ' . $this->socketFile);
            $socket->onClose(function () {
                $this->logger?->notice('RRDCacheD Client closed');
                $this->rejectAllPending(new Exception('Connection closed'));
            });
            $this->socket = $socket;
            EventLoop::queue(fn () => $this->keepReadingFromSocket($socket));
        }

        return $this->socket;
    }

    protected function keepReadingFromSocket(Socket $socket): void
    {
        while (null !== ($data = $socket->read())) {
            $this->buffer .= $data;
            $this->processBuffer();
        }

        $this->socket = null;
    }

    protected function processBuffer(): void
    {
        $offset = 0;

        while (false !== ($pos = strpos($this->buffer, "\n", $offset))) {
            $line = substr($this->buffer, $offset, $pos - $offset);
            $offset = $pos + 1;
            $this->bufferLines[] = $line;
        }

        if ($offset > 0) {
            $this->buffer = substr($this->buffer, $offset);
        }

        $this->checkForResults();
    }

    protected function checkForResults(): void
    {
        while (! empty($this->bufferLines)) {
            $current = $this->bufferLines[0];
            $pos = strpos($current, ' ');
            if ($pos === false) {
                $this->failForProtocolViolation();
                return;
            }
            $cntLines = substr($current, 0, $pos);
            if ($this->debugCommunication) {
                $this->logger?->debug("rrdCached < $current");
            }
            if ($cntLines === '-1') {
                array_shift($this->bufferLines);
                $this->rejectNextPending(substr($current, $pos + 1));
                continue;
            }
            if (ctype_digit($cntLines)) {
                $cntLines = (int) $cntLines;

                if ($cntLines === 0) {
                    if (empty($this->pending)) {
                        $this->failForProtocolViolation();
                        return;
                    }

                    array_shift($this->bufferLines);
                    $result = substr($current, $pos + 1);
                    if (strtolower($result) === 'errors') { // Output: 0 errors
                        $result = true;
                    }
                    $this->resolveNextPending($result);
                    continue;
                }
                if (count($this->bufferLines) <= $cntLines) {
                    // We'll wait, there are more lines to come
                    return;
                }

                if (empty($this->pending)) {
                    $this->failForProtocolViolation();
                }

                array_shift($this->bufferLines);
                $result = [];
                for ($i = 0; $i < $cntLines; $i++) {
                    $result[] = array_shift($this->bufferLines);
                }

                $this->resolveNextPending($result);
            } else {
                array_shift($this->bufferLines);
                $this->failForProtocolViolation();
            }
        }
    }

    /**
     * @param array<string|bool>|string|bool $result
     * @return void
     */
    protected function resolveNextPending(array|string|bool $result): void
    {
        array_shift($this->pendingCommands);
        $next = array_shift($this->pending);
        if ($next === null) {
            $this->failForProtocolViolation();
            return;
        }

        $next->complete($result);
    }

    protected function rejectNextPending(string $message): void
    {
        $command = array_shift($this->pendingCommands);
        $next = array_shift($this->pending);
        if ($next === null) {
            $this->failForProtocolViolation();
            return;
        }
        $command = preg_replace('/\s.*$/', '', $command);
        $next->error(new RuntimeException("$command: $message"));
    }

    protected function failForProtocolViolation(): void
    {
        $exception = new RuntimeException('Protocol exception, got: ' . $this->getFullBuffer());
        $this->rejectAllPending($exception);
        $this->close();
    }

    protected function getFullBuffer(): string
    {
        if (empty($this->bufferLines)) {
            return $this->buffer;
        }

        return implode("\n", $this->bufferLines) . "\n" . $this->buffer;
    }

    protected function rejectAllPending(Exception $exception): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $this->pendingCommands = [];
        foreach ($pending as $deferred) {
            $deferred->error($exception);
        }
    }
}
