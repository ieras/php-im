<?php
//启动服务
(new ws())->run();

/**
 * 原生PHP的websocket服务
 */
class ws
{
    protected string $address = '0.0.0.0';
    protected int $port = 3233;
    protected Socket $_socket;
    protected array $users;//在线用户数

    /**
     * 构造函数，定义listen IP和Port
     * @param string $address
     * @param int $port
     */
    public function __construct(string $address = '', int $port = 0)
    {
        !empty($address) && $this->address = $address;
        $port > 0 && $this->port = $port;
    }

    /**
     * 处理业务
     * @param array $clients 所有在线用户数
     * @param string $key 当前用户的key 通过 $clients[$key] 可以拿到当前用户的 ws实例
     * @param string $msg 当前用户发来的消息
     * @return void
     */
    public function logic(array $clients, string $key, string $msg): void
    {
        echo "在线人数【" . count($clients) . "】|老用户[key:{$key}|请求信息:$msg]\n";
        $message = json_decode($msg, true);
        if (isset($message['event'])) {
            $resp = match ($message['event']) {
                "ping" => ['to' => $clients[$key], 'message' => ''],
                "join" => $this->eventJoin($message, $key, $clients),
                "send" => $this->eventSend($message, $key, $clients),
                default => $this->eventJson($message, $key, $clients),
            };
        } else {
            $resp = $this->eventStr($msg, $key, $clients);
        }
        if (empty($resp)) return;

        // 处理公共消息
        $to = $resp['to'] ?? false;
        $response = $resp['message'];
        switch (true) {
            case is_array($to) :
                foreach ($to as $client) {
                    $this->send($client, $this->stripTags($response));
                }
                break;
            case $to instanceof Socket :
                $this->send($to, $this->stripTags($response));
                break;
            case $to == 'all' :
                foreach ($this->users as $client) {
                    $this->send($client, $this->stripTags($response));
                }
                break;
            default :
                break;
        }
    }

    public function stripTags($str): string
    {
        $allowed_tags = ['span', 'p', 'strong', 'em', 'br', 'ul', 'ol', 'li'];
        return strip_tags($str, '<' . implode('><', $allowed_tags) . '>');
    }

    public function eventJoin($message, $key, $clients): bool
    {
        $nickname = $message['nickname'];
        $this->users[$nickname] = $clients[$key];
        foreach ($this->users as $nick => $client) {
            if ($nick == $nickname) {
                $pre = "[<span class='name' style='color: purple'>我</span>]";
            } else {
                $pre = "[<span class='name' style='color: blue'>{$message['nickname']}</span>]";
            }
            $response = "{$pre}加入了聊天,当前在线:[" . count($this->users) . "]";
            $this->send($client, $this->stripTags($response));
        }
        return false;
    }

    public function eventSend($message, $key, $clients): bool
    {
        $nickname = $message['nickname'];
        foreach ($this->users as $nick => $client) {
            if ($nick == $nickname) {
                $pre = "[<span class='name' style='color: purple'>我</span>]";
            } else {
                $pre = "[<span class='name' style='color: blue'>{$message['nickname']}</span>]";
            }
            $response = "{$pre}：{$message['msg']}";
            $this->send($client, $this->stripTags($response));
        }
        return false;
    }

    public function eventJson($message, $key, $clients): array
    {
        if (isset($message['final_from_name']) && isset($message['msg']) && !is_array($message['msg'])) {
            //$this->eventJoin(['event' => 'join', 'nickname' => $message['final_from_name']], $key, $clients);
            $nickname = strip_tags($message['final_from_name']);
            $msg = strip_tags($message['msg']);
            $msg = "[<span style='background-color: green'>微信</span>][<span style='color: blue'>$nickname</span>]：{$msg}";
            return ['to' => "all", "message" => $msg];
        } else {
            return ['to' => $clients[$key], "message" => json_encode($message, JSON_UNESCAPED_UNICODE)];
        }
    }

    public function eventStr($message, $key, $clients): array
    {
        return ['to' => "all", "message" => $message];
    }

    /**
     * 创建socket服务 并listen IP和Port
     * @throws Exception
     */
    public function service(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if ($socket === false) {
            $error_code = socket_last_error();
            throw new Exception("Couldn't create socket: [$error_code] " . socket_strerror($error_code) . " \n");
        }
        socket_bind($socket, $this->address, $this->port);
        socket_listen($socket, $this->port);
        echo "listen on ws://$this->address:$this->port ... \n";
        $this->_socket = $socket;
    }

