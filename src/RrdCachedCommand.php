<?php

namespace IMEdge\RrdCached;

final class RrdCachedCommand
{
    /**
     * BATCH
     *
     * This command initiates the bulk load of multiple commands. This is
     * designed for installations with extremely high update rates, since it
     * permits more than one command to be issued per read() and write().
     *
     * All commands are executed just as they would be if given individually,
     * except for output to the user. Messages indicating success are
     * suppressed, and error messages are delayed until the client is finished.
     *
     * Command processing is finished when the client sends a dot (".") on its
     * own line. After the client has finished, the server responds with an
     * error count and the list of error messages (if any). Each error messages
     * indicates the number of the command to which it corresponds, and the
     * error message itself. The first user command after BATCH is command
     * number one.
     *
     *     client:  BATCH
     *     server:  0 Go ahead.  End with dot '.' on its own line.
     *     client:  UPDATE x.rrd 1223661439:1:2:3            <--- command #1
     *     client:  UPDATE y.rrd 1223661440:3:4:5            <--- command #2
     *     client:  and so on...
     *     client:  .
     *     server:  2 Errors
     *     server:  1 message for command 1
     *     server:  12 message for command 12
     */
    public const BATCH = 'BATCH';

    /**
     * BATCH terminator
     */
    public const BATCH_DONE = '.';

    /**
     * CREATE filename [-s stepsize] [-b begintime] [-r sourcefile ...]
     *  [-t templatefile] [-O] DSdefinitions ... RRAdefinitions ...
     *
     * This will create the RRD file according to the supplied parameters,
     * provided the parameters are valid, and (if the -O option is given or if
     * the rrdcached was started with the -O flag) the specified filename does
     * not already exist.
     */
    public const CREATE = 'CREATE';

    /**
     * DUMP <filename> [-h none|xsd|dtd]
     *
     * Dumps the specified RRD to XML
     *
     * This has been implmented in the current rrdtool master, is not in v1.8.0
     */
    public const DUMP = 'DUMP';

    /**
     * FETCH filename CF [start [end] [ds ...]]
     *
     * Calls rrd_fetch with the specified arguments and returns the result in
     * text form. If necessary, the file is flushed to disk first. The client
     * side function rrdc_fetch (declared in rrd_client.h) parses the output
     * and behaves just like rrd_fetch_r for easy integration of remote queries.
     *
     * ds defines the columns to dump - if none are given then all are returned
     */
    public const FETCH = 'FETCH';

    /**
     * FETCHBIN filename CF [start [end] [ds ...]]
     *
     * Calls rrd_fetch with the specified arguments and returns the result in
     * text/binary form to avoid unnecessary un/marshalling overhead. If
     * necessary, the file is flushed to disk first. The client side function
     * rrdc_fetch (declared in rrd_client.h) parses the output and behaves just
     * like rrd_fetch_r for easy integration of remote queries. ds defines the
     * columns to dump - if none are given then all are returned
     */
    public const FETCH_BIN = 'FETCHBIN';

    /**
     * FIRST filename [rranum]
     *
     * Return the timestamp for the first CDP in the specified RRA. Default is
     * to use RRA zero if none is specified.
     */
    public const FIRST = 'FIRST';

    /**
     * FLUSH filename
     *
     * Causes the daemon to put filename to the head of the update queue
     * (possibly moving it there if the node is already enqueued). The
     * answer will be sent after the node has been dequeued.
     */
    public const FLUSH = 'FLUSH';

    /**
     * FLUSHALL
     *
     * Causes the daemon to start flushing ALL pending values to disk. This
     * returns immediately, even though the writes may take a long time.
     */
    public const FLUSH_ALL = 'FLUSHALL';

    /**
     * FORGET filename
     *
     * Removes filename from the cache. Any pending updates WILL BE LOST.
     */
    public const FORGET = 'FORGET';

    /**
     * HELP [command]
     *
     * Returns a short usage message. If no command is given, or command is
     * HELP, a list of commands supported by the daemon is returned. Otherwise,
     * a short description, possibly containing a pointer to a manual page, is
     * returned.
     *
     * Obviously, this is meant for interactive usage and the format in which
     * the commands and usage summaries are returned is not well-defined.
     */
    public const HELP = 'HELP';

