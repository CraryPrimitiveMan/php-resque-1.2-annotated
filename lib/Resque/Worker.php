<?php
require_once dirname(__FILE__) . '/Stat.php';
require_once dirname(__FILE__) . '/Event.php';
require_once dirname(__FILE__) . '/Job.php';
require_once dirname(__FILE__) . '/Job/DirtyExitException.php';

/**
 * Resque worker that handles checking queues for jobs, fetching them
 * off the queues, running them and handling the result.
 *
 * @package		Resque/Worker
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Worker
{
	const LOG_NONE = 0;
	const LOG_NORMAL = 1;
	const LOG_VERBOSE = 2;

	/**
	 * @var int Current log level of this worker.
	 */
	public $logLevel = 0;

	/**
	 * @var array Array of all associated queues for this worker.
	 */
	private $queues = array();

	/**
	 * @var string The hostname of this worker.
	 */
	private $hostname;

	/**
	 * @var boolean True if on the next iteration, the worker should shutdown.
	 */
	private $shutdown = false;

	/**
	 * @var boolean True if this worker is paused.
	 */
	private $paused = false;

	/**
	 * @var string String identifying this worker.
	 */
	private $id;

	/**
	 * @var Resque_Job Current job, if any, being processed by this worker.
	 */
	private $currentJob = null;

	/**
	 * @var int Process ID of child worker processes.
	 */
	private $child = null;

	/**
	 * Return all workers known to Resque as instantiated instances.
	 * @return array
	 */
	public static function all()
	{
		// SMEMBERS key
		// 返回集合 key 中的所有成员。
		$workers = Resque::redis()->smembers('workers');
		if(!is_array($workers)) {
			$workers = array();
		}

		$instances = array();
		foreach($workers as $workerId) {
			// 分别找出workerId对应的worker实例
			$instances[] = self::find($workerId);
		}
		return $instances;
	}

	/**
	 * Given a worker ID, check if it is registered/valid.
	 *
	 * @param string $workerId ID of the worker.
	 * @return boolean True if the worker exists, false if not.
	 */
	public static function exists($workerId)
	{
		// SISMEMBER key member
		// 判断 member 元素是否集合 key 的成员。
		return (bool)Resque::redis()->sismember('workers', $workerId);
	}

	/**
	 * Given a worker ID, find it and return an instantiated worker class for it.
	 *
	 * @param string $workerId The ID of the worker.
	 * @return Resque_Worker Instance of the worker. False if the worker does not exist.
	 */
	public static function find($workerId)
	{
		// worker不存在或者workerId中不含有：，就直接返回false
	    if(!self::exists($workerId) || false === strpos($workerId, ":")) {
			return false;
		}

		list($hostname, $pid, $queues) = explode(':', $workerId, 3);
		$queues = explode(',', $queues);
		$worker = new self($queues);
		$worker->setId($workerId);
		return $worker;
	}

	/**
	 * Set the ID of this worker to a given ID string.
	 *
	 * @param string $workerId ID for the worker.
	 */
	public function setId($workerId)
	{
		// 设置workerId
		$this->id = $workerId;
	}

	/**
	 * Instantiate a new worker, given a list of queues that it should be working
	 * on. The list of queues should be supplied in the priority that they should
	 * be checked for jobs (first come, first served)
	 *
	 * Passing a single '*' allows the worker to work on all queues in alphabetical
	 * order. You can easily add new queues dynamically and have them worked on using
	 * this method.
	 *
	 * @param string|array $queues String with a single queue name, array with multiple.
	 */
	public function __construct($queues)
	{
		if(!is_array($queues)) {
			$queues = array($queues);
		}

		$this->queues = $queues;
		if(function_exists('gethostname')) {
			$hostname = gethostname();
		}
		else {
			// php_uname — 返回运行 PHP 的系统的有关信息
			// 'a'：此为默认。包含序列 "s n r v m" 里的所有模式。
			// 's'：操作系统名称。例如： FreeBSD。
			// 'n'：主机名。例如： localhost.example.com。
			// 'r'：版本名称，例如： 5.1.2-RELEASE。
			// 'v'：版本信息。操作系统之间有很大的不同。
			// 'm'：机器类型。例如：i386。
			$hostname = php_uname('n');
		}
		$this->hostname = $hostname;
		// worker的ID是host + PHP进程ID + 队列的名字
		$this->id = $this->hostname . ':'.getmypid() . ':' . implode(',', $this->queues);
	}

	/**
	 * The primary loop for a worker which when called on an instance starts
	 * the worker's life cycle.
	 *
	 * Queues are checked every $interval (seconds) for new jobs.
	 *
	 * @param int $interval How often to check for new jobs across the queues.
	 */
	public function work($interval = 5)
	{
		$this->updateProcLine('Starting');
		$this->startup();

		while(true) {
			if($this->shutdown) {
				break;
			}

			// Attempt to find and reserve a job
			$job = false;
			if(!$this->paused) {
				$job = $this->reserve();
			}

			// 如果没有找到job
			if(!$job) {
				// For an interval of 0, break now - helps with unit testing etc
				if($interval == 0) {
					break;
				}
				// If no job was found, we sleep for $interval before continuing and checking again
				$this->log('Sleeping for ' . $interval, true);
				if($this->paused) {
					$this->updateProcLine('Paused');
				}
				else {
					$this->updateProcLine('Waiting for ' . implode(',', $this->queues));
				}
				// usleep — 以指定的微秒数延迟执行
				usleep($interval * 1000000);
				continue;
			}

			$this->log('got ' . $job);
			Resque_Event::trigger('beforeFork', $job);
			$this->workingOn($job);

			// 在父进程中记录子进程的ID
			$this->child = $this->fork();

			// Forked and we're the child. Run the job.
			if ($this->child === 0 || $this->child === false) {
				// 在子进程中
				$status = 'Processing ' . $job->queue . ' since ' . strftime('%F %T');
				$this->updateProcLine($status);
				$this->log($status, self::LOG_VERBOSE);
				$this->perform($job);
				if ($this->child === 0) {
					// 执行完job之后，结束子进程
					exit(0);
				}
			}

			if($this->child > 0) {
				// 在父进程中
				// Parent process, sit and wait
				$status = 'Forked ' . $this->child . ' at ' . strftime('%F %T');
				$this->updateProcLine($status);
				$this->log($status, self::LOG_VERBOSE);

				// Wait until the child process finishes before continuing
				// pcntl_wait — 等待或返回fork的子进程状态
				// wait函数刮起当前进程的执行直到一个子进程退出或接收到一个信号要求中断当前进程或调用一个信号处理函数。
				// 如果一个子进程在调用此函数时已经退出（俗称僵尸进程），此函数立刻返回。子进程使用的所有系统资源将 被释放。
				// 关于wait在您系统上工作的详细规范请查看您系统的wait（2）手册。
				pcntl_wait($status);
				// pcntl_wexitstatus — 返回一个中断的子进程的返回代码
				// 如果子进程的退出状态是0，就表示执行job完成（不管是成功还是失败）
				$exitStatus = pcntl_wexitstatus($status);
				if($exitStatus !== 0) {
					// 返回其他的退出状态，同样认为job执行失败，做相应的处理
					$job->fail(new Resque_Job_DirtyExitException(
						'Job exited with exit code ' . $exitStatus
					));
				}
			}
			// 清空子进程的ID
			$this->child = null;
			$this->doneWorking();
		}

		$this->unregisterWorker();
	}

	/**
	 * Process a single job.
	 *
	 * @param Resque_Job $job The job to be processed.
	 */
	public function perform(Resque_Job $job)
	{
		try {
			Resque_Event::trigger('afterFork', $job);
			// 执行job
			$job->perform();
		}
		catch(Exception $e) {
			$this->log($job . ' failed: ' . $e->getMessage());
			// 执行job失败后的处理
			$job->fail($e);
			return;
		}
		// job的状态更新成complete
		$job->updateStatus(Resque_Job_Status::STATUS_COMPLETE);
		$this->log('done ' . $job);
	}

	/**
	 * Attempt to find a job from the top of one of the queues for this worker.
	 *
	 * @return object|boolean Instance of Resque_Job if a job is found, false if not.
	 */
	public function reserve()
	{
		$queues = $this->queues();
		if(!is_array($queues)) {
			return;
		}
		// 遍历整个所有队列中的内容，找到一个job
		foreach($queues as $queue) {
			$this->log('Checking ' . $queue, self::LOG_VERBOSE);
			$job = Resque_Job::reserve($queue);
			if($job) {
				$this->log('Found job on ' . $queue, self::LOG_VERBOSE);
				return $job;
			}
		}
		// 找不到job，返回false
		return false;
	}

	/**
	 * Return an array containing all of the queues that this worker should use
	 * when searching for jobs.
	 *
	 * If * is found in the list of queues, every queue will be searched in
	 * alphabetic order. (@see $fetch)
	 *
	 * @param boolean $fetch If true, and the queue is set to *, will fetch
	 * all queue names from redis.
	 * @return array Array of associated queues.
	 */
	public function queues($fetch = true)
	{
		if(!in_array('*', $this->queues) || $fetch == false) {
			return $this->queues;
		}

		$queues = Resque::queues();
		sort($queues);
		return $queues;
	}

	/**
	 * Attempt to fork a child process from the parent to run a job in.
	 * fork一个进程去执行job
	 * Return values are those of pcntl_fork().
	 *
	 * @return int -1 if the fork failed, 0 for the forked child, the PID of the child for the parent.
	 */
	private function fork()
	{
		if(!function_exists('pcntl_fork')) {
			return false;
		}

		$pid = pcntl_fork();
		if($pid === -1) {
			throw new RuntimeException('Unable to fork child worker.');
		}

		return $pid;
	}

	/**
	 * Perform necessary actions to start a worker.
	 */
	private function startup()
	{
		// 注册相应信号的处理
		$this->registerSigHandlers();
		$this->pruneDeadWorkers();
		Resque_Event::trigger('beforeFirstFork', $this);
		$this->registerWorker();
	}

	/**
	 * On supported systems (with the PECL proctitle module installed), update
	 * the name of the currently running process to indicate the current state
	 * of a worker.
	 *
	 * @param string $status The updated process title.
	 */
	private function updateProcLine($status)
	{
		if(function_exists('setproctitle')) {
			// setproctitle — 设置PHP进程的标题
			setproctitle('resque-' . Resque::VERSION . ': ' . $status);
		}
	}

	/**
	 * Register signal handlers that a worker should respond to.
	 *
	 * TERM: Shutdown immediately and stop processing jobs.
	 * INT: Shutdown immediately and stop processing jobs.
	 * QUIT: Shutdown after the current job finishes processing.
	 * USR1: Kill the forked child immediately and continue processing jobs.
	 */
	private function registerSigHandlers()
	{
		if(!function_exists('pcntl_signal')) {
			return;
		}
		// Tick（时钟周期）是一个在 declare 代码段中解释器每执行 N 条可计时的低级语句就会发生的事件。
		// N 的值是在 declare 中的 directive 部分用 ticks=N 来指定的。
		// 不是所有语句都可计时。通常条件表达式和参数表达式都不可计时。
		declare(ticks = 1);
		// pcntl_signal — 安装一个信号处理器
		// Signal     Value     Action   Comment
		// ──────────────────────────────────────────────────────────────────────
		// SIGHUP        1       Term    Hangup detected on controlling terminal or death of controlling process
		// SIGINT        2       Term    Interrupt from keyboard
		// SIGQUIT       3       Core    Quit from keyboard
		// SIGILL        4       Core    Illegal Instruction
		// SIGABRT       6       Core    Abort signal from abort(3)
		// SIGFPE        8       Core    Floating point exception
		// SIGKILL       9       Term    Kill signal
		// SIGSEGV      11       Core    Invalid memory reference
		// SIGPIPE      13       Term    Broken pipe: write to pipe with noreaders
		// SIGALRM      14       Term    Timer signal from alarm(2)
		// SIGTERM      15       Term    Termination signal
		// SIGUSR1   30,10,16    Term    User-defined signal 1
		// SIGUSR2   31,12,17    Term    User-defined signal 2
		// SIGCHLD   20,17,18    Ign     Child stopped or terminated
		// SIGCONT   19,18,25    Cont    Continue if stopped
		// SIGSTOP   17,19,23    Stop    Stop process
		// SIGTSTP   18,20,24    Stop    Stop typed at terminal
		// SIGTTIN   21,21,26    Stop    Terminal input for background process
		// SIGTTOU   22,22,27    Stop    Terminal output for background process
		// 更多内容可以用man 7 signal命令查看
		// Ctrl-C送SIGINT信号，默认进程会结束，但是进程自己可以重定义收到这个信号的行为。
		// Ctrl-Z送SIGSTOP信号，进程只是被停止，再送SIGCONT信号，进程继续运行，涉及到命令有jobs，fg，bg。

		// 接收到SIGTERM和SIGINT信号，woker将被标记为关闭并杀死子进程
		pcntl_signal(SIGTERM, array($this, 'shutDownNow'));
		pcntl_signal(SIGINT, array($this, 'shutDownNow'));
		// 接收到SIGQUIT信号，woker将被标记为关闭
		pcntl_signal(SIGQUIT, array($this, 'shutdown'));
		// 接收到SIGUSR1信号，woker将杀死子进程
		pcntl_signal(SIGUSR1, array($this, 'killChild'));
		// 接收到SIGUSR2信号，worker将被标记为暂停
		pcntl_signal(SIGUSR2, array($this, 'pauseProcessing'));
		// 接收到SIGCONT信号，worker将被取消标记标记
		pcntl_signal(SIGCONT, array($this, 'unPauseProcessing'));
		// 接收到SIGPIPE信号，worker将重新连接Redis
		pcntl_signal(SIGPIPE, array($this, 'reestablishRedisConnection'));
		$this->log('Registered signals', self::LOG_VERBOSE);
	}

	/**
	 * Signal handler callback for USR2, pauses processing of new jobs.
	 */
	public function pauseProcessing()
	{
		// 将worker标记为暂停
		$this->log('USR2 received; pausing job processing');
		$this->paused = true;
	}

	/**
	 * Signal handler callback for CONT, resumes worker allowing it to pick
	 * up new jobs.
	 */
	public function unPauseProcessing()
	{
		// 将worker的暂停标记取消
		$this->log('CONT received; resuming job processing');
		$this->paused = false;
	}

	/**
	 * Signal handler for SIGPIPE, in the event the redis connection has gone away.
	 * Attempts to reconnect to redis, or raises an Exception.
	 */
	public function reestablishRedisConnection()
	{
		// 重新连接Redis
		$this->log('SIGPIPE received; attempting to reconnect');
		Resque::redis()->establishConnection();
	}

	/**
	 * Schedule a worker for shutdown. Will finish processing the current job
	 * and when the timeout interval is reached, the worker will shut down.
	 */
	public function shutdown()
	{
		// 标记为关闭
		$this->shutdown = true;
		$this->log('Exiting...');
	}

	/**
	 * Force an immediate shutdown of the worker, killing any child jobs
	 * currently running.
	 */
	public function shutdownNow()
	{
		// 标记为关闭并杀死子进程
		$this->shutdown();
		$this->killChild();
	}

	/**
	 * Kill a forked child job immediately. The job it is processing will not
	 * be completed.
	 */
	public function killChild()
	{
		if(!$this->child) {
			$this->log('No child to kill.', self::LOG_VERBOSE);
			return;
		}

		$this->log('Killing child at ' . $this->child, self::LOG_VERBOSE);
		// exec — 执行一个外部程序
		// 如果提供了 $output 参数， 那么会用命令执行的输出填充此数组， 每行输出填充数组中的一个元素。
		// 数组中的数据不包含行尾的空白字符，例如 \n 字符。 请注意，如果数组中已经包含了部分元素，exec() 函数会在数组末尾追加内容。
		// 如果你不想在数组末尾进行追加， 请在传入 exec() 函数之前 对数组使用 unset() 函数进行重置。
		// 同时提供 $output 和 $returnCode 参数， 命令执行后的返回状态会被写入到此变量。
		if(exec('ps -o pid,state -p ' . $this->child, $output, $returnCode) && $returnCode != 1) {
			$this->log('Killing child at ' . $this->child, self::LOG_VERBOSE);
			// posix_kill — 发送信号到一个进程
			posix_kill($this->child, SIGKILL);
			// 清空子进程ID
			$this->child = null;
		}
		else {
			// 子进程不存在，关闭
			$this->log('Child ' . $this->child . ' not found, restarting.', self::LOG_VERBOSE);
			$this->shutdown();
		}
	}

	/**
	 * Look for any workers which should be running on this server and if
	 * they're not, remove them from Redis.
	 * 移除不在运行的worker
	 *
	 * This is a form of garbage collection to handle cases where the
	 * server may have been killed and the Resque workers did not die gracefully
	 * and therefore leave state information in Redis.
	 */
	public function pruneDeadWorkers()
	{
		// 获取所有的resque worker的pid
		$workerPids = $this->workerPids();
		$workers = self::all();
		foreach($workers as $worker) {
			if (is_object($worker)) {
				list($host, $pid, $queues) = explode(':', (string)$worker, 3);
				if($host != $this->hostname || in_array($pid, $workerPids) || $pid == getmypid()) {
					continue;
				}
				// 如果host不知当前的host或者workerId不在集合中或者workerId不等于当前进程的id，就认为是一个dead worker
				$this->log('Pruning dead worker: ' . (string)$worker, self::LOG_VERBOSE);
				$worker->unregisterWorker();
			}
		}
	}

	/**
	 * Return an array of process IDs for all of the Resque workers currently
	 * running on this machine.
	 *
	 * @return array Array of Resque worker process IDs.
	 */
	public function workerPids()
	{
		$pids = array();
		// 获取所有的resque worker的pid，返回结果如 ['5127 php resque.php']
		exec('ps -A -o pid,command | grep [r]esque', $cmdOutput);
		foreach($cmdOutput as $line) {
			// explode 的最后一个参数限制分割后返回的个数
			list($pids[],) = explode(' ', trim($line), 2);
		}
		return $pids;
	}

	/**
	 * Register this worker in Redis.
	 */
	public function registerWorker()
	{
		// 从worker的集合中移除
		Resque::redis()->sadd('workers', $this);
		// 记录worker开始的时间，格式是：Tue Jul 28 20:39:00 CST 2015
		Resque::redis()->set('worker:' . (string)$this . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));
	}

	/**
	 * Unregister this worker in Redis. (shutdown etc)
	 * 注销掉该worker
	 */
	public function unregisterWorker()
	{
		if(is_object($this->currentJob)) {
			// 如果还有job在，就将其标记为失败
			$this->currentJob->fail(new Resque_Job_DirtyExitException);
		}

		$id = (string)$this;
		// SREM key member [member ...]
		// 移除集合 key 中的一个或多个 member 元素，不存在的 member 元素会被忽略。
		// 当 key 不是集合类型，返回一个错误。
		// 从worker的集合中移除
		Resque::redis()->srem('workers', $id);
		// 删除worker在redis中的存储，包括下面四种：
		// worker:{workerId}/worker:{workerId}:started/stat:processed:{workerId}/stat:failed:{workerId}
		Resque::redis()->del('worker:' . $id);
		Resque::redis()->del('worker:' . $id . ':started');
		Resque_Stat::clear('processed:' . $id);
		Resque_Stat::clear('failed:' . $id);
	}

	/**
	 * Tell Redis which job we're currently working on.
	 *
	 * @param object $job Resque_Job instance containing the job we're working on.
	 */
	public function workingOn(Resque_Job $job)
	{
		$job->worker = $this;
		$this->currentJob = $job;
		// 将job的状态更新为running
		$job->updateStatus(Resque_Job_Status::STATUS_RUNNING);
		$data = json_encode(array(
			'queue' => $job->queue,
			// 格式是：Tue Jul 28 20:39:00 CST 2015
			'run_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
			'payload' => $job->payload
		));
		// 设置worker的数据， worker专程字符串后是该worker的id
		Resque::redis()->set('worker:' . $job->worker, $data);
	}

	/**
	 * Notify Redis that we've finished working on a job, clearing the working
	 * state and incrementing the job stats.
	 */
	public function doneWorking()
	{
		$this->currentJob = null;
		// 执行完的job数目加1
		Resque_Stat::incr('processed');
		Resque_Stat::incr('processed:' . (string)$this);
		// 删除掉worker的状态
		Resque::redis()->del('worker:' . (string)$this);
	}

	/**
	 * Generate a string representation of this worker.
	 *
	 * @return string String identifier for this worker instance.
	 */
	public function __toString()
	{
		return $this->id;
	}

	/**
	 * Output a given log message to STDOUT.
	 *
	 * @param string $message Message to output.
	 */
	public function log($message)
	{
		if($this->logLevel == self::LOG_NORMAL) {
			// 只打印message
			fwrite(STDOUT, "*** " . $message . "\n");
		}
		else if($this->logLevel == self::LOG_VERBOSE) {
			// 打印message和时间
			// strftime — 根据区域设置格式化本地时间／日期
			fwrite(STDOUT, "** [" . strftime('%T %Y-%m-%d') . "] " . $message . "\n");
		}
	}

	/**
	 * Return an object describing the job this worker is currently working on.
	 *
	 * @return object Object with details of current job.
	 */
	public function job()
	{
		$job = Resque::redis()->get('worker:' . $this);
		if(!$job) {
			return array();
		}
		else {
			return json_decode($job, true);
		}
	}

	/**
	 * Get a statistic belonging to this worker.
	 *
	 * @param string $stat Statistic to fetch.
	 * @return int Statistic value.
	 */
	public function getStat($stat)
	{
		return Resque_Stat::get($stat . ':' . $this);
	}
}
?>
