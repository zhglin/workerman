<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Connection;

use Workerman\Events\EventInterface;
use Workerman\Worker;
use Exception;

/**
 * TcpConnection.
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * Read buffer size.
     *
     * @var int
     */
    const READ_BUFFER_SIZE = 65535;

    /**
     * Status initial.
     *
     * @var int
     */
    const STATUS_INITIAL = 0;

    /**
     * Status connecting.
     *
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * Status connection established.
     *
     * @var int
     */
    const STATUS_ESTABLISHED = 2;

    /**
     * Status closing.
     *
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * Status closed.
     *
     * @var int
     */
    const STATUS_CLOSED = 8;

    /**
     * Emitted when data is received. 有请求进来时候的回调
     *
     * @var callback
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet. 链接关闭时候的回调
     *
     * @var callback
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection. 产生错误的回调函数
     *
     * @var callback
     */
    public $onError = null;

    /**
     * Emitted when the send buffer becomes full. 发送缓冲区满的回调函数
     *
     * @var callback
     */
    public $onBufferFull = null;

    /**
     * Emitted when the send buffer becomes empty. 可写事件中一次发送所有发送缓冲区中的数据
     *
     * @var callback
     */
    public $onBufferDrain = null;

    /**
     * Application layer protocol. 使用的应用层协议
     * The format is like this Workerman\\Protocols\\Http.
     *
     * @var \Workerman\Protocols\ProtocolInterface
     */
    public $protocol = null;

    /**
     * Transport (tcp/udp/unix/ssl). 传输层协议
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * Which worker belong to. 归属的worker进程实例
     *
     * @var Worker
     */
    public $worker = null;

    /**
     * Bytes read. 当前连接读取的数据量总量
     * @var int
     */
    public $bytesRead = 0;

    /**
     * Bytes written. 已发送的数据量
     *
     * @var int
     */
    public $bytesWritten = 0;

    /**
     * Connection->id.
     *
     * @var int
     */
    public $id = 0;

    /**
     * A copy of $worker->id which used to clean up the connection in worker->connections
     *
     * @var int
     */
    protected $_id = 0;

    /**
     * Sets the maximum send buffer size for the current connection.
     * OnBufferFull callback will be emited When the send buffer is full.
     *
     * @var int
     */
    public $maxSendBufferSize = 1048576;

    /**
     * Default send buffer size.  默认最大的发送缓冲区大小
     *
     * @var int
     */
    public static $defaultMaxSendBufferSize = 1048576;

    /**
     * Maximum acceptable packet size. 最大报文长度
     *
     * @var int
     */
    public static $maxPackageSize = 10485760;

    /**
     * Id recorder. 连接计数器
     * @var int
     */
    protected static $_idRecorder = 1;

    /**
     * Socket
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * Send buffer. 发送缓冲区
     *
     * @var string
     */
    protected $_sendBuffer = '';

    /**
     * Receive buffer. 接收缓冲区
     * @var string
     */
    protected $_recvBuffer = '';

    /**
     * Current package length.
     *
     * @var int
     */
    protected $_currentPackageLength = 0;

    /**
     * Connection status. 链接状态
     *
     * @var int
     */
    protected $_status = self::STATUS_ESTABLISHED;

    /**
     * Remote address. 客户端ip地址端口号
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * Is paused. 是否暂停读取数据
     *
     * @var bool
     */
    protected $_isPaused = false;

    /**
     * SSL handshake completed or not.
     *
     * @var bool
     */
    protected $_sslHandshakeCompleted = false;

    /**
     * All connection instances. 所有已连接对象实例

     * @var array
     */
    public static $connections = array();

    /**
     * Status to string.
     *
     * @var array
     */
    public static $_statusToString = array(
        self::STATUS_INITIAL     => 'INITIAL',
        self::STATUS_CONNECTING  => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED', //accept之后默认的状态
        self::STATUS_CLOSING     => 'CLOSING', //主动调用close
        self::STATUS_CLOSED      => 'CLOSED', //主动调用destroy  baseWrite回调
    );


    /**
     * Adding support of custom functions within protocols
     *
     * @param string $name
     * @param array  $arguments
     */
    public function __call($name, $arguments) {
        // Try to emit custom function within protocol
        if (method_exists($this->protocol, $name)) {
            try {
                return call_user_func(array($this->protocol, $name), $this, $arguments);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
	} else {
	    trigger_error('Call to undefined method '.__CLASS__.'::'.$name.'()', E_USER_ERROR);
	}

    }

    /**
     * Construct.
     *
     * @param resource $socket
     * @param string   $remote_address
     */
    public function __construct($socket, $remote_address = '')
    {
        self::$statistics['connection_count']++;
        $this->id = $this->_id = self::$_idRecorder++;
        if(self::$_idRecorder === PHP_INT_MAX){
            self::$_idRecorder = 0;
        }
        $this->_socket = $socket;
        stream_set_blocking($this->_socket, 0);
        // Compatible with hhvm 关闭读缓冲
        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($this->_socket, 0);
        }
        //对此连接添加read事件
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->_remoteAddress    = $remote_address;
        static::$connections[$this->id] = $this;
    }

    /**
     * Get status. 链接状态
     *
     * @param bool $raw_output
     *
     * @return int
     */
    public function getStatus($raw_output = true)
    {
        if ($raw_output) {
            return $this->_status;
        }
        return self::$_statusToString[$this->_status];
    }

    /**
     * Sends data on the connection.  发送数据(服务器端要主动调用)
     *
     * @param string $send_buffer
     * @param bool  $raw
     * @return void|bool|null
     */
    public function send($send_buffer, $raw = false)
    {
        //是否已经被关闭 比如stop restart 主动调用close(); 被关闭的连接不能再发送数据
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return false;
        }

        // Try to call protocol::encode($send_buffer) before sending.
        // 发送之前是否需要应用层进行编码
        if (false === $raw && $this->protocol !== null) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }

        // ssl todo
        if ($this->_status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true)
        ) {
            if ($this->_sendBuffer) {
                if ($this->bufferIsFull()) {
                    self::$statistics['send_fail']++;
                    return false;
                }
            }
            $this->_sendBuffer .= $send_buffer;
            $this->checkBufferWillFull();
            return null;
        }


        // Attempt to send data directly.
        // 如果发送缓冲区没有数据 就直接发送 如果数据太大发送不完就把剩下的数据放入发送缓冲区
        // 第一次写入时_sendBuffer==''
        if ($this->_sendBuffer === '') {
            $len = @fwrite($this->_socket, $send_buffer, 8192);
            // send successful.
            if ($len === strlen($send_buffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            // Send only part of the data.
            if ($len > 0) {
                $this->_sendBuffer = substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                // Connection closed? 客户端异常关闭 或者 tcp发送缓冲区已满 写入不进去
                if (!is_resource($this->_socket) || feof($this->_socket)) {
                    self::$statistics['send_fail']++;
                    if ($this->onError) {
                        try {
                            call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'client closed');
                        } catch (\Exception $e) {
                            Worker::log($e);
                            exit(250);
                        } catch (\Error $e) {
                            Worker::log($e);
                            exit(250);
                        }
                    }
                    $this->destroy();
                    return false;
                }
                $this->_sendBuffer = $send_buffer;
            }
            //数据写不完 添加可写事件
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            // Check if the send buffer will be full.
            $this->checkBufferWillFull();
            return null;
        } else {
            if ($this->bufferIsFull()) {
                self::$statistics['send_fail']++;
                return false;
            }

            $this->_sendBuffer .= $send_buffer;
            // Check if the send buffer is full.
            $this->checkBufferWillFull();
        }
    }

    /**
     * Get remote IP. 客户端ip
     *
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return substr($this->_remoteAddress, 0, $pos);
        }
        return '';
    }

    /**
     * Get remote port. 客户端端口号
     *
     * @return int
     */
    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int)substr(strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * Get remote address. 客户端ip端口号
     *
     * @return string
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }

    /**
     * Get local IP.获取当前链接的服务器端ip
     *
     * @return string
     */
    public function getLocalIp()
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return substr($address, 0, $pos);
    }

    /**
     * Get local port.获取当前链接的服务器端端口号
     *
     * @return int
     */
    public function getLocalPort()
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)substr(strrchr($address, ':'), 1);
    }

    /**
     * Get local address.获取本地套接字名称
     *
     * @return string
     */
    public function getLocalAddress()
    {
        return (string)@stream_socket_get_name($this->_socket, false);
    }

    /**
     * Get send buffer queue size. 发送缓冲区长度
     *
     * @return integer
     */
    public function getSendBufferQueueSize()
    {
        return strlen($this->_sendBuffer);
    }

    /**
     * Get recv buffer queue size.接受缓冲区长度
     *
     * @return integer
     */
    public function getRecvBufferQueueSize()
    {
        return strlen($this->_recvBuffer);
    }

    /**
     * Is ipv4.
     *
     * return bool.
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     * Is ipv6.
     *
     * return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * Pauses the reading of data. That is onMessage will not be emitted. Useful to throttle back an upload. 暂停读取操作
     * 暂停读取数据   这就是onMessage不会被释放   有助于减少上传
     * @return void
     */
    public function pauseRecv()
    {
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        $this->_isPaused = true;
    }

    /**
     * Resumes reading after a call to pauseRecv. 恢复读取
     *
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->_isPaused === true) {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            $this->_isPaused = false;
            $this->baseRead($this->_socket, false);
        }
    }

    /**
     * Base read handler. 读取请求(事件模型回调)
     *
     * @param resource $socket
     * @param bool $check_eof
     * @return void
     */
    public function baseRead($socket, $check_eof = true)
    {
        // SSL handshake. todo
        if ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true) {
            $ret = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_SSLv2_SERVER |
                STREAM_CRYPTO_METHOD_SSLv3_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER);
            // Negotiation has failed.
            if(false === $ret) {
                if (!feof($socket)) {
                    echo "\nSSL Handshake fail. \nBuffer:".bin2hex(fread($socket, 8182))."\n";
                }
                return $this->destroy();
            } elseif(0 === $ret) {
                // There isn't enough data and should try again.
                return;
            }
            if (isset($this->onSslHandshake)) {
                try {
                    call_user_func($this->onSslHandshake, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            $this->_sslHandshakeCompleted = true;
            if ($this->_sendBuffer) {
                Worker::$globalEvent->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            }
            return;
        }

        $buffer = @fread($socket, self::READ_BUFFER_SIZE);

        // Check connection closed.
        // 客户端关闭连接
        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            $this->bytesRead += strlen($buffer);
            $this->_recvBuffer .= $buffer;
        }

        // If the application layer protocol has been set up.
        // 有应用层协议
        if ($this->protocol !== null) {
            $parser = $this->protocol;
            // while 处理可能出现的粘包情况 定长 间隔字符 报文长度
            while ($this->_recvBuffer !== '' && !$this->_isPaused) {
                // The current packet length is known. 报文中有长度字段 但是数据不足
                if ($this->_currentPackageLength) {
                    // Data is not enough for a package.
                    if ($this->_currentPackageLength > strlen($this->_recvBuffer)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                    // The packet length is unknown.
                    // 接收到的数据不足一个报文
                    if ($this->_currentPackageLength === 0) {
                        break;
                    } elseif ($this->_currentPackageLength > 0 && $this->_currentPackageLength <= self::$maxPackageSize) {
                        // Data is not enough for a package. 可能报文里面有长度字段并且大于接收缓冲区
                        if ($this->_currentPackageLength > strlen($this->_recvBuffer)) {
                            break;
                        }
                    } // Wrong package.
                    else {
                        echo 'error package. package_length=' . var_export($this->_currentPackageLength, true);
                        $this->destroy();
                        return;
                    }
                }

                // The data is enough for a packet.
                self::$statistics['total_request']++;
                // The current packet length is equal to the length of the buffer.
                // 接收缓冲区中只有一个报文
                if (strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                    $one_request_buffer = $this->_recvBuffer;
                    $this->_recvBuffer  = '';
                } else {
                    // Get a full package from the buffer.
                    // 如果接受缓冲区中数据 大于一个请求长度 从缓冲区只取出部分数据
                    $one_request_buffer = substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                    // Remove the current package from the receive buffer.
                    $this->_recvBuffer = substr($this->_recvBuffer, $this->_currentPackageLength);
                }
                // Reset the current packet length to 0.
                // 重置请求长度为0
                $this->_currentPackageLength = 0;
                if (!$this->onMessage) {
                    continue;
                }
                try {
                    // Decode request buffer before Emitting onMessage callback. 回调
                    call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            return;
        }

        if ($this->_recvBuffer === '' || $this->_isPaused) {
            return;
        }

        // Applications protocol is not set.
        // 没有应用层协议 自己处理拆包 封包
        self::$statistics['total_request']++;
        if (!$this->onMessage) {
            $this->_recvBuffer = '';
            return;
        }
        try {
            call_user_func($this->onMessage, $this, $this->_recvBuffer);
        } catch (\Exception $e) {
            Worker::log($e);
            exit(250);
        } catch (\Error $e) {
            Worker::log($e);
            exit(250);
        }
        // Clean receive buffer.
        $this->_recvBuffer = '';
    }

    /**
     * Base write handler. 可写事件回调
     * 写入数据长度正好与_sendBuffer长度一样 去掉可写事件 调用onBufferDrain 如果_status=STATUS_CLOSING就关闭链接
     * >0未发送完_sendBuffer中的数据 去掉_sendBuffer中的数据
     * <0出错 客户端崩溃 发送不了关闭报文
     * @return void|bool
     */
    public function baseWrite()
    {
        $len = @fwrite($this->_socket, $this->_sendBuffer, 8192);
        if ($len === strlen($this->_sendBuffer)) {
            $this->bytesWritten += $len;
            Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
            $this->_sendBuffer = '';
            // Try to emit onBufferDrain callback when the send buffer becomes empty. 
            if ($this->onBufferDrain) {
                try {
                    call_user_func($this->onBufferDrain, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            return true;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->_sendBuffer = substr($this->_sendBuffer, $len);
        } else {
            self::$statistics['send_fail']++;
            $this->destroy();
        }
    }

    /**
     * This method pulls all the data out of a readable stream, and writes it to the supplied destination.
     *
     * @param TcpConnection $dest
     * @return void
     */
    public function pipe($dest)
    {
        $source              = $this;
        $this->onMessage     = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose       = function ($source) use ($dest) {
            $dest->destroy();
        };
        $dest->onBufferFull  = function ($dest) use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function ($dest) use ($source) {
            $source->resumeRecv();
        };
    }

    /**
     * Remove $length of data from receive buffer. 从接受缓冲区截取一定的数据长度
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer($length)
    {
        $this->_recvBuffer = substr($this->_recvBuffer, $length);
    }

    /**
     * Close connection. 关闭链接 如果发送缓冲区有还有数据 就发送完数据再关闭
     *
     * @param mixed $data
     * @param bool $raw
     * @return void
     */
    public function close($data = null, $raw = false)
    {
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return;
        } else {
            //应用层发送关闭请求
            if ($data !== null) {
                $this->send($data, $raw);
            }
            $this->_status = self::STATUS_CLOSING;
        }
        if ($this->_sendBuffer === '') {
            $this->destroy();
        }
    }

    /**
     * Get the real socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->_socket;
    }

    /**
     * Check whether the send buffer will be full. 加上此次发送的数据 发送缓冲区是否已满 onBufferFull
     *
     * @return void
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= strlen($this->_sendBuffer)) {
            if ($this->onBufferFull) {
                try {
                    call_user_func($this->onBufferFull, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        }
    }

    /**
     * Whether send buffer is full. 发送缓冲区已经满了 不能发送当前数据 onError
     *
     * @return bool
     */
    protected function bufferIsFull()
    {
        // Buffer has been marked as full but still has data to send then the packet is discarded.
        if ($this->maxSendBufferSize <= strlen($this->_sendBuffer)) {
            if ($this->onError) {
                try {
                    call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'send buffer full and drop package');
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Destroy connection. 关闭连接(丢弃发送缓冲区数据直接关闭)
     *
     * @return void
     */
    public function destroy()
    {
        // Avoid repeated calls. 可能会被restart stop 信号中断 导致重复进入 todo
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }
        // Remove event listener.
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
        // Close socket.
        @fclose($this->_socket);
        // Remove from worker->connections.
        // 从worker进程中去掉保存的连接
        if ($this->worker) {
            unset($this->worker->connections[$this->_id]);
        }
        unset(static::$connections[$this->_id]);
        $this->_status = self::STATUS_CLOSED;
        // Try to emit onClose callback.
        //下面的回调只会被执行一次 正常的destroy 或者 信号触发 todo?
        if ($this->onClose) {
            try {
                call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        // Try to emit protocol::onClose
        if (method_exists($this->protocol, 'onClose')) {
            try {
                call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        if ($this->_status === self::STATUS_CLOSED) {
            // Cleaning up the callback to avoid memory leaks.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
        }
    }

    /**
     * Destruct. 关闭链接时候记录剩余的连接数
     * 相当于分页 关闭了一页就记录一次log
     * 如果当前worker进程中的连接关闭完了就退出 重启一个worker
     *
     * @return void
     */
    public function __destruct()
    {
        static $mod;
        self::$statistics['connection_count']--;
        if (Worker::getGracefulStop()) {
            if (!isset($mod)) {
                $mod = ceil((self::$statistics['connection_count'] + 1) / 3);
            }

            if (0 === self::$statistics['connection_count'] % $mod) {
                Worker::log('worker[' . posix_getpid() . '] remains ' . self::$statistics['connection_count'] . ' connection(s)');
            }

            if(0 === self::$statistics['connection_count']) {
                Worker::$globalEvent->destroy();
                exit(0);
            }
        }
    }
}
