<?php

namespace Amp\Mysql\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Mysql\Result;
use Amp\Pipeline\ConcurrentIterableIterator;
use Amp\Pipeline\ConcurrentIterator;
use Revolt\EventLoop;
use function Amp\async;

final class ConnectionResult implements Result, \IteratorAggregate
{
    private readonly ResultProxy $result;

    private readonly ConcurrentIterator $generator;

    private ?Future $nextResult = null;

    public function __construct(ResultProxy $result)
    {
        $this->result = $result;
        $this->generator = new ConcurrentIterableIterator(self::iterate($result));
    }

    public function getIterator(): \Traversable
    {
        return $this->generator;
    }

    private static function iterate(ResultProxy $result): \Generator
    {
        $next = self::fetchRow($result);

        try {
            if (!($row = $next->await())) {
                return;
            }

            // Column names are only available once a result row has been fetched.
            $columnNames = \array_column($result->columns, 'name');

            do {
                $next = self::fetchRow($result);
                yield \array_combine($columnNames, $row);
            } while ($row = $next->await());
        } finally {
            if (!isset($row)) {
                return; // Result fully consumed.
            }

            EventLoop::queue(static function () use ($next, $result): void {
                try {
                    // Discard remaining results if disposed.
                    while ($next->await()) {
                        $next = self::fetchRow($result);
                    }
                } catch (\Throwable) {
                    // Ignore errors while discarding result.
                }
            });
        }
    }

    /**
     * @return Future<array|null>
     */
    private static function fetchRow(ResultProxy $result): Future
    {
        if ($result->userFetched < $result->fetchedRows) {
            $row = $result->rows[$result->userFetched];
            unset($result->rows[$result->userFetched]);
            $result->userFetched++;
            return Future::complete($row);
        }

        if ($result->state === ResultProxy::ROWS_FETCHED) {
            return Future::complete();
        }

        $deferred = new DeferredFuture;

        /* We need to increment the internal counter, else the next time fetch is called,
         * it'll simply return the row we fetch here instead of fetching a new row
         * since callback order on promises isn't defined, we can't do this via onResolve() */
        $incRow = static function (?array $row) use ($result): ?array {
            unset($result->rows[$result->userFetched++]);
            return $row;
        };

        $result->deferreds[ResultProxy::UNFETCHED][] = [$deferred, null, $incRow];
        return $deferred->getFuture();
    }

    public function getNextResult(): ?Result
    {
        if ($this->nextResult) {
            return $this->nextResult->await();
        }

        $this->nextResult = async(function (): ?Result {
            $deferred = $this->result->next ?: $this->result->next = new DeferredFuture;
            $result = $deferred->getFuture()->await();

            if ($result instanceof ResultProxy) {
                return new self($result);
            }

            return $result; // Instance of CommandResult or null.
        });

        return $this->nextResult->await();
    }

    public function getRowCount(): ?int
    {
        return $this->result->affectedRows;
    }

    public function getColumnCount(): ?int
    {
        return $this->result->columnCount;
    }

    public function getLastInsertId(): ?int
    {
        return $this->result->insertId;
    }

    public function getColumnDefinitions(): ?array
    {
        if ($this->result->state >= ResultProxy::COLUMNS_FETCHED) {
            return $this->result->columns;
        }

        $deferred = new DeferredFuture;
        $this->result->deferreds[ResultProxy::COLUMNS_FETCHED][] = [$deferred, &$this->result->columns, null];
        return $deferred->getFuture()->await();
    }
}
