<?php
class ClassRegistry
{
    public static $attribute;
    public static function init($name) { return $name === 'Job' ? new Job() : self::$attribute; }
}
class AppShell
{
    public $Job;
    public $MispAttribute;
    public $args = [];
    public $params = [];
    public $output = [];
    public function out($message) { $this->output[] = $message; }
    public function error($message) { throw new RuntimeException($message); }
    protected function json($data) { return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR); }
    protected function getBackgroundJobsTool() { return $this->Job->getBackgroundJobsTool(); }
}
class Job
{
    const WORKER_DEFAULT = 'default';
    public $tool;
    public $success;
    public $progress = 0;
    public function __construct() { $this->tool = new BackgroundJobsTool(); }
    public function createJob(...$args) { return 5; }
    public function saveProgress(...$args)
    {
        if (++$this->progress > 50) { throw new LogicException('The IOC index loop did not stop.'); }
    }
    public function saveStatus($jobId, $success, $message = null) { $this->success = $success; }
    public function getBackgroundJobsTool() { return $this->tool; }
}
class BackgroundJobsTool
{
    const DEFAULT_QUEUE = 'default';
    const CMD_ADMIN = 'admin';
    public $queued = [];
    public function enqueue(...$args) { $this->queued[] = $args; }
}
class FastLookupFilter extends FastLookupLifecycleFilter
{
    const MEMORY_LIMIT_CONFIG = 'bf.bloom-memory-usage-limit';
    private static $shared;
    public static function estimatedFilterBytes(int $capacity, float $rate): float
    {
        return max(1, $capacity) * -log($rate) / (log(2) ** 2) / 8;
    }
    public static function recommendedMemoryLimit(int $capacity, float $rate): int
    {
        return max(134217728, (int)ceil(self::estimatedFilterBytes($capacity, $rate) / 0.9 / 1048576) * 1048576);
    }
    public function __construct(...$args)
    {
        if (self::$shared === null) { self::$shared = ['meta' => [], 'generations' => [], 'lease' => null, 'available' => true, 'refuse' => false, 'limit' => null, 'sets' => []]; }
        $this->memoryLimit =& self::$shared['limit'];
        $this->memoryLimitSets =& self::$shared['sets'];
        $this->available =& self::$shared['available'];
        $this->refuseLeaseWrites =& self::$shared['refuse'];
        $this->meta =& self::$shared['meta'];
        $this->generations =& self::$shared['generations'];
        $this->lease =& self::$shared['lease'];
    }
}