    /**
     * INFO filename
     *
     * Return the configuration information for the specified RRD. Note that
     * the cache is not flushed before checking, as the client is expected to
     * request this separately if it is required.
     *
     * The information is returned, one item per line, with the format:
     *
     *     I<keyname> I<type> I<value>
     */
    public const INFO = 'INFO';

    /**
     * LAST filename
     *
     * Return the timestamp for the last update to the specified RRD. Note that
     * the cache is not flushed before checking, as the client is expected to
     * request this separately if it is required.
     */
    public const LAST = 'LAST';

    /**
     * LIST [RECURSIVE] I/<path>
     *
     * This command allows to list directories and rrd databases as seen by
     * the daemon. The root "directory" is the base_dir (see '-b dir'). When
     * invoked with 'LIST RECURSIVE /<path>' it will behave similarly to
     * 'ls -R' but limited to rrd files (listing all the rrd bases in the
     * subtree of <path>, skipping empty directories).
     */
    public const LIST = 'LIST';
    public const LIST_RECURSIVE = 'LIST RECURSIVE';

    /**
     * PENDING filename
     *
     * Shows any "pending" updates for a file, in order. The updates shown have
     * not yet been written to the underlying RRD file.
     */
    public const PENDING = 'PENDING';

    /**
     * PING
     *
     * PING-PONG, this is very useful when using connection pool between user
     * client and RRDCACHED.
     *
     * Example:
     *
     *     0 PONG
     */
    public const PING = 'PING';

    /**
     * QUIT
     *
     * Disconnect from rrdcached
     */
    public const QUIT = 'QUIT';

    /**
     * RESUME filename
     *
     * Resume writing to an RRD file previously suspended by SUSPEND or
     * SUSPENDALL.
     */
    public const RESUME = 'RESUME';

    /**
     * RESUMEALL
     *
     * Resume writing to all RRD files previously suspended by SUSPEND or
     * SUSPENDALL.
     */
    public const RESUME_ALL = 'RESUMEALL';

    /**
     * QUEUE
     *
     * Shows the files that are on the output queue. Returns zero or more lines
     * in the following format, where <num_vals> is the number of values to be
     * written for the <file>:
     *
     * <num_vals> <file>
     */
    public const QUEUE = 'QUEUE';

    /**
     * STATS
     *
     * Returns a list of metrics which can be used to measure the daemons
     * performance and check its status. For a description of the values
     * returned, see "Performance Values" below.
     *
     * The format in which the values are returned is similar to many other
     * line based protocols: Each value is printed on a separate line, each
     * consisting of the name of the value, a colon, one or more spaces and
     * the actual value.
     *
     * Example:
     *
     *     9 Statistics follow
     *     QueueLength: 0
     *     UpdatesReceived: 30
     *     FlushesReceived: 2
     *     UpdatesWritten: 13
     *     DataSetsWritten: 390
     *     TreeNodesNumber: 13
     *     TreeDepth: 4
     *     JournalBytes: 190
     *     JournalRotate: 0
     */
    public const STATS = 'STATS';

    /**
     * SUSPEND filename
     *
     * Suspend writing to an RRD file. While a file is suspended, all metrics
     * for it are cached in memory until RESUME is called for that file or
     * RESUMEALL is called.
     */
    public const SUSPEND = 'SUSPEND';

    /**
     * SUSPEND
     *
     * Suspend writing to all RRD files. While a file is suspended, all metrics
     * for it are cached in memory until RESUME is called for that file or
     * RESUMEALL is called.
     */
    public const SUSPEND_ALL = 'SUSPENDALL';

    /**
     * TUNE <filename> [options]
     *
     * Tunes the given file, takes the parameters as defined in rrdtool
     *
     * Available since rrdtool v1.8.0
     */
    public const TUNE = 'TUNE';

    /**
     * UPDATE filename values [values ...]
     *
     * Adds more data to a filename. This is the operation the daemon was
     * designed for, so describing the mechanism again is unnecessary. Read
     * "HOW IT WORKS" above for a detailed explanation.
     *
     * Note that rrdcached only accepts absolute timestamps in the update
     * values. Update strings like "N:1:2:3" are automatically converted to
     * absolute time by the RRD client library before sending to rrdcached.
     */
    public const UPDATE = 'UPDATE';

    /**
     * WROTE filename
     *
     * This command is written to the journal after a file is successfully
     * written out to disk. It is used during journal replay to determine which
     * updates have already been applied. It is only valid in the journal; it
     * is not accepted from the other command channels.
     */
    public const WROTE = 'WROTE';
}
