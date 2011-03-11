<?php
namespace phpcassa\Connection;

use phpcassa\Connection\ConnectionWrapper;

$GLOBALS['THRIFT_ROOT'] = (__DIR__) . '/../../thrift';
require_once $GLOBALS['THRIFT_ROOT'].'/packages/cassandra/Cassandra.php';
require_once $GLOBALS['THRIFT_ROOT'].'/transport/TSocket.php';
require_once $GLOBALS['THRIFT_ROOT'].'/protocol/TBinaryProtocol.php';
require_once $GLOBALS['THRIFT_ROOT'].'/transport/TFramedTransport.php';
require_once $GLOBALS['THRIFT_ROOT'].'/transport/TBufferedTransport.php';

class ConnectionPool {

    const BASE_BACKOFF = 0.1;
    const MICROS = 1000000;
    const MAX_RETRIES = 2147483647; // 2^31 - 1
    private static $default_servers = array('localhost:9160');

    public $keyspace;
    private $servers;
    private $pool_size;
    private $timeout;
    private $recycle;
    private $max_retries;
    private $credentials;
    private $framed_transport;
    private $queue;
    private $keyspace_description = NULL;

    public function __construct($keyspace,
                                $servers=NULL,
                                $max_retries=5,
                                $send_timeout=5000,
                                $recv_timeout=5000,
                                $recycle=10000,
                                $credentials=NULL,
                                $framed_transport=true)
    {
        $this->keyspace = $keyspace;
        $this->send_timeout = $send_timeout;
        $this->recv_timeout = $recv_timeout;
        $this->recycle = $recycle;
        $this->max_retries = $max_retries;
        $this->credentials = $credentials;
        $this->framed_transport = $framed_transport;

        $this->stats = array(
            'created' => 0,
            'failed' => 0,
            'recycled' => 0);

        if ($servers == NULL)
            $servers = self::$default_servers;
        $this->servers = $servers;
        $this->pool_size = max(count($this->servers) * 2, 5);

        $this->queue = array();

        // Randomly permute the server list
        $n = count($servers);
        if ($n > 1) {
            foreach (range(0, $n - 1) as $i) {
                $j = rand($i, $n - 1);
                $temp = $servers[$j];
                $servers[$j] = $servers[$i];
                $servers[$i] = $temp;
            }
        }
        $this->list_position = 0;

        foreach(range(0, $this->pool_size - 1) as $i)
            $this->make_conn();
    }

    private function make_conn() {
        // Keep trying to make a new connection, stopping after we've
        // tried every server twice
        $err = "";
        foreach (range(1, count($this->servers) * 2) as $i)
        {
            try {
                $this->list_position = ($this->list_position + 1) % count($this->servers);
                $new_conn = new ConnectionWrapper($this->keyspace, $this->servers[$this->list_position],
                    $this->credentials, $this->framed_transport, $this->send_timeout, $this->recv_timeout);
                array_push($this->queue, $new_conn);
                $this->stats['created'] += 1;
                return;
            } catch (\TException $e) {
                $h = $this->servers[$this->list_position];
                $err = (string)$e;
                //error_log("Error connecting to $h: $err", 0);
                $this->stats['failed'] += 1;
            }
        }
        throw new NoServerAvailable("An attempt was made to connect to every server twice, but " .
                                    "all attempts failed. The last error was: $err");
    }

    public function get() {
        return array_shift($this->queue);
    }

    public function return_connection($connection) {
        if ($connection->op_count >= $this->recycle) {
            $this->stats['recycled'] += 1;
            $connection->close();
            $this->make_conn();
            $connection = $this->get();
        }
        array_push($this->queue, $connection);
    }

    public function describe_keyspace() {
        if (NULL === $this->keyspace_description) {
            $this->keyspace_description = $this->call("describe_keyspace", $this->keyspace);
        }

        return $this->keyspace_description;
    }

    public function dispose() {
        foreach($this->queue as $conn)
            $conn->close();
    }

    public function close() {
        $this->dispose();
    }

    public function stats() {
        return $this->stats;
    }

    public function call() {
        $args = func_get_args(); // Get all of the args passed to this function
        $f = array_shift($args); // pull the function from the beginning

        $retry_count = 0;
        if ($this->max_retries == -1)
            $tries =  self::MAX_RETRIES;
        elseif ($this->max_retries == 0)
            $tries = 1;
        else
            $tries = $this->max_retries + 1;

        foreach (range(1, $tries) as $retry_count) {
            $conn = $this->get();

            $conn->op_count += 1;
            try {
                $resp = call_user_func_array(array($conn->client, $f), $args);
                $this->return_connection($conn);
                return $resp;
            } catch (\cassandra_TimedOutException $toe) {
                $last_err = $toe;
                $this->handle_conn_failure($conn, $f, $toe, $retry_count);
            } catch (\cassandra_UnavailableException $ue) {
                $last_err = $ue;
                $this->handle_conn_failure($conn, $f, $ue, $retry_count);
            } catch (\TTransportException $tte) {
                $last_err = $tte;
                $this->handle_conn_failure($conn, $f, $tte, $retry_count);
            }
        }
        throw new MaxRetriesException("An attempt to execute $f failed $tries times.".
                                      " The last error was " . (string)$last_err);
    }

    private function handle_conn_failure($conn, $f, $exc, $retry_count) {
        $err = (string)$exc;
        error_log("Error performing $f on $conn->server: $err", 0);
        $conn->close();
        $this->stats['failed'] += 1;
        usleep(self::BASE_BACKOFF * pow(2, $retry_count) * self::MICROS);
        $this->make_conn();
    }

}