    /**
     * 堵塞方式(死循环)启动 websocket 服务，等待客户端连入。
     * @return void
     */
    public function run(): void
    {
        try {
            $this->service();
            $clients['ws'] = $this->_socket;
            while (true) {
                $changes = $clients;
                $write = NULL;
                $except = NULL;
                socket_select($changes, $write, $except, NULL);
                foreach ($changes as $key => $_socket) {
                    if ($this->_socket == $_socket) { //判断是不是新接入的socket
                        if (($newClient = socket_accept($_socket)) === false) {
                            throw new Exception('failed to accept socket: ' . socket_strerror(socket_last_error()) . "\n");
                        }
                        $line = trim(socket_read($newClient, 32768));//最大读取32M数据
                        $headers = $this->handshakeAndGetHeaders($newClient, $line);
                        if (!$headers) continue;
                        socket_getpeername($newClient, $ip);//获取client ip
                        $clients[$headers['Sec-WebSocket-Key']] = $newClient;
                        echo "在线人数【" . count($clients) . "】|新用户[ip:$ip|swk:{$headers['Sec-WebSocket-Key']}]\n";
                    } else {
                        $len = socket_recv($_socket, $buffer, 32768, 0);
                        $msg = $this->message($buffer);
                        if ($len < 7 || ($len == 8 && strlen($msg) == 2)) {
                            if ($key != 'ws') unset($clients[$key]);
                            continue;
                        }
                        $this->logic($clients, $key, $msg);//处理消息
                    }
                }
            }
        } catch (Exception $e) {
            echo "error:" . $e->getMessage();
        }
    }

    /**
     * 握手并获取客户端的头信息
     * @param Socket $newClient socket客户端实例
     * @param string $info socket_read 的数据
     * @return array|false 接收到的信息
     */
    public function handshakeAndGetHeaders(Socket $newClient, string $info): false|array
    {

        $lines = preg_split("/\r\n/", $info);
        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }
        if (!isset($headers['Sec-WebSocket-Key'])) return false;

        $sec_key = $headers['Sec-WebSocket-Key'];
        $sec_accept = base64_encode(sha1($sec_key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "Connection: Upgrade\r\n" .
            "Date: " . gmdate("D, d M Y H:i:s") . " GMT\r\n" .
            "Server: PHP " . phpversion() . "/websocketServer\r\n" .
            "WebSocket-Origin: $this->address\r\n" .
            "WebSocket-Location: ws://{$headers['Host']}\r\n" .
            "Sec-WebSocket-Accept:$sec_accept\r\n\r\n";
        if (socket_write($newClient, $upgrade, strlen($upgrade))) return $headers;

        return false;
    }

    /**
     * 解析接收的数据
     * @param $buffer
     * @return string
     */
    public function message($buffer): string
    {
        $decoded = "";
        if (empty($buffer)) return $decoded;
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else {
            if ($len === 127) {
                $masks = substr($buffer, 10, 4);
                $data = substr($buffer, 14);
            } else {
                $masks = substr($buffer, 2, 4);
                $data = substr($buffer, 6);
            }
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }

    /**
     * 发送数据
     * @param Socket $client 新接入的socket
     * @param string $msg 要发送的数据
     * @return void
     */
    public function send(Socket $client, string $msg): void
    {
        $msg = $this->dataFrame($msg);
        $lens = strlen($msg);//总长度
        $finish = 0;//已经写入完成的长度
        while ($finish < $lens) {
            $bytes = @socket_write($client, substr($msg, $finish));
            if ($bytes === false) {
                echo "error：Failed to write to socket.\r\n";
                return;
            }
            $finish += $bytes;
        }
    }

    /**
     * 构建相应的 WebSocket 数据帧
     * @param $msg
     * @return string
     */
    public function dataFrame($msg): string
    {
        $ns = "";
        foreach (str_split($msg, 125) as $o) {
            $ns .= "\x81" . chr(strlen($o)) . $o;
        }
        return empty($ns) ? "\x81" . chr(strlen($msg)) . $msg : $ns;
    }
}
