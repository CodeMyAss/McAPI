<?php

require_once __DIR__ . '/enum/McAPIVersion.class.php';
require_once __DIR__ . '/enum/McAPIResult.class.php';
require_once __DIR__ . '/enum/McLatencyAction.class.php';

require_once __DIR__ . '/util/McLatency.class.php';

class McAPIPing {

    private $ip;
    private $port;
    private $timeout;
    private $_socket;
    private $_data;
    private $_latency;
    private $result = array(
        'result' => null,
        'hostname' => null,
        'software' => [
            'name'      => null,
            'version'   => 0.0
        ],
        'protocol' => 0.0,
        'players' => [
            'max' => 0,
            'online' => 0
        ],
        'list' => [
            'motd' => null,
            'motdRaw' => null,
            'favicon' => null,
            'ping' => -1
        ]
    );

    public function __construct($ip, $port, $timeout = 2) {
        $this->ip = (substr_count($ip, '.') != 4 ? $ip : gethostbyaddr($ip));
        $this->port = $port;
        $this->timeout = $timeout;
        $this->_latency = new McLatency();

        if ($this->connect() === false) {
            return $this->setValue('result', McAPIResult::CANT_CONNECT);
        }
    }

    public function fetch($version) {

        switch ($version) {

            //1.7 and 1.8
            case McVersion::ONEDOTEIGHT:
            case McVersion::ONEDOTSEVEN:

                $this->_latency->executeAction(McLatencyAction::START);

                //say hello to the server
                $handshake = pack('cccca*', hexdec(strlen($this->ip)), 0, 0x04, strlen($this->ip), $this->ip) . pack('nc', $this->port, 0x01);
                $this->send($handshake, strlen($handshake), 0);
                $this->send("\x01\x00", 2, 0);


                $packetLength = $this->packetLength();

                if ($packetLength < 10) {
                    return $this->setValue('result', McAPIResult::PACKET_TO_SHORT);
                }

                $this->_latency->executeAction(McLatencyAction::STOP);
                $this->_latency->executeAction(McLatencyAction::CALCULATE);

                $this->read(1);
                $packetLength = $this->packetLength();
                $this->_data = $this->read($packetLength, PHP_NORMAL_READ);

                if (!($this->_data)) {
                    return $this->setValue('result', McAPIResult::FAILED_TO_READ_DATA);
                }

                $this->_data = json_decode($this->_data);

                //set values
                $this->setValue('hostname', $this->ip);

                $versionSplit = explode(' ', $this->_data->version->name);
                $this->setValue('software.name', (count($versionSplit) >= 2 ? $versionSplit[0] : null) );
                $this->setValue('software.version', (count($versionSplit) >= 2 ? $versionSplit[1] : $this->_data->version->name));

                $this->setValue('protocol', $this->_data->version->protocol);
                $this->setValue('players.max', $this->_data->players->max);
                $this->setValue('players.online', $this->_data->players->online);
                $this->setValue('list.motd', self::clearColour($this->_data->description));
                $this->setValue('list.motdRaw', $this->_data->description);
                $this->setValue('list.favicon', (isset($this->_data->favicon) ? $this->_data->favicon : null));
                $this->setValue('list.ping', $this->_latency->getLatency());
                $this->setValue('result', McAPIResult::SUCCESSFULLY_DONE);

                break; //1.7 and 1.8 

            case McVersion::ONEDOTSIX:

                $this->_latency->executeAction(McLatencyAction::START); //start

                $handle = fsockopen($this->ip, $this->port, $eerno, $errstr, 0.8);

                if ($handle) {
                    return $this->setValue('result', McAPIResult::CANT_CONNECT);
                }
                
                stream_set_timeout($handle, 2);

                fwrite($handle, "\xFE\x01");

                $data = fread($handle, 1024);
                
                if ($data != false && substr($data, 0, 1) == "\xFF") {
                    return $this->setValue('result', McAPIResult::FAILED_TO_READ_DATA);
                }

                $data = substr($data, 3);
                $data = mb_convert_encoding($data, 'auto', 'UCS-2');
                $data = explode("\x00", $data);
                fclose($handle);
                
                $this->_latency->executeAction(McLatencyAction::STOP);
                $this->_latency->executeAction(McLatencyAction::CALCULATE);

                $this->_data = $data;

                //setvalues
                $this->setValue('hostname', $this->ip);
                $this->setValue('software.name', explode(' ', $this->_data[2])[0]);
                $this->setValue('software.version', explode(' ', $this->_data[2])[1]);
                $this->setValue('players.max', $this->_data[4]);
                $this->setValue('players.online', $this->_data5);
                $this->setValue('list.motd', self::clearColour($this->_data[3]));
                $this->setValue('list.motdRaw', $this->_data[3]);
                $this->setValue('list.ping', $this->_latency->getLatency());
                $this->setValue('result', McAPIResult::SUCCESSFULLY_DONE);
                
                echo "<b>", print_r(explode(' ', $this->_data[2])), "</b>";

                break; //1.6
        }
    }

    public function test() {
        //header("Content-type: application/json");
        print_r(json_encode($this->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function send($buf, $length, $flags) {
        return socket_send($this->_socket, $buf, $length, $flags);
    }

    private function read($length, $type = PHP_BINARY_READ) {
        return socket_read($this->_socket, $length, $type);
    }

    private function receive($buf, $length, $flags = null) {
        return socket_recv($this->_socket, $buf, $length, $flags);
    }

    private function connect() {
        $this->_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        try {
            return socket_connect($this->_socket, $this->ip, $this->port);
        } catch (Exception $e) {
            return false;
        }
    }

    private function disconnect() {
        if (!(is_null($this->_socket))) {
            socket_close($this->_socket);
        }
    }

    private function packetLength($read = 1) {
        $a = 0;
        $b = 0;
        while (true) {
            $c = $this->read($read);

            if (!$c) {
                return 0;
            }
            $c = ord($c);
            $a |= ($c & 0x7F) << $b++ * 7;
            if ($b > 5) {
                return false;
            }
            if (($c & 0x80) != 128) {
                break;
            }
        }
        return $a;
    }

    private static function clearColour($motd) {
        $motd = preg_replace_callback('/\\\\u([0-9a-z]{3,4})/i', function ($matches) {
            return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
        }, $motd);
        $motd = preg_replace('/(§[0-9a-z])/i', '', $motd); //replace color codes
        $motd = preg_replace('/(\\\\n?\\\\r|\\\\n)/', '', $motd); //replace line breaks
        $motd = preg_replace('/(Â)?(«|»)?/', '', $motd); //replace more special chars
        $motd = preg_replace("/(\\\u[a-f0-9]{4})/", "iconv('UCS-4LE','UTF-8',pack('V', hexdec('U$1')))", $motd); //replace unicodes
        return (mb_detect_encoding($motd, 'UTF-8, ISO-8859-1') === 'UTF-8' ? $motd : utf8_decode($motd));
    }

    protected function setValue($path, $value) {

        if (substr_count($path, '.')) {
            $split = explode('.', $path);
            $this->result[$split[0]][$split[1]] = $value;
            return true;
        }

        $this->result[$path] = $value;
        return $value;
    }

}

?>