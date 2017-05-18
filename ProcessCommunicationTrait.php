<?php

namespace PHPPM;

use React\Stream\DuplexResourceStream;

/**
 * Little trait used in ProcessManager and ProcessSlave to have a simple json process communication.
 */
trait ProcessCommunicationTrait
{
    /**
     * Path to socket folder.
     *
     * @var string
     */
    protected $socketPath = '.ppm/run/';

    /**
     * Parses a received message. Redirects to the appropriate `command*` method.
     *
     * @param array $data
     * @param DuplexResourceStream $conn
     *
     * @throws \Exception when invalid 'cmd' in $data.
     */
    public function processMessage($data, DuplexResourceStream $conn)
    {
        $array = json_decode($data, true);

        $method = 'command' . ucfirst($array['cmd']);
        if (is_callable(array($this, $method))) {
            $this->$method($array, $conn);
        } else {
            throw new \Exception(sprintf('Command %s not found. Got %s', $method, $data));
        }
    }

    /**
     * Binds data-listener to $conn and waits for incoming commands.
     *
     * @param DuplexResourceStream $conn
     */
    protected function bindProcessMessage(DuplexResourceStream $conn)
    {
        $buffer = '';

        $conn->on(
            'data',
            \Closure::bind(
                function ($data) use ($conn, &$buffer) {
                    $buffer .= $data;

                    if (substr($buffer, -strlen(PHP_EOL)) === PHP_EOL) {
                        foreach (explode(PHP_EOL, $buffer) as $message) {
                            if ($message) {
                                $this->processMessage($message, $conn);
                            }
                        }

                        $buffer = '';
                    }
                },
                $this
            )
        );
    }

    /**
     * Sends a message through $conn.
     *
     * @param DuplexResourceStream $conn
     * @param string $command
     * @param array $message
     */
    protected function sendMessage(DuplexResourceStream $conn, $command, array $message = [])
    {
        $message['cmd'] = $command;
        $conn->write(json_encode($message) . PHP_EOL);
    }

    /**
     *
     * @param string $affix
     * @param bool $removeOld
     * @return string
     */
    protected function getSockFile($affix, $removeOld)
    {
        if (Utils::isWindows()) {
            //we have no unix domain sockets support
            return '127.0.0.1';
        }
        //since all commands set setcwd() we can make sure we are in the current application folder

        if ('/' === substr($this->socketPath, 0, 1)) {
            $run = $this->socketPath;
        } else {
            $run = getcwd() . '/' . $this->socketPath;
        }

        if ('/' !== substr($run, -1)) {
            $run .= '/';
        }

        if (!is_dir($run) && !mkdir($run, 0777, true)) {
            throw new \RuntimeException(sprintf('Could not create %s folder.', $run));
        }

        $sock = $run. $affix . '.sock';

        if ($removeOld && file_exists($sock)) {
            unlink($sock);
        }

        return 'unix://' . $sock;
    }

    /**
     * @param int $port
     *
     * @return string
     */
    protected function getNewSlaveSocket($port)
    {
        return $this->getSockFile($port, true);
    }

    /**
     * @param bool $removeOld
     * @return string
     */
    protected function getNewControllerHost($removeOld = true)
    {
        return $this->getSockFile('controller', $removeOld);
    }

    /**
     * @param string $socketPath
     */
    public function setSocketPath($socketPath)
    {
        $this->socketPath = $socketPath;
    }
}
