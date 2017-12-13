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
namespace Workerman\Lib;

use Workerman\Events\EventInterface;
use Exception;
//定时器的信号接收也是由
//Timer::add会触发SIGALRM信号, 开启定时器所以必须先调用Timer::init安装SIGALRM信号处理函数
//worker进程统一使用事件机制处理SIGALRM信号
//master进程使用原始方式,用来处理reload,stop. monitorWorkersForLinux中处理SIGALRM信号(pcntl_signal_dispatch)
//应用代码里不能在master上添加定时事件因为controller里面没有注册信号处理函数,第一次调用Timer::add函数就会产生SIGALRM信号, 没有注册SIGALRM处理函数是,默认是终止当前进程
/**
 * Timer.
 *
 * example:
 * Workerman\Lib\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Timer
{
    /**
     * Tasks that based on ALARM signal.
     * [
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   ..
     * ]
     *
     * @var array
     */
    protected static $_tasks = array();

    /**
     * event
     *
     * @var \Workerman\Events\EventInterface
     */
    protected static $_event = null;

    /**
     * Init. 安装信号处理程序
     *
     * @param \Workerman\Events\EventInterface $event
     * @return void
     */
    public static function init($event = null)
    {
        if ($event) {
            self::$_event = $event;
        } else {
            if (function_exists('pcntl_signal')) { //安装信号处理器 如果没有事件处理机制就一秒钟触发一次SIGALRM信号
                pcntl_signal(SIGALRM, array('\Workerman\Lib\Timer', 'signalHandle'), false);
            }
        }
    }

    /**
     * ALARM signal handler.
     *
     * @return void
     */
    public static function signalHandle()
    {
        if (!self::$_event) {
            pcntl_alarm(1);
            self::tick();
        }
    }

    /**
     * Add a timer.
     *
     * @param int      $time_interval
     * @param callable $func
     * @param mixed    $args
     * @param bool     $persistent
     * @return int/false
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true)
    {
        if ($time_interval <= 0) {
            echo new Exception("bad time_interval");
            return false;
        }

        if (self::$_event) {
            return self::$_event->add($time_interval,
                $persistent ? EventInterface::EV_TIMER : EventInterface::EV_TIMER_ONCE, $func, $args);
        }

        if (!is_callable($func)) {
            echo new Exception("not callable");
            return false;
        }

        //开始触发一秒钟一次的事件
        if (empty(self::$_tasks)) {
            pcntl_alarm(1);
        }

        //未来的执行时间
        $time_now = time();
        $run_time = $time_now + $time_interval;
        if (!isset(self::$_tasks[$run_time])) {
            self::$_tasks[$run_time] = array();
        }
        self::$_tasks[$run_time][] = array($func, (array)$args, $persistent, $time_interval);
        return 1;
    }


    /**
     * Tick.
     *
     * @return void
     */
    public static function tick()
    {
        //如果没有了定时事件就取消信号
        if (empty(self::$_tasks)) {
            pcntl_alarm(0);
            return;
        }

        $time_now = time();
        foreach (self::$_tasks as $run_time => $task_data) {
            if ($time_now >= $run_time) {
                foreach ($task_data as $index => $one_task) {
                    $task_func     = $one_task[0];
                    $task_args     = $one_task[1];
                    $persistent    = $one_task[2];
                    $time_interval = $one_task[3];
                    try {
                        call_user_func_array($task_func, $task_args);
                    } catch (\Exception $e) {
                        echo $e;
                    }
                    //如果是持续的就在此添加进去
                    if ($persistent) {
                        self::add($time_interval, $task_func, $task_args);
                    }
                }
                //触发了就删除
                unset(self::$_tasks[$run_time]);
            }
        }
    }

    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        if (self::$_event) {
            return self::$_event->del($timer_id, EventInterface::EV_TIMER);
        }

        return false;
    }

    /**
     * Remove all timers.
     * pcntl_alarm seconds设置为0,将不会创建alarm信号
     * @return void
     */
    public static function delAll()
    {
        self::$_tasks = array();
        pcntl_alarm(0);
        if (self::$_event) {
            self::$_event->clearAllTimer();
        }
    }
}
