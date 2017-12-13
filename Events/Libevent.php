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
namespace Workerman\Events;

use Workerman\Worker;
/*
 *  https://www.ibm.com/developerworks/cn/aix/library/au-libev/index.html
 *  https://www.gitbook.com/book/aceld/libevent/details
 *  https://aceld.gitbooks.io/libevent/content/
 *  libevent参考手册(中文版)
 *  libevent源码深度剖析
 *
 *  http://walkerqt.blog.51cto.com/1310630/946298
 *  所有新创建的事件都处于已初始化和非未决状态，调用 event_add（）可以使其成为未决的
 *  调用 libevent 函数设置事件并且关联到 event_base 之后，事件进入“已初始化（initialized）”状态(调用event_new)。此时可以将事件添加到 event_base 中，这使之进
 *  入“未决（pending）”状态(调用event_add)。在未决状态下，如果触发事件的条件发生（比如说，文件描述符的状态改变，或者超时时间到达），则事件进入“激活（active）”状态，（用户提供的）事
 *  件回调函数将被执行。如果配置为“持久的（persistent）”，事件将保持为未决状态。否则，执行完回调后，事件不再是未决的。删除操作可以让未决事件成为非未决（已初始化）的；
 *  添加操作可以让非未决事件再次成为未决的。
 *
 * 从2.0.1-alpha 版本开始，可以有 [任意多个事件] 因为同样的条件而未决。比如说，可以有两个事件因为某个给定的 fd 已经就绪，可以读取而成为激活的。这种情况下，多个事件回调被执行的次序是不确定的。
 *
 * EV_TIMEOUT   这个标志表示某超时时间流逝后事件成为激活的。构造事件的时候，EV_TIMEOUT 标志是被忽略的：可以在添加事件的时候设置超时(event_add($event, $time_interval))，也可以不设置。超时发生时，回调函数的 what参数将带有这个标志。
 * EV_READ      表示指定的文件描述符已经就绪，可以写入的时候，事件将成为激活的。
 * EV_SIGNAL    用于实现信号检测，请看下面的“构造信号事件”节。
 * EV_PERSIST   表示事件是“持久的”，默认情况下，每当未决事件成为激活的（因为 fd 已经准备好读取或者写入，或者因为超时），事件将在其回调被执行前成为非未决的。如果想让事件再次成为未决的，可以在回调函数中
                再次对其调用 event_add（）。然而，如果设置了 EV_PERSIST 标志，事件就是持久的。这意味着即使其回调被激活，事件还是会保持为未决状态。如果想在回调中让事件成为非未决的，可以对其调用 event_del（）。
 * EV_ET        表示如果底层的 event_base 后端支持边沿触发事件，则事件应该是边沿触发的。这个标志影响 EV_READ 和 EV_WRITE 的语义。
 *
 * 每次执行事件回调的时候，持久事件的超时值会被复位。因此，如果具有EV_READ|EV_PERSIST标志，以及5秒的超时值，则事件将在以下情况下成为激活的：
 *  1.套接字已经准备好被读取的时候
 *  2.从最后一次成为激活的开始，已经逝去5秒
 *
 * 在当前版本的libevent和大多数后端中，每个进程任何时刻只能有一个event_base可以监听信号。如果同时向两个event_base添加信号事件，即使是不同的信号，也只有一个event_base可以取得信号。kqueue后端没有这个限制。
 *
 * event_assign（）的参数必须指向一个未初始化的事件 不要对已经在 event_base 中未决的事件调用 event_assign（），这可能会导致难以诊断的错误
 *
 * bufferevent 在读取或者写入了足够量的数据之后调用用户提供的回调。
 *
 * libevent为什么总通知accept事件(listen之后添加listenId的读事件)?
 *      在服务器accept之前到达的数据应由服务器TCP排队,最大数据量为相应已连接套接字的的接收缓冲区大小(uninx网络编程(第4章)  listenId可读意味着可以通过accept从连接队列中取出连接进行处理
 */

/**
 * libevent eventloop
 */
class Libevent implements EventInterface
{
    /**
     * Event base.
     *
     * @var resource
     */
    protected $_eventBase = null;

    /**
     * All listeners for read/write event. 报存所有连接的读写事件
     *
     * @var array
     */
    protected $_allEvents = array();

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    protected $_eventSignal = array();

