<?php
/**
 * Redisent, a Redis interface for the modest
 * @author Justin Poliey <jdp34@njit.edu>
 * @copyright 2009 Justin Poliey <jdp34@njit.edu>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package Redisent
 */

require_once dirname(__FILE__) . '/Redisent.php';

/**
 * A generalized Redisent interface for a cluster of Redis servers
 */
class RedisentCluster {

	/**
	 * Collection of Redisent objects attached to Redis servers
	 * @var array
	 * @access private
	 */
	private $redisents;

	/**
	 * Aliases of Redisent objects attached to Redis servers, used to route commands to specific servers
	 * @see RedisentCluster::to
	 * @var array
	 * @access private
	 */
	private $aliases;

	/**
	 * Hash ring of Redis server nodes
	 * @var array
	 * @access private
	 */
	private $ring;

	/**
	 * Individual nodes of pointers to Redis servers on the hash ring
	 * @var array
	 * @access private
	 */
	private $nodes;

	/**
	 * Number of replicas of each node to make around the hash ring
	 * 每个redis实例都有128个索引指向
	 * @var integer
	 * @access private
	 */
	private $replicas = 128;

	/**
	 * The commands that are not subject to hashing
	 * @var array
	 * @access private
	 */
	private $dont_hash = array(
		'RANDOMKEY', 'DBSIZE',
		'SELECT',    'MOVE',    'FLUSHDB',  'FLUSHALL',
		'SAVE',      'BGSAVE',  'LASTSAVE', 'SHUTDOWN',
		'INFO',      'MONITOR', 'SLAVEOF'
	);

	/**
	 * Creates a Redisent interface to a cluster of Redis servers
	 * @param array $servers The Redis servers in the cluster. Each server should be in the format array('host' => hostname, 'port' => port)
	 */
	function __construct($servers) {
		$this->ring = array();
		$this->aliases = array();
		foreach ($servers as $alias => $server) {
			$this->redisents[] = new Redisent($server['host'], $server['port']);
			if (is_string($alias)) {
				// 用key作为alias标记redis实例
				$this->aliases[$alias] = $this->redisents[count($this->redisents)-1];
			}
 			for ($replica = 1; $replica <= $this->replicas; $replica++) {
				// crc32 — 计算一个字符串的 crc32 多项式，生成 str 的 32 位循环冗余校验码多项式。这通常用于检查传输的数据是否完整。返回值为int类型。
				$this->ring[crc32($server['host'].':'.$server['port'].'-'.$replica)] = $this->redisents[count($this->redisents)-1];
			}
		}
		// ksort — 对数组按照键名排序
		// 排序类型标记：
		// SORT_REGULAR - 正常比较单元（不改变类型）
		// SORT_NUMERIC - 单元被作为数字来比较
		// SORT_STRING - 单元被作为字符串来比较
		// SORT_LOCALE_STRING - 根据当前的区域（locale）设置来把单元当作字符串比较，可以用 setlocale() 来改变。
		// SORT_NATURAL - 和 natsort() 类似对每个单元以“自然的顺序”对字符串进行排序。 PHP 5.4.0 中新增的。
		// SORT_FLAG_CASE - 能够与 SORT_STRING 或 SORT_NATURAL 合并（OR 位运算），不区分大小写排序字符串。
		ksort($this->ring, SORT_NUMERIC);
		// 将redis实例集合的索引放到$this->nodes中
		$this->nodes = array_keys($this->ring);
	}

	/**
	 * Routes a command to a specific Redis server aliased by {$alias}.
	 * 根据alias去选择redis实例
	 * @param string $alias The alias of the Redis server
	 * @return Redisent The Redisent object attached to the Redis server
	 */
	function to($alias) {
		if (isset($this->aliases[$alias])) {
			return $this->aliases[$alias];
		}
		else {
			throw new Exception("That Redisent alias does not exist");
		}
	}

	/* Execute a Redis command on the cluster */
	function __call($name, $args) {

		/* Pick a server node to send the command to */
		$name = strtoupper($name);
		// 如果不是特殊的命令（在$this->dont_hash的），就选一个redis实例去执行
		if (!in_array($name, $this->dont_hash)) {
			// 根据第一个参数（是数据的key），生成 crc32 多项式，去选择redis实例
			$node = $this->nextNode(crc32($args[0]));
			$redisent = $this->ring[$node];
    	}
    	else {
			// 特殊命令都在第一个中执行
			$redisent = $this->redisents[0];
    	}

		/* Execute the command on the server */
    	return call_user_func_array(array($redisent, $name), $args);
	}

	/**
	 * Routes to the proper server node
	 * 使用二分查找法找到与$needle最相近的索引
	 * @param integer $needle The hash value of the Redis command
	 * @return Redisent The Redisent object associated with the hash
	 */
	private function nextNode($needle) {
		$haystack = $this->nodes;
		while (count($haystack) > 2) {
			// floor — 舍去法取整，返回不大于 value 的最接近的整数，舍去小数部分取整。
			$try = floor(count($haystack) / 2);
			if ($haystack[$try] == $needle) {
				// 找到相同的值就直接返回
				return $needle;
			}
			if ($needle < $haystack[$try]) {
				$haystack = array_slice($haystack, 0, $try + 1);
			}
			if ($needle > $haystack[$try]) {
				$haystack = array_slice($haystack, $try + 1);
			}
		}
		// 找到最近的区间，取上区间，即较小的那一个
		return $haystack[count($haystack)-1];
	}

}