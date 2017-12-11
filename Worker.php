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
namespace Workerman;
require_once __DIR__ . '/Lib/Constants.php';

use Workerman\Events\EventInterface;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\UdpConnection;
use Workerman\Lib\Timer;
use Workerman\Events\Select;
use Exception;

/**
 * Worker class
 * A container for listening ports
 */
class Worker
{
    /**
     * Version.
     *
     * @var string
     */
    const VERSION = '3.5.3';

    /**
     * Status starting.
     *
     * @var int
     */
    const STATUS_STARTING = 1;

    /**
     * Status running.
     *
     * @var int
     */
    const STATUS_RUNNING = 2;

    /**
     * Status shutdown.
     *
     * @var int
     */
    const STATUS_SHUTDOWN = 4;

    /**
     * Status reloading.
     *
     * @var int
     */
    const STATUS_RELOADING = 8;

    /**
     * After sending the restart command to the child process KILL_WORKER_TIMER_TIME seconds,
     * if the process is still living then forced to kill.
     *
     * @var int
     */
    const KILL_WORKER_TIMER_TIME = 2;

    /**
     * Default backlog. Backlog is the maximum length of the queue of pending connections.
     *
     * @var int
     */
    const DEFAULT_BACKLOG = 102400;
    /**
     * Max udp package size.
     *
     * @var int
     */
    const MAX_UDP_PACKAGE_SIZE = 65535;

    /**
     * Worker id.
     *
     * @var int
     */
    public $id = 0;

    /**
     * Name of the worker processes.
     *
     * @var string
     */
    public $name = 'none';

    /**
     * Number of worker processes.worker进程数
     *
     * @var int
     */
    public $count = 1;

    /**
     * Unix user of processes, needs appropriate privileges (usually root).
     * worker进程的用户
     * @var string
     */
    public $user = '';

    /**
     * Unix group of processes, needs appropriate privileges (usually root).
     * worker进程的用户组
     * @var string
     */
    public $group = '';

    /**
     * reloadable. worker进程能否被重启
     *
     * @var bool
     */
    public $reloadable = true;

    /**
     * reuse port. 是否多个进程或者线程绑定到同一端口
     * SO_REUSEPORT支持多个进程或者线程绑定到同一端口，提高服务器程序的性能，解决的问题：
     *  允许多个套接字 bind()/listen() 同一个TCP/UDP端口
     *      每一个线程拥有自己的服务器套接字
     *      在服务器套接字上没有了锁的竞争
     *  内核层面实现负载均衡
     *  安全层面，监听同一个端口的套接字只能位于同一个用户下面
     * @var bool
     */
    public $reusePort = false;

    /**
     * Emitted when worker processes start. worker进程启动时的回调函数
     *
     * @var callback
     */
    public $onWorkerStart = null;

    /**
     * Emitted when a socket connection is successfully established. 有链接建立时候的回调(accept)
     *
     * @var callback
     */
    public $onConnect = null;

    /**
     * Emitted when data is received. 链接上有数据进来的时候的回调函数
     *
     * @var callback
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callback
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var callback
     */
    public $onError = null;

    /**
     * Emitted when the send buffer becomes full.
     *
     * @var callback
     */
    public $onBufferFull = null;

    /**
     * Emitted when the send buffer becomes empty.
     *
     * @var callback
     */
    public $onBufferDrain = null;

    /**
     * Emitted when worker processes stoped. worker进程正常退出的回调函数
     *
     * @var callback
     */
    public $onWorkerStop = null;

    /**
     * Emitted when worker processes get reload signal. worker进程重启的回调函数
     *
     * @var callback
     */
    public $onWorkerReload = null;

    /**
     * Transport layer protocol. 传输层协议
     * @var string
     */
    public $transport = 'tcp';

    /**
     * Store all connections of clients. worker进程中的连接实例
     * worker进程才会使用
     * @var array
     */
    public $connections = array();

    /**
     * Application layer protocol. 应用层协议
     *
     * @var string
     */
    public $protocol = null;

    /**
     * Root path for autoload.
     *
     * @var string
     */
    protected $_autoloadRootPath = '';

    /**
     * Pause accept new connections or not.是否在listen链接上添加读事件(进行accept处理链接)
     *
     * @var string
     */
    protected $_pauseAccept = true;

    /**
     * Daemonize.
     * 是否以守护进程的方式执行
     * @var bool
     */
    public static $daemonize = false;

    /**
     * Stdout file.
     *
     * @var string
     */
    public static $stdoutFile = '/dev/null';

    /**
     * The file to store master process PID. 进程文件 记录pid
     *
     * @var string
     */
    public static $pidFile = '';

    /**
     * Log file. 日志文件
     * @var mixed
     */
    public static $logFile = '';

    /**
     * Global event loop. 保存的event模型实例
     * @var Events\EventInterface
     */
    public static $globalEvent = null;

    /**
     * Emitted when the master process get reload signal. 重启(reload)的回调函数
     *
     * @var callback
     */
    public static $onMasterReload = null;

    /**
     * Emitted when the master process terminated. master进程退出的回调函数
     *
     * @var callback
     */
    public static $onMasterStop = null;

    /**
     * EventLoopClass 使用的事件模型类名(可配置)
     *
     * @var string
     */
    public static $eventLoopClass = '';

    /**
     * The PID of master process. master进程的pid
     *
     * @var int
     */
    protected static $_masterPid = 0;

    /**
     * Listening socket. 保存的监听链接实例
     *
     * @var resource
     */
    protected $_mainSocket = null;

    /**
     * Socket name. The format is like this http://0.0.0.0:80 .
     * 协议(应用层 || 传输层) ip地址 端口号
     * @var string
     */
    protected $_socketName = '';

    /**
     * Context of socket.  stream_context_create创建的资源上下文
     * @var resource
      */
    protected $_context = null;

    /**
     * All worker instances. master进程下保存所有的master进程实例  worker进程保存worker进程实例
     *
     * @var array
     */
    protected static $_workers = array();

    /**
     * All worker porcesses pid. 所有worker(master)进程下的pid [worker_id=>[pid=>pid, pid=>pid, ..], ..]  worker进程中为空
     * The format is like this
     * @var array
     */
    protected static $_pidMap = array();

    /**
     * All worker processes waiting for restart. 需要重启的worker进程(所有的 多个master)
     * The format is like this [pid=>pid, pid=>pid].
     *
     * @var array
     */
    protected static $_pidsToRestart = array();

    /**
     * Mapping from PID to worker process ID. worker进程序列 worker进程退出时依据它来重新生成 [worker_id=>[0=>$pid, 1=>$pid, ..], ..].
     * The format is like this
     *
     * @var array
     */
    protected static $_idMap = array();

    /**
     * Current status.
     *
     * @var int
     */
    protected static $_status = self::STATUS_STARTING;

    /**
     * Maximum length of the worker names.
     *
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;

    /**
     * Maximum length of the socket names.
     *
     * @var int
     */
    protected static $_maxSocketNameLength = 12;

    /**
     * Maximum length of the process user names.
     *
     * @var int
     */
    protected static $_maxUserNameLength = 12;

    /**
     * The file to store status info of current worker process.记录运行状态信息的文件
     * 进程通信
     * @var string
     */
    protected static $_statisticsFile = '';

    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';

    /**
     * OS.
     *
     * @var string
     */
    protected static $_OS = 'linux';

    /**
     * Processes for windows.
     *
     * @var array
     */
    protected static $_processForWindows = array();

    /**
     * Status info of current worker process.
     *
     * @var array
     */
    protected static $_globalStatistics = array(
        'start_timestamp'  => 0,
        'worker_exit_info' => array()
    );

    /**
     * Available event loops.
     * 可用的event扩展
     * @var array
     */
    protected static $_availableEventLoops = array(
        'libevent' => '\Workerman\Events\Libevent',
        'event'    => '\Workerman\Events\Event'
    );

    /**
     * PHP built-in protocols.
     * 可用的传输层协议
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'tcp'
    );

    /**
     * Graceful stop or not. 是否立即退出
     * @var string
     */
    protected static $_gracefulStop = false;

