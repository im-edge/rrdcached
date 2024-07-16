<?php

namespace IMEdge\RrdCached;

class RrdCachedStats
{
    // Hint: this should all be unsigned integers, using int is technically not correct

    /** @var int Number of nodes currently enqueued in the update queue */
    public int $queueLength;
    /** @var int Number of UPDATE commands received */
    public int $updatesReceived;
    /** @var int Number of FLUSH commands received */
    public int $flushesReceived;
    /** @var int Total number of updates, i.e. calls to rrd_update_r, since the daemon was started */
    public int $updatesWritten;
    /**
     * Total number of "data sets" written to disk since the daemon was started. A data set is one or more values passed
     * to the UPDATE command. For example: 1223661439:123:456 is one data set with two values. The term "data set" is
     * used to prevent confusion whether individual values or groups of values are counted
     *
     * @var int
     */
    public int $dDataSetsWritten;
    /** @var int Number of nodes in the cache */
    public int $treeNodesNumber;
    /** @var int Depth of the tree used for fast key lookup */
    public int $treeDepth;
    /** @var int Total number of bytes written to the journal since startup */
    public int $journalBytes;
    /** @var int Number of times the journal has been rotated since startup */
    public int $journalRotate;

    /**
     * Hint: these are all unsigned 64bit integers, so PHP int isn't enough
     *
     * @param string[] $resultRows
     */
    public static function parseResultRows(array $resultRows): RrdCachedStats
    {
        $self = new static();
        foreach ($resultRows as $line) {
            [$key, $value] =  preg_split('/:\s/', $line, 2);
            $self->{lcfirst($key)} = (int) $value;
        }

        return $self;
    }
}