    /**
     * All timer event listeners.
     * [func, args, event, flag, time_interval]
     *
     * @var array
     */
    protected $_eventTimer = array();

    /**
     * construct
     */
    public function __construct()
    {
        $this->_eventBase = event_base_new();
    }

    /**
     * {@inheritdoc}
     */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case self::EV_SIGNAL:
                $fd_key                      = (int)$fd;
                $real_flag                   = EV_SIGNAL | EV_PERSIST;
                $this->_eventSignal[$fd_key] = event_new();
                if (!event_set($this->_eventSignal[$fd_key], $fd, $real_flag, $func, null)) {
                    return false;
                }
                if (!event_base_set($this->_eventSignal[$fd_key], $this->_eventBase)) {
                    return false;
                }
                if (!event_add($this->_eventSignal[$fd_key])) {
                    return false;
                }
                return true;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $event    = event_new();
                $timer_id = (int)$event;
                if (!event_set($event, 0, EV_TIMEOUT, array($this, 'timerCallback'), $timer_id)) {
                    return false;
                }

                if (!event_base_set($event, $this->_eventBase)) {
                    return false;
                }

                $time_interval = $fd * 1000000;
                //超时触发
            if (!event_add($event, $time_interval)) {
                    return false;
                }
                $this->_eventTimer[$timer_id] = array($func, (array)$args, $event, $flag, $time_interval);
                return $timer_id;

            default :
                $fd_key    = (int)$fd;
                $real_flag = $flag === self::EV_READ ? EV_READ | EV_PERSIST : EV_WRITE | EV_PERSIST;

                $event = event_new();

                if (!event_set($event, $fd, $real_flag, $func, null)) {
                    return false;
                }

                if (!event_base_set($event, $this->_eventBase)) {
                    return false;
                }

                if (!event_add($event)) {
                    return false;
                }

                $this->_allEvents[$fd_key][$flag] = $event;

                return true;
        }

    }

    /**
     * {@inheritdoc}
     */
    public function del($fd, $flag)
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                if (isset($this->_allEvents[$fd_key][$flag])) {
                    event_del($this->_allEvents[$fd_key][$flag]);
                    unset($this->_allEvents[$fd_key][$flag]);
                }
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                break;
            case  self::EV_SIGNAL:
                $fd_key = (int)$fd;
                if (isset($this->_eventSignal[$fd_key])) {
                    event_del($this->_eventSignal[$fd_key]);
                    unset($this->_eventSignal[$fd_key]);
                }
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                // 这里 fd 为timerid 
                if (isset($this->_eventTimer[$fd])) {
                    event_del($this->_eventTimer[$fd][2]);
                    unset($this->_eventTimer[$fd]);
                }
                break;
        }
        return true;
    }

    /**
     * Timer callback.
     *
     * @param mixed $_null1
     * @param int   $_null2
     * @param mixed $timer_id
     */
    protected function timerCallback($_null1, $_null2, $timer_id)
    {
        //多次触发 再次添加进去
        if ($this->_eventTimer[$timer_id][3] === self::EV_TIMER) {
            event_add($this->_eventTimer[$timer_id][2], $this->_eventTimer[$timer_id][4]);
        }
        try {
            call_user_func_array($this->_eventTimer[$timer_id][0], $this->_eventTimer[$timer_id][1]);
        } catch (\Exception $e) {
            Worker::log($e);
            exit(250);
        } catch (\Error $e) {
            Worker::log($e);
            exit(250);
        }
        //不是多次触发  从event_base_new中删除掉
        if (isset($this->_eventTimer[$timer_id]) && $this->_eventTimer[$timer_id][3] === self::EV_TIMER_ONCE) {
            $this->del($timer_id, self::EV_TIMER_ONCE);
        }
    }

    /**
     * {@inheritdoc}
     * 删掉所有定时器事件
     */
    public function clearAllTimer()
    {
        foreach ($this->_eventTimer as $task_data) {
            event_del($task_data[2]);
        }
        $this->_eventTimer = array();
    }

    /**
     * {@inheritdoc}
     */
    public function loop()
    {
        //默认情况下,运行 event_base 直到其中没有已经注册的事件为止
        event_base_loop($this->_eventBase);
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function destroy()
    {
        foreach ($this->_eventSignal as $event) {
            event_del($event);
        }
    }

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return count($this->_eventTimer);
    }
}

