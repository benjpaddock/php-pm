<?php

namespace PHPPM;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Stream\DuplexResourceStream;
use React\Stream\DuplexStreamInterface;

class Client
{
    use ProcessCommunicationTrait;

    /**
     * @var int
     */
    protected $controllerPort;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var DuplexStreamInterface
     */
    protected $connection;

    public function __construct($controllerPort = ProcessManager::CONTROLLER_PORT)
    {
        $this->controllerPort = $controllerPort;
        $this->loop = Factory::create();
    }

    /**
     * @return DuplexStreamInterface
     */
    protected function getConnection()
    {
        if ($this->connection) {
            $this->connection->close();
            unset($this->connection);
        }

        $socketUri = $this->getControllerSocket();
        $client = false;
        for ($attempts = 10; $attempts; --$attempts, usleep(mt_rand(500, 1000))) {
            $client = @stream_socket_client($socketUri, $errno, $errstr);
            if ($client) {
                break;
            }
        }
        if (!$client) {
            $message = "Could not bind to $socketUri. Error: [$errno] $errstr";
            throw new \RuntimeException($message, $errno);
        }

        $this->connection = new DuplexResourceStream($client, $this->loop);
        return $this->connection;
    }

    protected function request($command, $options, $callback)
    {
        $data['cmd'] = $command;
        $data['options'] = $options;
        $connection = $this->getConnection();

        $result = '';
        $connection->on('data', function($data) use (&$result) {
            $result .= $data;
        });

        $connection->on('close', function() use ($callback, &$result) {
            $callback($result);
        });

        $connection->write(json_encode($data) . PHP_EOL);
    }

    public function getStatus(callable $callback)
    {
        $this->request('status', [], function($result) use ($callback) {
            $callback(json_decode($result, true));
        });
        $this->loop->run();
    }

    /**
     * @return string
     */
    protected function getControllerSocket()
    {
        $host = $this->getNewControllerHost(false);
        $port = $this->controllerPort;
        $localSocket = '';
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $localSocket = 'tcp://' . $host . ':' . $port;
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // enclose IPv6 addresses in square brackets before appending port
            $localSocket = 'tcp://[' . $host . ']:' . $port;
        } elseif (preg_match('#^unix://#', $host)) {
            $localSocket = $host;
        } else {
            throw new \UnexpectedValueException(
                '"' . $host . '" does not match to a set of supported transports. ' .
                'Supported transports are: IPv4, IPv6 and unix:// .'
                , 1433253311);
        }
        return $localSocket;
    }

    public function stopProcessManager(callable $callback)
    {
        $this->request('stop', [], function($result) use ($callback) {
            $callback(json_decode($result, true));
        });
        $this->loop->run();
    }
}
