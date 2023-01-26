<?php

class FiberFactory
{
    private static WeakMap $weakMap;

    public static function createFiber(Closure $closure, Routine $routine) : Fiber
    {
        if (!isset(self::$weakMap)) {
            self::$weakMap = new WeakMap();
        }

        $fiber = new Fiber($closure);
        self::$weakMap[$fiber] = $routine;

        return $fiber;
    }

    public static function getRoutine(?Fiber $fiber) : ?Routine
    {
        return $fiber ? self::$weakMap[$fiber] : null;
    }
}

class Routine
{
    private static $i = 0;
    private $rid;
    private $fiber;
    public function __construct(Closure $routine)
    {
        $this->rid = ++self::$i;
        $this->fiber = FiberFactory::createFiber($routine, $this);
        Loop::getInstance()->add($this);
    }

    public function start()
    {
        $this->fiber->start();
        return $this;
    }

    public function resume()
    {
        if (!$this->fiber->isStarted()) {
            $this->fiber->start();
            return ;
        }

        if ($this->fiber->isSuspended()) {
            $this->fiber->resume();
        }
    }

    public static function current() : ?Routine
    {
        if (empty($fiber = Fiber::getCurrent())) {
            throw new BadMethodCallException("Your are not in a routine.");
        }

        return FiberFactory::getRoutine($fiber);
    }

    public function getRoutineId()
    {
        return $this->rid;
    }
}

function go(Closure $closure)
{
    return new Routine($closure);
}

function async_sleep(float $duration): void
{
    $routine = Routine::current();
    Loop::getInstance()->addTimer($duration, function() use ($routine) {
        Loop::getInstance()->add($routine);
    });

    Fiber::suspend();
}

function async_file_get_contents(string $filename) : string|false
{
    $routine = Routine::current();
    $fp = fopen($filename, "r");

    stream_set_blocking($fp, false);

    $callback = function() use ($routine) {
        Loop::getInstance()->add($routine);
    };

    $content = '';
    while (!feof($fp)) {
        Loop::getInstance()->addReadCallback($fp, $callback);
        Fiber::suspend();

        do {
            $data = fread($fp, 1024);
            $content .= $data;
        } while (strlen($data) == 1024);
    }

    return $content;
}

enum LogLevel : string {
    case DEBUG = 'DEBUG';
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}

class Loop
{
    private $loop;
    private EventBase $eventBase;
    private $eventStorage;

    private function __construct()
    {
        $this->log("Creating event base", LogLevel::DEBUG);
        $this->eventBase = new EventBase();
        $this->eventStorage = new SplObjectStorage();
        $this->loop = new SplQueue();
    }

    public function addTimer(float $duration, callable $callback) : int
    {
        $this->log("Adding timer $duration", LogLevel::DEBUG);

        $ev = null;
        $ev = Event::timer($this->eventBase, function() use ($callback, &$ev) {
            $callback();
            $this->eventStorage->detach($ev);
        }, null);

        $ev->add($duration);

        $this->eventStorage->attach($ev);
        return 0;
    }

    public function addReadCallback($fp, $callback): void
    {
        $ev = null;
        $ev = new Event($this->eventBase, $fp, Event::READ, function() use ($callback, &$ev) {
            $callback();
            $this->eventStorage->detach($ev);
        });

        $ev->add();
        $this->eventStorage->attach($ev);
    }

    public function add(Routine $routine): static
    {
        $this->loop->enqueue($routine);
        return $this;
    }

    private function log(string $msg, LogLevel $level)
    {
        echo '[', date('Y-m-d H:i:s'), ']' , " ", "$msg\n";
    }

    private function loop()
    {
        while (true) {
            while ($this->loop->count()) {
                /** @var Routine $fiber */
                $routine = $this->loop->dequeue();
                $routine->resume();

                $this->log("Running #{$routine->getRoutineId()}", LogLevel::DEBUG);
            }

            $this->log("Event Looping", LogLevel::DEBUG);
            $this->eventBase->loop(EventBase::LOOP_ONCE);

            if (!$this->loop->count()) {
                break;
            }
        }
    }

    private static Loop $instance;
    public static function getInstance(): static
    {
        return self::$instance ?? self::$instance = new static();
    }

    public static function launch()
    {
        static::getInstance()->loop();
    }
}

function get_routine_id()
{
    return Routine::current()->getRoutineId();
}
