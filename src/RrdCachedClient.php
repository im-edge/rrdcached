<?php

namespace IMEdge\RrdCached;

use Exception;
use IMEdge\RrdStructure\DsList;
use IMEdge\RrdStructure\RraSet;
use IMEdge\RrdStructure\RrdInfo;
use Psr\Log\LoggerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\UnixConnector;
use RuntimeException;

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

    protected ?ConnectionInterface $connection = null;
    protected ?PromiseInterface $currentBatch = null;
    /** @var Deferred[] */
    protected array $pending = [];
    protected array $pendingCommands = [];
    protected string $buffer = '';
    protected array $bufferLines = [];
    protected ?array $availableCommands = null;

    public function __construct(
        protected string $socketFile,
        protected readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * When resolved usually returns 'PONG'
     */
    public function ping(): string
    {
        return $this->send(RrdCachedCommand::PING);
    }

    public function stats(): RrdCachedStats
    {
        return $this->send(RrdCachedCommand::STATS)->then(static function ($resultRows) {
            return RrdCachedResultParser::parseStats($resultRows);
        });
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
    public function batch(array $commands): array|bool
    {
        if (empty($commands)) {
            throw new RuntimeException('Cannot run BATCH with no command');
        }
        if ($this->currentBatch) {
            return $this->currentBatch->then(function () use ($commands) {
                // $this->logger->warning(
                //     'RRDCacheD: a BATCH is already in progress, queuing up.'
                //     . ' This could be a bug, please let us know!'
                // );

                return $this->batch($commands);
            });
        }

        // TODO: If a command manages it to be transmitted between "BATCH" and
        // it's commands, this could be an undesired race condition. We should
        // either combine both strings and parse two results - or implement some
        // other blocking logic.
        $commands = implode("\n", $commands) . "\n.";

        // BATCH gives: 0 Go ahead.  End with dot '.' on its own line.
        return $this->currentBatch = $this->send(RrdCachedCommand::BATCH)->then(function ($result) use ($commands) {
            return $this->send($commands)->then(function ($result) {
                if ($result === 'errors' || $result === true) { // TODO: either one or the other
                    // Was: '0 errors'
                    return true;
                }
                if (is_string($result)) {
                    $this->logger->debug('Unknown positive result string: ' . $result);
                    // Well... unknown string, but anyway: no error
                    return true;
                }
                if (is_array($result)) {
                    $res = [];
                    foreach ($result as $line) {
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

                throw new RuntimeException('Unexpected result from BATCH: ' . \var_export($result, 1));
            })->always(function () {
                $this->currentBatch = null;
            });
        });
    }

    /**
     * When resolved returns true on success
     *
     * This doesn't mean that all files have been flushed, but that FLUSHALL has
     * successfully been started.
     */
    public function flushAll(): bool
    {
        return $this->send(RrdCachedCommand::FLUSH_ALL)->then(function ($result) {
            // $result is 'Started flush.'
            return true;
        });
    }

    public function first(string $file, int $rra = 0): int
    {
        $file = static::quoteFilename($file);

        return $this->send(RrdCachedCommand::FIRST . " $file $rra")->then(function ($result) {
            return (int) $result;
        });
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

        return $this->send(RrdCachedCommand::LAST . " $file")->then(function ($result) {
            return (int) $result;
        })->otherwise(function (Exception $e) {
            return Error::forException($e);
        });
    }

    public function flush(string $file): bool
    {
        $file = static::quoteFilename($file);

        return $this->send(RrdCachedCommand::FLUSH . " $file")->then(function ($result) {
            // $result is 'Successfully flushed <path>/<filename>.rrd.'
            return true;
        });
    }

    public function forget(string $file): bool
    {
        $file = static::quoteFilename($file);

        return $this->send(RrdCachedCommand::FORGET . " $file")->then(function ($result) use ($file) {
            // $result is 'Gone!'
            $this->logger->debug("Forgot $file: $result");
            return true;
        })->otherwise(function ($result) use ($file) {
            // $result is 'No such file or directory'
            $this->logger->debug("Failed to forgot $file: $result");
            return false;
        });
    }

    public function flushAndForget(string $file): bool
    {
        $file = static::quoteFilename($file);

        return $this->flush($file)->then(function () use ($file) {
            return $this->forget($file);
        });
    }

    public function pending(string $file): array
    {
        $file = static::quoteFilename($file);
        return $this->send(RrdCachedCommand::PENDING . " $file")->then(function ($result) {
            if (is_array($result)) {
                return $result;
            }

            // '0 updates pending', so $result is 'updates pending'
            return [];
        })->otherwise(function () {
            return [];
        });
    }

    public function info(string $file): RrdInfo
    {
        return $this->rawInfo($file)->then(function ($result) {
            return RrdInfo::parseLines($result);
        });
    }

    public function tune(string $file, ...$parameters): RrdInfo
    {
        return $this->send(implode(' ', array_merge([$file], $parameters)))->then(function ($result) {
            return RrdInfo::parseLines($result);
        });
    }

    public function rawInfo(string $file): array
    {
        $file = static::quoteFilename($file);

        return $this->send(RrdCachedCommand::INFO . " $file");
    }

    protected function createFile(
        string $filename,
        int $step,
        int $start,
        DsList $dsList,
        RraSet $rraSet
    ): PromiseInterface {
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

    public function listAvailableCommands(): array
    {
        $deferred = new Deferred();
        if ($this->availableCommands === null) {
            $this->send(RrdCachedCommand::HELP)->then(function ($result) use ($deferred) {
                $this->availableCommands = RrdCachedResultParser::extractAvailableCommandsFromHelp($result);
                $deferred->resolve($this->availableCommands);
            }, function (Exception $e) use ($deferred) {
                $this->logger->error($e->getMessage());
                $deferred->reject($e);
            });
        } else {
            Loop::futureTick(function () use ($deferred) {
                try {
                    $deferred->resolve($this->availableCommands);
                } catch (\Throwable $e) {
                    $this->logger->error($e->getMessage());
                }
            });
        }

        return $deferred->promise();
    }

    public function hasCommand(string $commandName): bool
    {
        return $this
            ->listAvailableCommands()
            ->then(function ($commands) use ($commandName) {
                return in_array($commandName, $commands);
            });
    }

    public function listFiles(string $directory = '/'): array
    {
        return $this->send(RrdCachedCommand::LIST . " $directory")->then(function ($result) {
            sort($result);

            return $result;
        });
    }

    public function listRecursive(string $directory = '/'): array
    {
        return $this->send(RrdCachedCommand::LIST_RECURSIVE . " $directory")->then(function ($result) {
            sort($result);

            return $result;
        });
    }

    public function quit(): void
    {
        if ($this->connection === null) {
            return;
        }

        $deferred = new Deferred();
        $this->connection->on('close', function () use ($deferred) {
            $deferred->resolve();
        });

        $this->connection->write(RrdCachedCommand::QUIT);

        return $deferred->promise();
    }

    public function send(string $command): PromiseInterface
    {
        $command = rtrim($command, "\n");
        $this->pending[] = $deferred = new Deferred();
        $this->pendingCommands[] = $command;
        // Logger::debug
        // echo "Sending $command\n";

        // foreach (\preg_split('/\r\n/', "$command") as $l) {
        //     echo "> $l\n";
        // }
        if ($this->connection === null) {
            $this->logger->debug("Not yet connected, deferring $command");
            $this->connect()->then(function () use ($command, $deferred) {
                $this->logger->debug("Connected to RRDCacheD, now sending $command");
                $this->connection->write("$command\n");
            })->otherwise(function (Exception $error) use ($deferred) {
                $this->logger->error('Connection to RRDCacheD failed');
                $deferred->reject($error);
            });
        } else {
            // TODO: Drain if false?
            $this->connection->write("$command\n");
        }

        // Hint: used to be 5s, too fast?
        return timeout($deferred->promise(), 30);
    }

    protected function connect()
    {
        $this->availableCommands = null;
        $connector = new UnixConnector();

        $attempt = $connector->connect($this->socketFile)->then(function (ConnectionInterface $connection) {
            $this->connection = $connection;
            $this->initializeHandlers($connection);
        })->otherwise(function (Exception $e) {
            $this->logger->error('RRDCached connection error: ' . $e->getMessage());
            throw $e;
        });

        return timeout($attempt, 5);
    }

    protected function initializeHandlers(ConnectionInterface $connection)
    {
        $connection->on('end', function () {
            $this->logger->info('RRDCacheD Client ended');
            $this->connection = null;
        });
        $connection->on('error', function (\Exception $e) {
            $this->rejectAllPending($e);
            $this->logger->error('RRDCacheD Client error: ' . $e->getMessage());
            $this->connection = null;
        });

        $connection->on('close', function () {
            $this->logger->info('RRDCacheD Client closed');
            // In case of an error, they should already have been rejected
            $this->rejectAllPending(new RuntimeException('RRDCacheD Client closed'));
            $this->emit(self::ON_CLOSE);
            $this->connection = null;
        });

        $connection->on('data', function ($data) {
            $this->processData($data);
        });
    }

    protected function processData($data)
    {
        $this->buffer .= $data;
        $this->processBuffer();
    }

    protected function processBuffer()
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

    protected function checkForResults()
    {
        while (! empty($this->bufferLines)) {
            $current = $this->bufferLines[0];
            $pos = strpos($current, ' ');
            if ($pos === false) {
                $this->failForProtocolViolation();
            }
            $cntLines = substr($current, 0, $pos);
            // $this->logger->debug("< $current");
            if ($cntLines === '-1') {
                array_shift($this->bufferLines);
                $this->rejectNextPending(substr($current, $pos + 1));
            } elseif (ctype_digit($cntLines)) {
                $cntLines = (int) $cntLines;

                if ($cntLines === 0) {
                    if (empty($this->pending)) {
                        $this->failForProtocolViolation();
                    }

                    array_shift($this->bufferLines);
                    $result = substr($current, $pos + 1);
                    if ($result === 'errors') { // Output: 0 errors
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

    protected function resolveNextPending($result)
    {
        if (empty($this->pending)) {
            $this->failForProtocolViolation();
        }
        $next = array_shift($this->pending);
        array_shift($this->pendingCommands);
        $next->resolve($result);
    }

    protected function rejectNextPending($message)
    {
        $next = array_shift($this->pending);
        $command = array_shift($this->pendingCommands);
        $command = preg_replace('/\s.*$/', '', $command);
        $next->reject(new RuntimeException("$command: $message"));
    }

    protected function failForProtocolViolation()
    {
        $exception = new RuntimeException('Protocol exception, got: ' . $this->getFullBuffer());
        $this->rejectAllPending($exception);
        $this->connection->close();
        unset($this->connection);
    }

    protected function getFullBuffer(): string
    {
        if (empty($this->bufferLines)) {
            return $this->buffer;
        }

        return implode("\n", $this->bufferLines) . "\n" . $this->buffer;
    }

    protected function rejectAllPending(Exception $exception)
    {
        foreach ($this->pending as $deferred) {
            $deferred->reject($exception);
        }
    }
}
