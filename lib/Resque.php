<?php
require_once dirname(__FILE__) . '/Resque/Event.php';
require_once dirname(__FILE__) . '/Resque/Exception.php';

/**
 * Base Resque class.
 *
 * @package		Resque
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque
{
	const VERSION = '1.2';

	/**
	 * @var Resque_Redis Instance of Resque_Redis that talks to redis.
	 */
	public static $redis = null;

	/**
	 * @var mixed Host/port conbination separated by a colon, or a nested
	 * array of server swith host/port pairs
	 * Redis server的配置
	 */
	protected static $redisServer = null;

	/**
	 * @var int ID of Redis database to select.
	 * 当前选择的Redis的数据库
	 */
	protected static $redisDatabase = 0;

	/**
	 * @var int PID of current process. Used to detect changes when forking
	 *  and implement "thread" safety to avoid race conditions.
	 */
	 protected static $pid = null;

	/**
	 * Given a host/port combination separated by a colon, set it as
	 * the redis server that Resque will talk to.
	 *
	 * 设置Redis的相关配置，$server：'127.0.0.1:6379'
	 * @param mixed $server Host/port combination separated by a colon, or
	 *                      a nested array of servers with host/port pairs.
	 * @param int $database
	 */
	public static function setBackend($server, $database = 0)
	{
		self::$redisServer   = $server;
		self::$redisDatabase = $database;
		self::$redis         = null;
	}

	/**
	 * Return an instance of the Resque_Redis class instantiated for Resque.
	 * 获取一个redis的实例
	 * @return Resque_Redis Instance of Resque_Redis.
	 */
	public static function redis()
	{
		// Detect when the PID of the current process has changed (from a fork, etc)
		// and force a reconnect to redis.
		// getmypid — 获取 PHP 进程的 ID
		$pid = getmypid();
		// 如果当前PHP进程的ID与类中定义的不同，清空redis实例，将类中定义的ID改为当前PHP进程的ID
		if (self::$pid !== $pid) {
			self::$redis = null;
			self::$pid   = $pid;
		}

		// redis实例存在，直接返回
		if(!is_null(self::$redis)) {
			return self::$redis;
		}

		// redis实例不存在，取出redis配置，默认为'localhost:6379'
		$server = self::$redisServer;
		if (empty($server)) {
			$server = 'localhost:6379';
		}

		// 如果配置是个array，就会生成多个redis实例
		// 配置项$server是[['host' => hostname0, 'port' => port0], ['host' => hostname1, 'port' => port1]]
		// 也可以是['redis0' => ['host' => hostname0, 'port' => port0], 'redis1' => ['host' => hostname1, 'port' => port1]]
		if(is_array($server)) {
			require_once dirname(__FILE__) . '/Resque/RedisCluster.php';
			self::$redis = new Resque_RedisCluster($server);
		}
		else {
			if (strpos($server, 'unix:') === false) {
				// $server is '127.0.0.1:6379'
				list($host, $port) = explode(':', $server);
			}
			else {
				// $server is 'unix:/tmp/redis.sock'
				// 注：redis 默认没有开启unix socket，需要在/etc/redis/redis.conf中修改
				$host = $server;
				$port = null;
			}
			require_once dirname(__FILE__) . '/Resque/Redis.php';
			self::$redis = new Resque_Redis($host, $port);
		}
		// 选择redis的数据库
		self::$redis->select(self::$redisDatabase);
		return self::$redis;
	}

	/**
	 * Push a job to the end of a specific queue. If the queue does not
	 * exist, then create it as well.
	 * 将一个job添加到一个队列的队尾
	 * @param string $queue The name of the queue to add the job to.
	 * @param array $item Job description as an array to be JSON encoded.
	 */
	public static function push($queue, $item)
	{
		// SADD key member [member ...]
		// 将一个或多个 member 元素加入到集合 key 当中，已经存在于集合的 member 元素将被忽略。假如 key 不存在，则创建一个只包含 member 元素作成员的集合。
		// 'queues'是一个集合（Set）
		self::redis()->sadd('queues', $queue);
		// RPUSH key value [value ...]
		// 将一个或多个值 value 插入到列表 key 的表尾(最右边)。
		// 如果有多个 value 值，那么各个 value 值按从左到右的顺序依次插入到表尾：比如对一个空列表 mylist 执行 RPUSH mylist a b c ，得出的结果列表为 a b c ，等同于执行命令 RPUSH mylist a 、 RPUSH mylist b 、 RPUSH mylist c 。
		// 'queue:' . $queue是一个列表类型（List）
		self::redis()->rpush('queue:' . $queue, json_encode($item));
	}

	/**
	 * Pop an item off the end of the specified queue, decode it and
	 * return it.
	 *
	 * @param string $queue The name of the queue to fetch an item from.
	 * @return array Decoded item from the queue.
	 */
	public static function pop($queue)
	{
		// LPOP key
		// 移除并返回列表 key 的头元素。
		$item = self::redis()->lpop('queue:' . $queue);
		if(!$item) {
			return;
		}

		return json_decode($item, true);
	}

	/**
	 * Return the size (number of pending jobs) of the specified queue.
	 *
	 * @param $queue name of the queue to be checked for pending jobs
	 *
	 * @return int The size of the queue.
	 */
	public static function size($queue)
	{
		return self::redis()->llen('queue:' . $queue);
	}

	/**
	 * Create a new job and save it to the specified queue.
	 * 创建一个Job并将它放入到队列中
	 *
	 * @param string $queue The name of the queue to place the job in.
	 * @param string $class The name of the class that contains the code to execute the job.
	 * @param array $args Any optional arguments that should be passed when the job is executed.
	 * @param boolean $trackStatus Set to true to be able to monitor the status of a job.
	 *
	 * @return string
	 */
	public static function enqueue($queue, $class, $args = null, $trackStatus = false)
	{
		require_once dirname(__FILE__) . '/Resque/Job.php';
		// 创建job
		$result = Resque_Job::create($queue, $class, $args, $trackStatus);
		if ($result) {
			// 触发入队的事件
			Resque_Event::trigger('afterEnqueue', array(
				'class' => $class,
				'args'  => $args,
				'queue' => $queue,
			));
		}

		return $result;
	}

	/**
	 * Reserve and return the next available job in the specified queue.
	 *
	 * @param string $queue Queue to fetch next available job from.
	 * @return Resque_Job Instance of Resque_Job to be processed, false if none or error.
	 */
	public static function reserve($queue)
	{
		require_once dirname(__FILE__) . '/Resque/Job.php';
		return Resque_Job::reserve($queue);
	}

	/**
	 * Get an array of all known queues.
	 *
	 * @return array Array of queues.
	 */
	public static function queues()
	{
		$queues = self::redis()->smembers('queues');
		if(!is_array($queues)) {
			$queues = array();
		}
		return $queues;
	}
}