    /**
     * Run all worker instances. 启动所有worker
     *
     * @return void
     */
    public static function runAll()
    {
        static::checkSapiEnv();     //验证环境 windows || cli
        static::init();             //初始化系统信息  pid文件 log文件 status文件  $_idMap数组 定时器handle
        static::parseCommand();     //解析验证命令行参数  并发送相应信号
        static::daemonize();        //master进程作为守护进程执行
        static::initWorkers();      //初始化master信息 name $_maxWorkerNameLength  $_maxSocketNameLength  user
        static::installSignal();    //安装信号处理函数
        static::saveMasterPid();    //把master的pid写入pid文件
        static::displayUI();        //打印启动信息
        static::forkWorkers();      //创建并执行worker进程 worker进程阻塞在这里 在这里退出不会执行下面的函数
        static::resetStd();         //父进程重置标准输入输出
        static::monitorWorkers();   //worker进程监控
    }

    /**
     * Check sapi. 检查操作系统 以及执行方式
     *
     * @return void
     */
    protected static function checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != "cli") {
            exit("only run in command line mode \n");
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            self::$_OS = 'windows';
        }
    }

    /**
     * Init. 初始化系统信息 创建pid文件 log文件 状态文件 worker下的pidMap 安装定时器处理函数
     * @return void
     */
    protected static function init()
    {
        // Start file.
        $backtrace        = debug_backtrace();
        static::$_startFile = $backtrace[count($backtrace) - 1]['file'];

        $unique_prefix = str_replace('/', '_', static::$_startFile);

        // Pid file.
        if (empty(static::$pidFile)) {
            static::$pidFile = __DIR__ . "/../$unique_prefix.pid";
        }

        // Log file.
        if (empty(static::$logFile)) {
            static::$logFile = __DIR__ . '/../workerman.log';
        }
        $log_file = (string)static::$logFile;
        if (!is_file($log_file)) {
            touch($log_file);
            chmod($log_file, 0622);
        }

        // State.
        static::$_status = static::STATUS_STARTING;

        // For statistics. 临时目录 /tmp
        static::$_globalStatistics['start_timestamp'] = time();
        static::$_statisticsFile                      = sys_get_temp_dir() . "/$unique_prefix.status";

        // Process title. ps命令显示的进程title
        static::setProcessTitle('WorkerMan: master process  start_file=' . static::$_startFile);

        // Init data for worker id.
        static::initId();

        // Timer init.
        Timer::init();
    }

    /**
     * Init All worker instances.初始化master 设置master的name $_maxWorkerNameLength  $_maxSocketNameLength 验证user是否合法 listen
     * @return void
     */
    protected static function initWorkers()
    {
        if (static::$_OS !== 'linux') {
            return;
        }
        foreach (static::$_workers as $worker) {
            // Worker name.
            if (empty($worker->name)) {
                $worker->name = 'none';
            }

            // Get maximum length of worker name.
            $worker_name_length = strlen($worker->name);
            if (static::$_maxWorkerNameLength < $worker_name_length) {
                static::$_maxWorkerNameLength = $worker_name_length;
            }

            // Get maximum length of socket name.
            $socket_name_length = strlen($worker->getSocketName());
            if (static::$_maxSocketNameLength < $socket_name_length) {
                static::$_maxSocketNameLength = $socket_name_length;
            }

            // Get unix user of the worker process.
            if (static::$_OS === 'linux') {
                if (empty($worker->user)) {
                    $worker->user = static::getCurrentUser();
                } else {
                    if (posix_getuid() !== 0 && $worker->user != static::getCurrentUser()) {
                        static::log('Warning: You must have the root privileges to change uid and gid.');
                    }
                }
            }

            // Get maximum length of unix user name.
            $user_name_length = strlen($worker->user);
            if (static::$_maxUserNameLength < $user_name_length) {
                static::$_maxUserNameLength = $user_name_length;
            }

            // Listen. reusePort初值false 只在master进行listen 改为true就只在worker里进行listen
            if (!$worker->reusePort) {
                $worker->listen();
            }
        }
    }

    /**
     * Get all worker instances. 获取所有的worker信息
     *
     * @return array
     */
    public static function getAllWorkers()
    {
        return static::$_workers;
    }

    /**
     * Get global event-loop instance.
     *
     * @return EventInterface
     */
    public static function getEventLoop()
    {
        return static::$globalEvent;
    }

    /**
     * Init idMap.初始化$_idMap数组 [worker_id=>[0=>0, 1=>0, ..], ..]. 占位
     * return void
     */
    protected static function initId()
    {
        foreach (static::$_workers as $worker_id => $worker) {
            $new_id_map = array();
            for($key = 0; $key < $worker->count; $key++) {
                $new_id_map[$key] = isset(static::$_idMap[$worker_id][$key]) ? static::$_idMap[$worker_id][$key] : 0;
            }
            static::$_idMap[$worker_id] = $new_id_map;
        }
    }

    /**
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }

    /**
     * Display staring UI. 打印启动信息
     *
     * @return void
     */
    protected static function displayUI()
    {
        global $argv;
        if (isset($argv[1]) && $argv[1] === '-q') {
            return;
        }
        static::safeEcho("\033[1A\n\033[K-----------------------\033[47;30m WORKERMAN \033[0m-----------------------------\r\n\033[0m");
        static::safeEcho('Workerman version:'. static::VERSION. "          PHP version:". PHP_VERSION. "\r\n");
        static::safeEcho("------------------------\033[47;30m WORKERS \033[0m-------------------------------\r\n");
        if (static::$_OS !== 'linux') {
            static::safeEcho("worker               listen                              processes status\r\n");
            return;
        }
        static::safeEcho("\033[47;30muser\033[0m". str_pad('',
                static::$_maxUserNameLength + 2 - strlen('user')). "\033[47;30mworker\033[0m". str_pad('',
                static::$_maxWorkerNameLength + 2 - strlen('worker')). "\033[47;30mlisten\033[0m". str_pad('',
                static::$_maxSocketNameLength + 2 - strlen('listen')). "\033[47;30mprocesses\033[0m \033[47;30m". "status\033[0m\n");

        foreach (static::$_workers as $worker) {
            static::safeEcho(str_pad($worker->user, static::$_maxUserNameLength + 2). str_pad($worker->name,
                    static::$_maxWorkerNameLength + 2). str_pad($worker->getSocketName(),
                    static::$_maxSocketNameLength + 2). str_pad(' ' . $worker->count, 9). " \033[32;40m [OK] \033[0m\n");
        }
        static::safeEcho("----------------------------------------------------------------\n");
        if (static::$daemonize) {
            global $argv;
            $start_file = $argv[0];
            static::safeEcho("Input \"php $start_file stop\" to quit. Start success.\n\n");
        } else {
            static::safeEcho("Press Ctrl+C to quit. Start success.\n");
        }
    }

    /**
     * Parse command.  检查管理命令 并向master进程发送信号
     * php yourfile.php start | stop | restart | reload | status [-d]
     * $argv[0] =>  yourfile.php
     * $argv[1] =>  start | stop | restart | reload | status
     * $argv[2] =>  -d
     * @return void
     */
    protected static function parseCommand()
    {
        if (static::$_OS !== 'linux') {
            return;
        }
        global $argv;
        // Check argv;
        $start_file = $argv[0];
        $available_commands = array(
            'start',
            'stop',
            'restart',
            'reload',
            'status',
            'connections',
        );
        $usage = "Usage: php yourfile.php {" . implode('|', $available_commands) . "} [-d]\n";
        if (!isset($argv[1]) || !in_array($argv[1], $available_commands)) {
            exit($usage);
        }

        // Get command.
        $command  = trim($argv[1]);
        $command2 = isset($argv[2]) ? $argv[2] : '';

        // Start command.
        $mode = '';
        if ($command === 'start') {
            if ($command2 === '-d' || static::$daemonize) {
                $mode = 'in DAEMON mode';
            } else {
                $mode = 'in DEBUG mode';
            }
        }
        static::log("Workerman[$start_file] $command $mode");

        // Get master process PID. 是否是重复启动
        // posix_kill($master_pid, 0) 检测指定的进程PID是否存在 存在返回true, 反之返回false
        $master_pid      = is_file(static::$pidFile) ? file_get_contents(static::$pidFile) : 0;
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0) && posix_getpid() != $master_pid;
        // Master is still alive?
        if ($master_is_alive) {
            if ($command === 'start') {
                static::log("Workerman[$start_file] already running");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            static::log("Workerman[$start_file] not run");
            exit;
        }

        // execute command.
        switch ($command) {
            case 'start':
                if ($command2 === '-d') {
                    static::$daemonize = true;
                }
                break;
            case 'status':
                // status -d  一秒钟更新一次最新数据 否则只输出一次
                while (1) {
                    if (is_file(static::$_statisticsFile)) {
                        //删掉旧的统计信息
                        @unlink(static::$_statisticsFile);
                    }
                    // Master process will send SIGUSR2 signal to all child processes.
                    posix_kill($master_pid, SIGUSR2);
                    // Sleep 1 second.
                    sleep(1);
                    // Clear terminal.
                    if ($command2 === '-d') {
                        echo "\33[H\33[2J\33(B\33[m";
                    }
                    // Echo status data.
                    echo static::formatStatusData();
                    if ($command2 !== '-d') {
                        exit(0);
                    }
                    echo "\nPress Ctrl+C to quit.\n\n";
                }
                exit(0);
            case 'connections':
                if (is_file(static::$_statisticsFile)) {
                    @unlink(static::$_statisticsFile);
                }
                // Master process will send SIGIO signal to all child processes.
                posix_kill($master_pid, SIGIO);
                // Waiting amoment.
                usleep(500000);
                // Display statisitcs data from a disk file.
                @readfile(static::$_statisticsFile);
                exit(0);
            case 'restart':
            case 'stop':
                //  子进程都会进行退出
                //  worker进程 -g 优雅退出  tcpConnection的__destruct控制退出 服务器主动关闭掉所有的连接之后才会进行退出
                //                非优雅    收到信号后 就执行退出操作 但是会将每个连接的发送缓冲区数据发送完毕
                //  master进程 在 monitorWorkersForLinux 中等待worker进程全部退出后才退出
                if ($command2 === '-g') {
                    static::$_gracefulStop = true;
                    $sig = SIGTERM; //该信号可以被阻塞和处理. 通常用来要求程序自己正常退出
                    static::log("Workerman[$start_file] is gracefully stoping ...");
                } else {
                    static::$_gracefulStop = false;
                    $sig = SIGINT; //Ctrl-C 立即终止 必须退出
                    static::log("Workerman[$start_file] is stoping ...");
                }
                // Send stop signal to master process.
                $master_pid && posix_kill($master_pid, $sig);
                // Timeout.
                $timeout    = 5;
                $start_time = time();
                // Check master process is still alive?
                while (1) {
                    $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        // Timeout? 非优雅退出超时
                        if (!static::$_gracefulStop && time() - $start_time >= $timeout) {
                            static::log("Workerman[$start_file] stop fail");
                            exit;
                        }
                        // Waiting amoment. 优雅退出一直等待
                        usleep(10000);
                        continue;
                    }
                    // Stop success.
                    static::log("Workerman[$start_file] stop success");
                    if ($command === 'stop') {
                        exit(0);
                    }
                    //restart
                    if ($command2 === '-d') {
                        static::$daemonize = true;
                    }
                    break;
                }
                break;
            case 'reload':
                //重启worker进程
                if($command2 === '-g'){
                    $sig = SIGQUIT;
                }else{
                    $sig = SIGUSR1;
                }
                posix_kill($master_pid, $sig);
                exit;
            default :
                exit($usage);
        }
    }

    /**
     * Format status data.
     *
     * @return string
     */
    protected static function formatStatusData()
    {
        static $total_request_cache = array();
        $info = @file(static::$_statisticsFile, FILE_IGNORE_NEW_LINES);
        if (!$info) {
            return '';
        }
        $status_str = '';
        $current_total_request = array();
        $worker_info = json_decode($info[0], true);
        ksort($worker_info, SORT_NUMERIC);
        unset($info[0]);
        $data_waiting_sort = array();
        $read_process_status = false;
        foreach($info as $key => $value) {
            if (!$read_process_status) {
                $status_str .= $value . "\n";
                if (preg_match('/^pid.*?memory.*?listening/', $value)) {
                    $read_process_status = true;
                }
                continue;
            }
            if(preg_match('/^[0-9]+/', $value, $pid_math)) {
                $pid = $pid_math[0];
                $data_waiting_sort[$pid] = $value;
                if(preg_match('/^\S+?\s+?\S+?\s+?\S+?\s+?\S+?\s+?\S+?\s+?\S+?\s+?\S+?\s+?(\S+?)\s+?/', $value, $match)) {
                    $current_total_request[$pid] = $match[1];
                }
            }
        }
        foreach($worker_info as $pid => $info) {
            if (!isset($data_waiting_sort[$pid])) {
                $status_str .= "$pid\t" . str_pad('N/A', 7) . " "
                    . str_pad($info['listen'], static::$_maxSocketNameLength) . " "
                    . str_pad($info['name'], static::$_maxWorkerNameLength) . " "
                    . str_pad('N/A', 11) . " " . str_pad('N/A', 9) . " "
                    . str_pad('N/A', 7) . " " . str_pad('N/A', 13) . " N/A    [busy] \n";
                continue;
            }
            //$qps = isset($total_request_cache[$pid]) ? $current_total_request[$pid]
            if (!isset($total_request_cache[$pid]) || !isset($current_total_request[$pid])) {
                $qps = 0;
            } else {
                $qps = $current_total_request[$pid] - $total_request_cache[$pid];
            }
            $status_str .= $data_waiting_sort[$pid]. " " . str_pad($qps, 6) ." [idle]\n";
        }
        $total_request_cache = $current_total_request;
        return $status_str;
    }


    /**
     * Install signal handler. 注册信号处理函数
     * @return void
     */
    protected static function installSignal()
    {
        if (static::$_OS !== 'linux') {
            return;
        }
        // stop
        pcntl_signal(SIGINT, array('\Workerman\Worker', 'signalHandler'), false);
        // graceful stop
        pcntl_signal(SIGTERM, array('\Workerman\Worker', 'signalHandler'), false);
        // reload
        pcntl_signal(SIGUSR1, array('\Workerman\Worker', 'signalHandler'), false);
        // graceful reload
        pcntl_signal(SIGQUIT, array('\Workerman\Worker', 'signalHandler'), false);
        // status
        pcntl_signal(SIGUSR2, array('\Workerman\Worker', 'signalHandler'), false);
        // connection status
        pcntl_signal(SIGIO, array('\Workerman\Worker', 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * Reinstall signal handler. worker使用event处理signal
     * @return void
     */
    protected static function reinstallSignal()
    {
        if (static::$_OS !== 'linux') {
            return;
        }
        //忽略之前master设置的信号处理
        // uninstall stop signal handler
        pcntl_signal(SIGINT, SIG_IGN, false);
        // uninstall graceful stop signal handler
        pcntl_signal(SIGTERM, SIG_IGN, false);
        // uninstall reload signal handler
        pcntl_signal(SIGUSR1, SIG_IGN, false);
        // uninstall graceful reload signal handler
        pcntl_signal(SIGQUIT, SIG_IGN, false);
        // uninstall status signal handler
        pcntl_signal(SIGUSR2, SIG_IGN, false);
        // reinstall stop signal handler
        static::$globalEvent->add(SIGINT, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        // reinstall graceful stop signal handler
        static::$globalEvent->add(SIGTERM, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        // reinstall reload signal handler
        static::$globalEvent->add(SIGUSR1, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        // reinstall graceful reload signal handler
        static::$globalEvent->add(SIGQUIT, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        // reinstall  status signal handler
        static::$globalEvent->add(SIGUSR2, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        // reinstall connection status signal handler
        static::$globalEvent->add(SIGIO, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
    }

    /**
     * Signal handler. 信号处理
     *
     * @param int $signal
     */
    public static function signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case SIGINT:
                static::$_gracefulStop = false;
                static::stopAll();
                break;
            // Graceful stop.
            case SIGTERM:
                static::$_gracefulStop = true;
                static::stopAll();
                break;
            // Reload.
            case SIGQUIT:
            case SIGUSR1:
                if($signal === SIGQUIT){
                    static::$_gracefulStop = true;
                }else{
                    static::$_gracefulStop = false;
                }
                static::$_pidsToRestart = static::getAllWorkerPids();
                static::reload();
                break;
            // Show status.
            case SIGUSR2:
                static::writeStatisticsToStatusFile();
                break;
            // Show connection status.
            case SIGIO:
                static::writeConnectionsStatisticsToStatusFile();
                break;
        }
    }

    /**
     * Run as deamon mode. master进程以守护进程执行
     *
     * @throws Exception
     */
    protected static function daemonize()
    {
        if (!static::$daemonize) {
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new Exception('fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === posix_setsid()) {
            throw new Exception("setsid fail");
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new Exception("fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * Redirect standard input and output. 标准的输入输出重定向到$stdoutFile
     *
     * @throws Exception
     */
    public static function resetStd()
    {
        if (!static::$daemonize) {
            return;
        }
        global $STDOUT, $STDERR;
        $handle = fopen(static::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(static::$stdoutFile, "a");
            $STDERR = fopen(static::$stdoutFile, "a");
        } else {
            throw new Exception('can not open stdoutFile ' . static::$stdoutFile);
        }
    }

    /**
     * Save pid. 把masterpid写入pid文件 并保留到$_masterPid中
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        if (static::$_OS !== 'linux') {
            return;
        }
        static::$_masterPid = posix_getpid();
        if (false === @file_put_contents(static::$pidFile, static::$_masterPid)) {
            throw new Exception('can not save pid to ' . static::$pidFile);
        }
    }

    /**
     * Get event loop name.  获取event模型
     * 如果没有配置event 获取默认的
     * @return string
     */
    protected static function getEventLoopName()
    {
        if (static::$eventLoopClass) {
            return static::$eventLoopClass;
        }

        //安装了 哪个扩展 就用哪个
        $loop_name = '';
        foreach (static::$_availableEventLoops as $name=>$class) {
            if (extension_loaded($name)) {
                $loop_name = $name;
                break;
            }
        }

        if ($loop_name) {
            //是否使用使用reactPHP
            if (interface_exists('\React\EventLoop\LoopInterface')) {
                switch ($loop_name) {
                    case 'libevent':
                        static::$eventLoopClass = '\Workerman\Events\React\LibEventLoop';
                        break;
                    case 'event':
                        static::$eventLoopClass = '\Workerman\Events\React\ExtEventLoop';
                        break;
                    default :
                        static::$eventLoopClass = '\Workerman\Events\React\StreamSelectLoop';
                        break;
                }
            } else {
                static::$eventLoopClass = static::$_availableEventLoops[$loop_name];
            }
        } else {
            static::$eventLoopClass = interface_exists('\React\EventLoop\LoopInterface')? '\Workerman\Events\React\StreamSelectLoop':'\Workerman\Events\Select';
        }
        return static::$eventLoopClass;
    }

    /**
     * Get all pids of worker processes. 获取所有worker进程的pid
     *
     * @return array
     */
    protected static function getAllWorkerPids()
    {
        $pid_array = array();
        foreach (static::$_pidMap as $worker_pid_array) {
            foreach ($worker_pid_array as $worker_pid) {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
    }

    /**
     * Fork some worker processes. 创建worker进程
     *
     * @return void
     */
    protected static function forkWorkers()
    {
        if (static::$_OS === 'linux') {
            static::forkWorkersForLinux();
        } else {
            static::forkWorkersForWindows();
        }
    }

    /**
     * Fork some worker processes.linux下创建worker进程
     * @return void
     */
    protected static function forkWorkersForLinux()
    {
        foreach (static::$_workers as $worker) {
            if (static::$_status === static::STATUS_STARTING) {
                //master进程名 initWorkers里面已经把name设置成了none
                if (empty($worker->name)) {
                    $worker->name = $worker->getSocketName();
                }
                $worker_name_length = strlen($worker->name);
                if (static::$_maxWorkerNameLength < $worker_name_length) {
                    static::$_maxWorkerNameLength = $worker_name_length;
                }
            }

            $worker->count = $worker->count <= 0 ? 1 : $worker->count;
            while (count(static::$_pidMap[$worker->workerId]) < $worker->count) {
                static::forkOneWorkerForLinux($worker);
            }
        }
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     */
    public static function forkWorkersForWindows()
    {
        $files = static::getStartFilesForWindows();
        global $argv;
        if(isset($argv[1]) && $argv[1] === '-q')
        {
            if(count(static::$_workers) > 1)
            {
                echo "@@@ Error: multi workers init in one php file are not support @@@\r\n";
                echo "@@@ Please visit http://wiki.workerman.net/Multi_woker_for_win @@@\r\n";
            }
            elseif(count(static::$_workers) <= 0)
            {
                exit("@@@no worker inited@@@\r\n\r\n");
            }

            reset(static::$_workers);
            /** @var Worker $worker */
            $worker = current(static::$_workers);

            // Display UI.
            echo str_pad($worker->name, 21) . str_pad($worker->getSocketName(), 36) . str_pad($worker->count, 10) . "[ok]\n";
            $worker->listen();
            $worker->run();
            exit("@@@child exit@@@\r\n");
        }
        else
        {
            static::$globalEvent = new \Workerman\Events\Select();
            Timer::init(static::$globalEvent);
            foreach($files as $start_file)
            {
                static::forkOneWorkerForWindows($start_file);
            }
        }
    }

    /**
     * Get start files for windows.
     *
     * @return array
     */
    public static function getStartFilesForWindows() {
        global $argv;
        $files = array();
        foreach($argv as $file)
        {
            $ext = pathinfo($file, PATHINFO_EXTENSION );
            if($ext !== 'php')
            {
                continue;
            }
            if(is_file($file))
            {
                $files[$file] = $file;
            }
        }
        return $files;
    }

    /**
     * Fork one worker process.
     *
     * @param string $start_file
     */
    public static function forkOneWorkerForWindows($start_file)
    {
        $start_file = realpath($start_file);
        $std_file = sys_get_temp_dir() . '/'.str_replace(array('/', "\\", ':'), '_', $start_file).'.out.txt';

        $descriptorspec = array(
            0 => array('pipe', 'a'), // stdin
            1 => array('file', $std_file, 'w'), // stdout
            2 => array('file', $std_file, 'w') // stderr
        );


        $pipes       = array();
        $process     = proc_open("php \"$start_file\" -q", $descriptorspec, $pipes);
        $std_handler = fopen($std_file, 'a+');
        stream_set_blocking($std_handler, 0);

        if (empty(static::$globalEvent)) {
            static::$globalEvent = new Select();
            Timer::init(static::$globalEvent);
        }
        $timer_id = Timer::add(1, function()use($std_handler)
        {
            echo fread($std_handler, 65535);
        });

        // 保存子进程句柄
        static::$_processForWindows[$start_file] = array($process, $start_file, $timer_id);
    }

    /**
     * check worker status for windows.
     * @return void
     */
    public static function checkWorkerStatusForWindows()
    {
        foreach(static::$_processForWindows as $process_data)
        {
            $process = $process_data[0];
            $start_file = $process_data[1];
            $timer_id = $process_data[2];
            $status = proc_get_status($process);
            if(isset($status['running']))
            {
                if(!$status['running'])
                {
                    echo "process $start_file terminated and try to restart\n";
                    Timer::del($timer_id);
                    @proc_close($process);
                    static::forkOneWorkerForWindows($start_file);
                }
            }
            else
            {
                echo "proc_get_status fail\n";
            }
        }
    }


    /**
     * Fork one worker process. linux创建worker进程并进行事件监听
     *
     * @param \Workerman\Worker $worker
     * @throws Exception
     */
    protected static function forkOneWorkerForLinux($worker)
    {
        // Get available worker id.
        $id = static::getId($worker->workerId, 0);
        if ($id === false) {
            return;
        }
        $pid = pcntl_fork();
        // For master process.
        // 当前master进程
        if ($pid > 0) {
            static::$_pidMap[$worker->workerId][$pid] = $pid;
            static::$_idMap[$worker->workerId][$id]   = $pid;
        } // For child processes.
        // 新生成的worker进程
        elseif (0 === $pid) {
            if ($worker->reusePort) {
                $worker->listen();
            }
            if (static::$_status === static::STATUS_STARTING) {
                //worker进程 输出 错误重定向
                static::resetStd();
            }

            //不存在子进程
            static::$_pidMap  = array();
            //保存当前worker进程对象
            static::$_workers = array($worker->workerId => $worker);
            //删除定时器
            Timer::delAll();
            //设置进程title
            static::setProcessTitle('WorkerMan: worker process  ' . $worker->name . ' ' . $worker->getSocketName());
            $worker->setUserAndGroup();
            //worker进程在master进程中的序号
            $worker->id = $id;
            $worker->run();
            $err = new Exception('event-loop exited');
            static::log($err);
            exit(250);
        } else {
            throw new Exception("forkOneWorker fail");
        }
    }

    /**
     * Get worker id.从$_idMap中取出一个没有没有被创建的进程id
     * @param int $worker_id
     * @param int $pid
     * array_search 多个相同值返回首个
     * @return integer
     */
    protected static function getId($worker_id, $pid)
    {
        return array_search($pid, static::$_idMap[$worker_id]);
    }

    /**
     * Set unix user and group for current process. worker进程切换user group
     * @return void
     */
    public function setUserAndGroup()
    {
        // Get uid.
        $user_info = posix_getpwnam($this->user);
        if (!$user_info) {
            static::log("Warning: User {$this->user} not exsits");
            return;
        }
        $uid = $user_info['uid'];
        // Get gid.
        if ($this->group) {
            $group_info = posix_getgrnam($this->group);
            if (!$group_info) {
                static::log("Warning: Group {$this->group} not exsits");
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }

        // Set uid and gid.
        //当前用户 用户组 不一样才进行设置
        //  posix_setgid 失败
        //  通过posix_initgroups把gid加到$user_info['name']的附加组中
        if ($uid != posix_getuid() || $gid != posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($user_info['name'], $gid) || !posix_setuid($uid)) {
                static::log("Warning: change gid or uid fail.");
            }
        }
    }

    /**
     * Set process name. 设置进程在ps命令下的标识
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorWorkers()
    {
        if (static::$_OS === 'linux') {
            static::monitorWorkersForLinux();
        } else {
            static::monitorWorkersForWindows();
        }
    }

    /**
     * Monitor all child processes. 监控worker进程 worker进程生成 master进程退出
     * worker进程的退出会引发 worker进程的重新创建 或者 master进程退出
     * @return void
     */
    protected static function monitorWorkersForLinux()
    {
        static::$_status = static::STATUS_RUNNING;
        while (1) {
            // Calls signal handlers for pending signals.
            //http://www.jianshu.com/p/c3fb535ecd8b
            //http://rango.swoole.com/archives/364
            echo  'a';
            pcntl_signal_dispatch();
            echo 'b';
            // Suspends execution of the current process until a child has exited, or until a signal is delivered
            $status = 0;
            //WUNTRACED 子进程已经退出并且其状态未报告时返回。 master进程阻塞在这里等着worker进程退出
            //wait函数刮起当前进程的执行直到一个子进程退出或接收到一个信号要求中断当前进程或调用一个信号处理函数。
            $pid    = pcntl_wait($status, WUNTRACED);
            echo $pid;
            echo 'c';
            // Calls signal handlers for pending signals again.
            pcntl_signal_dispatch();
            // If a child has already exited.
            if ($pid > 0) {
                // Find out witch worker process exited.
                foreach (static::$_pidMap as $worker_id => $worker_pid_array) {
                    if (isset($worker_pid_array[$pid])) {
                        $worker = static::$_workers[$worker_id];
                        // Exit status.
                        if ($status !== 0) {
                            static::log("worker[" . $worker->name . ":$pid] exit with status $status");
                        }

                        // For Statistics. worker进程退出的统计
                        if (!isset(static::$_globalStatistics['worker_exit_info'][$worker_id][$status])) {
                            static::$_globalStatistics['worker_exit_info'][$worker_id][$status] = 0;
                        }
                        static::$_globalStatistics['worker_exit_info'][$worker_id][$status]++;

                        // Clear process data. master下的pid
                        unset(static::$_pidMap[$worker_id][$pid]);

                        // Mark id is available. 用来重新生成worker进程 来替换退出的worker进程
                        $id                            = static::getId($worker_id, $pid);
                        static::$_idMap[$worker_id][$id] = 0;

                        break;
                    }
                }
                // Is still running state then fork a new worker process.
                if (static::$_status !== static::STATUS_SHUTDOWN) {
                    static::forkWorkers(); //重新生成一个worker进程
                    // If reloading continue. 重启
                    if (isset(static::$_pidsToRestart[$pid])) {
                        unset(static::$_pidsToRestart[$pid]);
                        static::reload();
                    }
                } else {
                    //stop跟restart会设置  1435行 退出的worker进程已经从pidMap中去掉了
                    // If shutdown state and all child processes exited then master process exit.
                    if (!static::getAllWorkerPids()) {
                        static::exitAndClearAll();
                    }
                }
            } else {
                // If shutdown state and all child processes exited then master process exit.
                // 不存在子进程返回-1 || 信号中断返回-1
                if (static::$_status === static::STATUS_SHUTDOWN && !static::getAllWorkerPids()) {
                    static::exitAndClearAll();
                }
            }
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorWorkersForWindows()
    {
        Timer::add(0.5, "\\Workerman\\Worker::checkWorkerStatusForWindows");

        static::$globalEvent->loop();
    }

    /**
     * Exit current process. 退出master进程
     *
     * @return void
     */
    protected static function exitAndClearAll()
    {
        foreach (static::$_workers as $worker) {
            $socket_name = $worker->getSocketName();
            //如果是unix域协议 删掉文件
            if ($worker->transport === 'unix' && $socket_name) {
                list(, $address) = explode(':', $socket_name, 2);
                @unlink($address);
            }
        }
        //删除pid文件
        @unlink(static::$pidFile);
        static::log("Workerman[" . basename(static::$_startFile) . "] has been stopped");
        if (static::$onMasterStop) {
            call_user_func(static::$onMasterStop);
        }
        exit(0);
    }

    /**
     * Execute reload. 重启worker进程
     * master 找到可以被重启的所有worker进程 重启一个  monitorWorkersForLinux重启下一个 一直到$_pidsToRestart没有需要重启的worker进程
     * worker reloadable如果为true  就stopAll 否则只是回调onWorkerReload
     * @return void
     */
    protected static function reload()
    {
        // For master process.
        if (static::$_masterPid === posix_getpid()) {
            // Set reloading state.static::$_status !== static::STATUS_SHUTDOWN 可能stop restart关闭不及时
            if (static::$_status !== static::STATUS_RELOADING && static::$_status !== static::STATUS_SHUTDOWN) {
                static::log("Workerman[" . basename(static::$_startFile) . "] reloading");
                static::$_status = static::STATUS_RELOADING;
                // Try to emit onMasterReload callback.
                if (static::$onMasterReload) {
                    try {
                        call_user_func(static::$onMasterReload);
                    } catch (\Exception $e) {
                        static::log($e);
                        exit(250);
                    } catch (\Error $e) {
                        static::log($e);
                        exit(250);
                    }
                    static::initId();
                }
            }

            if (static::$_gracefulStop) {
                $sig = SIGQUIT;
            } else {
                $sig = SIGUSR1;
            }

            // Send reload signal to all child processes.
            // 多个master进程 可以进行重启的master进程下的worker进程
            $reloadable_pid_array = array();
            foreach (static::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = static::$_workers[$worker_id];
                if ($worker->reloadable) {
                    foreach ($worker_pid_array as $pid) {
                        $reloadable_pid_array[$pid] = $pid;
                    }
                } else {
                    //这里会重复给worker进程发
                    foreach ($worker_pid_array as $pid) {
                        // Send reload signal to a worker process which reloadable is false.
                        posix_kill($pid, $sig);
                    }
                }
            }

            // Get all pids that are waiting reload. 可以被重启的worker进程
            static::$_pidsToRestart = array_intersect(static::$_pidsToRestart, $reloadable_pid_array);

            // Reload complete.
            if (empty(static::$_pidsToRestart)) {
                if (static::$_status !== static::STATUS_SHUTDOWN) {
                    static::$_status = static::STATUS_RUNNING;
                }
                return;
            }
            // Continue reload. 一次重启一个
            $one_worker_pid = current(static::$_pidsToRestart);
            // Send reload signal to a worker process.
            posix_kill($one_worker_pid, $sig);
            // If the process does not exit after static::KILL_WORKER_TIMER_TIME seconds try to kill it.
            if(!static::$_gracefulStop){
                Timer::add(static::KILL_WORKER_TIMER_TIME, 'posix_kill', array($one_worker_pid, SIGKILL), false);
            }
        } // For child processes.
        else {
            reset(static::$_workers);
            $worker = current(static::$_workers);
            // Try to emit onWorkerReload callback.
            if ($worker->onWorkerReload) {
                try {
                    call_user_func($worker->onWorkerReload, $worker);
                } catch (\Exception $e) {
                    static::log($e);
                    exit(250);
                } catch (\Error $e) {
                    static::log($e);
                    exit(250);
                }
            }

            if ($worker->reloadable) {
                static::stopAll();
            }
        }
    }

    /**
     * Stop.
     * master进程 monitorWorkersForLinux 中等待worker进程全部退出后才退出
     * @return void
     */
    public static function stopAll()
    {
        static::$_status = static::STATUS_SHUTDOWN;
        // For master process.
        if (static::$_masterPid === posix_getpid()) {
            static::log("Workerman[" . basename(static::$_startFile) . "] stopping ...");
            $worker_pid_array = static::getAllWorkerPids();
            // Send stop signal to all child processes.
            if (static::$_gracefulStop) {
                $sig = SIGTERM;
            } else {
                $sig = SIGINT;
            }
            foreach ($worker_pid_array as $worker_pid) {
                posix_kill($worker_pid, $sig);
                //非优雅退出  防止链接一直不可写 直接发送kill
                if(!static::$_gracefulStop){
                    //SIGKILL用来立即结束程序的运行. 本信号不能被阻塞, 处理和忽略. 强制退出
                    Timer::add(static::KILL_WORKER_TIMER_TIME, 'posix_kill', array($worker_pid, SIGKILL), false);
                }
            }
            // Remove statistics file.
            if (is_file(static::$_statisticsFile)) {
                @unlink(static::$_statisticsFile);
            }
        } // For child processes.
        else {
            // Execute exit.
            foreach (static::$_workers as $worker) {
                $worker->stop();
            }
            //ConnectionInterface::$statistics['connection_count'] <= 0 这是防止当前worker中已经没有连接了 优雅关闭无法进行
            if (!static::$_gracefulStop || ConnectionInterface::$statistics['connection_count'] <= 0) {
                static::$globalEvent->destroy();
                exit(0);
            }
        }
    }

    /**
     * Get process status.
     *
     * @return number
     */
    public static function getStatus()
    {
        return static::$_status;
    }

    /**
     * If stop gracefully.
     *
     * @return boolean
     */
    public static function getGracefulStop()
    {
        return static::$_gracefulStop;
    }

    /**
     * Write statistics data to disk. 把进程运行的统计信息写入文件
     *
     * @return void
     */
    protected static function writeStatisticsToStatusFile()
    {
        // For master process.
        if (static::$_masterPid === posix_getpid()) {
            $all_worker_info = array();
            foreach(static::$_pidMap as $worker_id => $pid_array) {
                /** @var /Workerman/Worker $worker */
                $worker = static::$_workers[$worker_id];
                foreach($pid_array as $pid) {
                    $all_worker_info[$pid] = array('name' => $worker->name, 'listen' => $worker->getSocketName());
                }
            }

            file_put_contents(static::$_statisticsFile, json_encode($all_worker_info)."\n", FILE_APPEND);
            $loadavg = function_exists('sys_getloadavg') ? array_map('round', sys_getloadavg(), array(2)) : array('-', '-', '-');
            file_put_contents(static::$_statisticsFile,
                "----------------------------------------------GLOBAL STATUS----------------------------------------------------\n", FILE_APPEND);
            file_put_contents(static::$_statisticsFile,
                'Workerman version:' . static::VERSION . "          PHP version:" . PHP_VERSION . "\n", FILE_APPEND);
            file_put_contents(static::$_statisticsFile, 'start time:' . date('Y-m-d H:i:s',
                    static::$_globalStatistics['start_timestamp']) . '   run ' . floor((time() - static::$_globalStatistics['start_timestamp']) / (24 * 60 * 60)) . ' days ' . floor(((time() - static::$_globalStatistics['start_timestamp']) % (24 * 60 * 60)) / (60 * 60)) . " hours   \n",
                FILE_APPEND);
            $load_str = 'load average: ' . implode(", ", $loadavg);
            file_put_contents(static::$_statisticsFile,
                str_pad($load_str, 33) . 'event-loop:' . static::getEventLoopName() . "\n", FILE_APPEND);
            file_put_contents(static::$_statisticsFile,
                count(static::$_pidMap) . ' workers       ' . count(static::getAllWorkerPids()) . " processes\n",
                FILE_APPEND);
            file_put_contents(static::$_statisticsFile,
                str_pad('worker_name', static::$_maxWorkerNameLength) . " exit_status      exit_count\n", FILE_APPEND);
            foreach (static::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = static::$_workers[$worker_id];
                if (isset(static::$_globalStatistics['worker_exit_info'][$worker_id])) {
                    foreach (static::$_globalStatistics['worker_exit_info'][$worker_id] as $worker_exit_status => $worker_exit_count) {
                        file_put_contents(static::$_statisticsFile,
                            str_pad($worker->name, static::$_maxWorkerNameLength) . " " . str_pad($worker_exit_status,
                                16) . " $worker_exit_count\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents(static::$_statisticsFile,
                        str_pad($worker->name, static::$_maxWorkerNameLength) . " " . str_pad(0, 16) . " 0\n",
                        FILE_APPEND);
                }
            }
            file_put_contents(static::$_statisticsFile,
                "----------------------------------------------PROCESS STATUS---------------------------------------------------\n",
                FILE_APPEND);
            file_put_contents(static::$_statisticsFile,
                "pid\tmemory  " . str_pad('listening', static::$_maxSocketNameLength) . " " . str_pad('worker_name',
                    static::$_maxWorkerNameLength) . " connections " . str_pad('send_fail', 9) . " "
                . str_pad('timers', 8) . str_pad('total_request', 13) ." qps    status\n", FILE_APPEND);

            chmod(static::$_statisticsFile, 0722);

            foreach (static::getAllWorkerPids() as $worker_pid) {
                posix_kill($worker_pid, SIGUSR2);
            }
            return;
        }

        // For child processes.
        /** @var \Workerman\Worker $worker */
        $worker            = current(static::$_workers);
        $worker_status_str = posix_getpid() . "\t" . str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7)
            . " " . str_pad($worker->getSocketName(), static::$_maxSocketNameLength) . " "
            . str_pad(($worker->name === $worker->getSocketName() ? 'none' : $worker->name), static::$_maxWorkerNameLength)
            . " ";
        $worker_status_str .= str_pad(ConnectionInterface::$statistics['connection_count'], 11)
            . " " .  str_pad(ConnectionInterface::$statistics['send_fail'], 9)
            . " " . str_pad(static::$globalEvent->getTimerCount(), 7)
            . " " . str_pad(ConnectionInterface::$statistics['total_request'], 13) . "\n";
        file_put_contents(static::$_statisticsFile, $worker_status_str, FILE_APPEND);
    }

    /**
     * Write statistics data to disk. 把worker进程的网络相关统计信息记入文件
     *
     * @return void
     */
    protected static function writeConnectionsStatisticsToStatusFile()
    {
        // For master process.
        if (static::$_masterPid === posix_getpid()) {
            file_put_contents(static::$_statisticsFile, "--------------------------------------------------------------------- WORKERMAN CONNECTION STATUS --------------------------------------------------------------------------------\n", FILE_APPEND);
            file_put_contents(static::$_statisticsFile, "PID      Worker          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address\n", FILE_APPEND);
            chmod(static::$_statisticsFile, 0722);
            foreach (static::getAllWorkerPids() as $worker_pid) {
                posix_kill($worker_pid, SIGIO);
            }
            return;
        }

        // For child processes.
        $bytes_format = function($bytes)
        {
            if($bytes > 1024*1024*1024*1024) {
                return round($bytes/(1024*1024*1024*1024), 1)."TB";
            }
            if($bytes > 1024*1024*1024) {
                return round($bytes/(1024*1024*1024), 1)."GB";
            }
            if($bytes > 1024*1024) {
                return round($bytes/(1024*1024), 1)."MB";
            }
            if($bytes > 1024) {
                return round($bytes/(1024), 1)."KB";
            }
            return $bytes."B";
        };

        $pid = posix_getpid();
        $str = '';
        reset(static::$_workers);
        $current_worker = current(static::$_workers);
        $default_worker_name = $current_worker->name;

        /** @var \Workerman\Worker $worker */
        foreach(TcpConnection::$connections as $connection) {
            /** @var \Workerman\Connection\TcpConnection $connection */
            $transport      = $connection->transport;
            $ipv4           = $connection->isIpV4() ? ' 1' : ' 0';
            $ipv6           = $connection->isIpV6() ? ' 1' : ' 0';
            $recv_q         = $bytes_format($connection->getRecvBufferQueueSize());
            $send_q         = $bytes_format($connection->getSendBufferQueueSize());
            $local_address  = trim($connection->getLocalAddress());
            $remote_address = trim($connection->getRemoteAddress());
            $state          = $connection->getStatus(false);
            $bytes_read     = $bytes_format($connection->bytesRead);
            $bytes_written  = $bytes_format($connection->bytesWritten);
            $id             = $connection->id;
            $protocol       = $connection->protocol ? $connection->protocol : $connection->transport;
            $pos            = strrpos($protocol, '\\');
            if ($pos) {
                $protocol = substr($protocol, $pos+1);
            }
            if (strlen($protocol) > 15) {
                $protocol = substr($protocol, 0, 13) . '..';
            }
            $worker_name = isset($connection->worker) ? $connection->worker->name : $default_worker_name;
            if (strlen($worker_name) > 14) {
                $worker_name = substr($worker_name, 0, 12) . '..';
            }
            $str .= str_pad($pid, 9) . str_pad($worker_name, 16) .  str_pad($id, 10) . str_pad($transport, 8)
                . str_pad($protocol, 16) . str_pad($ipv4, 7) . str_pad($ipv6, 7) . str_pad($recv_q, 13)
                . str_pad($send_q, 13) . str_pad($bytes_read, 13) . str_pad($bytes_written, 13) . ' '
                . str_pad($state, 14) . ' ' . str_pad($local_address, 22) . ' ' . str_pad($remote_address, 22) ."\n";
        }
        if ($str) {
            file_put_contents(static::$_statisticsFile, $str, FILE_APPEND);
        }
    }

    /**
     * Check errors when current process exited. 进程关闭时的回调函数 异常退出记录信息
     * worker进程 异常终止的信息
     * @return void
     */
    public static function checkErrors()
    {
        if (static::STATUS_SHUTDOWN != static::$_status) {
            $error_msg = static::$_OS === 'linx' ? 'Worker['. posix_getpid() .'] process terminated' : 'Worker process terminated';
            $errors    = error_get_last();
            if ($errors && ($errors['type'] === E_ERROR ||
                    $errors['type'] === E_PARSE ||
                    $errors['type'] === E_CORE_ERROR ||
                    $errors['type'] === E_COMPILE_ERROR ||
                    $errors['type'] === E_RECOVERABLE_ERROR)
            ) {
                $error_msg .= ' with ERROR: ' . static::getErrorType($errors['type']) . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"";
            }
            static::log($error_msg);
        }
    }

    /**
     * Get error message by error code. error级别对应的字符串
     *
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        switch ($type) {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
    }

    /**
     * Log.记录日志
     * @param string $msg
     * @return void
     */
    public static function log($msg)
    {
        $msg = $msg . "\n";
        if (!static::$daemonize) {
            static::safeEcho($msg);
        }
        file_put_contents((string)static::$logFile, date('Y-m-d H:i:s') . ' ' . 'pid:'
            . (static::$_OS === 'linux' ? posix_getpid() : 1) . ' ' . $msg, FILE_APPEND | LOCK_EX); //加锁写入 防止顺序错乱
    }

    /**
     * Safe Echo. 只写到终端中
     * posix_isatty(STDOUT) stdout是否是linux终端
     * @param $msg
     */
    public static function safeEcho($msg)
    {
        if (!function_exists('posix_isatty') || posix_isatty(STDOUT)) {
            echo $msg;
        }
    }

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name = '', $context_option = array())
    {
        // Save all worker instances.
        //http://php.net/manual/zh/function.spl-object-hash.php  为对象设置一个标识
        $this->workerId                  = spl_object_hash($this);
        //如果创建多个worker对象 $_workers中保留所有worker对象(master进程)
        static::$_workers[$this->workerId] = $this;
        static::$_pidMap[$this->workerId]  = array();

        // Get autoload root path. 项目的启动目录
        $backtrace                = debug_backtrace();
        $this->_autoloadRootPath = dirname($backtrace[0]['file']);
        // Context for socket.
        if ($socket_name) {
            $this->_socketName = $socket_name;
            //在Linux中backlog表示已完成(ESTABLISHED)且未accept的队列大小.
            //TCP 三次握手在应用程序accept之前由内核完成. 应用程序调用accept只是获取已经完成的连接.
            if (!isset($context_option['socket']['backlog'])) {
                $context_option['socket']['backlog'] = static::DEFAULT_BACKLOG;
            }
            //只是创建资源流的上下文
            $this->_context = stream_context_create($context_option);
        }
    }


    /**
     * Listen. 处理协议并进行listen
     *
     * @throws Exception
     */
    public function listen()
    {
        if (!$this->_socketName) {
            return;
        }

        // Autoload.
        Autoloader::setRootPath($this->_autoloadRootPath);

        if (!$this->_mainSocket) {
            // Get the application layer communication protocol and listening address.
            list($scheme, $address) = explode(':', $this->_socketName, 2);
            // Check application layer protocol class.
            //说明scheme是应用层协议 否则就是传输层协议
            if (!isset(static::$_builtinTransports[$scheme])) {
                $scheme         = ucfirst($scheme);
                $this->protocol = '\\Protocols\\' . $scheme;
                if (!class_exists($this->protocol)) {
                    $this->protocol = "\\Workerman\\Protocols\\$scheme";
                    if (!class_exists($this->protocol)) {
                        throw new Exception("class \\Protocols\\$scheme not exist");
                    }
                }

                //传输层协议不合法
                if (!isset(static::$_builtinTransports[$this->transport])) {
                    throw new \Exception('Bad worker->transport ' . var_export($this->transport, true));
                }
            } else {
                $this->transport = $scheme;
            }

            //最终的传输层协议
            $local_socket = static::$_builtinTransports[$this->transport] . ":" . $address;

            // Flag.
            $flags = $this->transport === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $errno = 0;
            $errmsg = '';
            // SO_REUSEPORT. worker进程才会执行
            if ($this->reusePort) {
                stream_context_set_option($this->_context, 'socket', 'so_reuseport', 1);
            }

            // Create an Internet or Unix domain server socket.
            // 根据传输层建立链接 stream_socket_server 包括bind listen
            $this->_mainSocket = stream_socket_server($local_socket, $errno, $errmsg, $flags, $this->_context);
            if (!$this->_mainSocket) {
                throw new Exception($errmsg);
            }

            //关闭了 ssl __controller中创建的_mainSocket开启了ssl 因为_mainSocket是监听链接 在这里关闭ssl
            //
            //http://www.kuqin.com/php5_doc/function.stream-socket-server.html
            if ($this->transport === 'ssl') {
                stream_socket_enable_crypto($this->_mainSocket, false);
            } elseif ($this->transport === 'unix') {
                //如果使用unix协议需要把socketFile的用户权限改掉 防止出现无权限的情况
                $socketFile = substr($address, 2);
                if ($this->user) {
                    chown($socketFile, $this->user);
                }
                if ($this->group) {
                    chgrp($socketFile, $this->group);
                }
            }

            // Try to open keepalive for tcp and disable Nagle algorithm.
            //Nagle 算法
            //http://wenda.workerman.net/?/question/873
            //http://wenda.workerman.net/?/question/1476
            /*
             *  php提供了两种类型的socket，stream_socket 和 sockets，二者api不兼容。
             *  stream_socket是php内置的，可以直接使用，并且api和stream 的api通用(可以调用fread fwrite...)。
             *  sockets需要php安装sockets扩展才能使用。
             *
             *  stream_socket与sockets相比有个缺点，无法精确设置socket选项。当需要设置stream_socket选项时，
             *  可以通过socket_import_stream将stream_socket转换成扩展的sockets，然后就可以通过socket_set_option设置stream_socket的socket选项了。
             */
            if (function_exists('socket_import_stream') && static::$_builtinTransports[$this->transport] === 'tcp') {
                $socket = socket_import_stream($this->_mainSocket);
                @socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
            }

            // Non blocking. 设置为非阻塞
            stream_set_blocking($this->_mainSocket, 0);
        }

        $this->resumeAccept();
    }

    /**
     * Unlisten. worker进程退出时关闭监听链接
     *
     * @return void
     */
    public function unlisten() {
        $this->pauseAccept();
        if ($this->_mainSocket) {
            @fclose($this->_mainSocket);
            $this->_mainSocket = null;
        }
    }

    /**
     * Pause accept new connections.删掉ev_read事件取消accept
     *
     * @return void
     */
    public function pauseAccept()
    {
        if (static::$globalEvent && false === $this->_pauseAccept && $this->_mainSocket) {
            static::$globalEvent->del($this->_mainSocket, EventInterface::EV_READ);
            $this->_pauseAccept = true;
        }
    }

    /**
     * Resume accept new connections. 添加ev_read事件进行accept
     * master进程不会进行accept操作 因为还没有事件模型
     * 通过listen进行的调用没效果
     * @return void
     */
    public function resumeAccept()
    {
        // Register a listener to be notified when server socket is ready to read.
        if (static::$globalEvent && true === $this->_pauseAccept && $this->_mainSocket) {
            if ($this->transport !== 'udp') {
                static::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptConnection'));
            } else {
                static::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ,
                    array($this, 'acceptUdpConnection'));
            }
            $this->_pauseAccept = false;
        }
    }

    /**
     * Get socket name.
     *
     * @return string
     */
    public function getSocketName()
    {
        return $this->_socketName ? lcfirst($this->_socketName) : 'none';
    }

    /**
     * Run worker instance. 执行worker进程 处理事件模型(添加connection读事件,信号,定时器) 开始调度
     * @return void
     */
    public function run()
    {
        //Update process state.
        static::$_status = static::STATUS_RUNNING;

        // Register shutdown function for checking errors.
        register_shutdown_function(array("\\Workerman\\Worker", 'checkErrors'));

        // Set autoload root path.
        Autoloader::setRootPath($this->_autoloadRootPath);

        // Create a global event loop.
        if (!static::$globalEvent) {
            $event_loop_class = static::getEventLoopName();
            static::$globalEvent = new $event_loop_class;
            $this->resumeAccept();
        }

        // Reinstall signal.
        static::reinstallSignal();

        // Init Timer. 使用event模型进行定时器功能
        Timer::init(static::$globalEvent);

        // Set an empty onMessage callback.
        if (empty($this->onMessage)) {
            $this->onMessage = function () {};
        }

        // Try to emit onWorkerStart callback.
        if ($this->onWorkerStart) {
            try {
                call_user_func($this->onWorkerStart, $this);
            } catch (\Exception $e) {
                static::log($e);
                // Avoid rapid infinite loop exit.
                sleep(1);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                // Avoid rapid infinite loop exit.
                sleep(1);
                exit(250);
            }
        }

        // Main loop.worker进程阻塞在这里
        static::$globalEvent->loop();
    }

    /**
     * Stop current worker instance. worker进程退出
     * @return void
     */
    public function stop()
    {
        // Try to emit onWorkerStop callback.
        if ($this->onWorkerStop) {
            try {
                call_user_func($this->onWorkerStop, $this);
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
        // Remove listener for server socket.
        // 关闭监听套接字
        $this->unlisten();
        // Close all connections for the worker.
        // 会发送完缓冲区中的数据才进行关闭
        if (!static::$_gracefulStop) {
            foreach ($this->connections as $connection) {
                $connection->close();
            }
        }
        // Clear callback.
        $this->onMessage = $this->onClose = $this->onError = $this->onBufferDrain = $this->onBufferFull = null;
        // Remove worker instance from static::$_workers.
        unset(static::$_workers[$this->workerId]);
    }

    /**
     * Accept a connection. 接受新的链接(accept) 创建链接实例
     *
     * @param resource $socket
     * @return void
     */
    public function acceptConnection($socket)
    {
        // Accept a connection on server socket.
        $new_socket = @stream_socket_accept($socket, 0, $remote_address);
        // Thundering herd.
        if (!$new_socket) {
            return;
        }

        // TcpConnection.
        $connection                         = new TcpConnection($new_socket, $remote_address);
        $this->connections[$connection->id] = $connection;
        $connection->worker                 = $this;
        $connection->protocol               = $this->protocol;
        $connection->transport              = $this->transport;
        $connection->onMessage              = $this->onMessage;
        $connection->onClose                = $this->onClose;
        $connection->onError                = $this->onError;
        $connection->onBufferDrain          = $this->onBufferDrain;
        $connection->onBufferFull           = $this->onBufferFull;

        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                call_user_func($this->onConnect, $connection);
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return bool
     */
    public function acceptUdpConnection($socket)
    {
        $recv_buffer = stream_socket_recvfrom($socket, static::MAX_UDP_PACKAGE_SIZE, 0, $remote_address);
        if (false === $recv_buffer || empty($remote_address)) {
            return false;
        }
        // UdpConnection.
        $connection           = new UdpConnection($socket, $remote_address);
        $connection->protocol = $this->protocol;
        if ($this->onMessage) {
            if ($this->protocol !== null) {
                /** @var \Workerman\Protocols\ProtocolInterface $parser */
                $parser      = $this->protocol;
                $recv_buffer = $parser::decode($recv_buffer, $connection);
                // Discard bad packets.
                if ($recv_buffer === false)
                    return true;
            }
            ConnectionInterface::$statistics['total_request']++;
            try {
                call_user_func($this->onMessage, $connection, $recv_buffer);
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
        return true;
    }
}
