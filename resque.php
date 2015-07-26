<?php
// getenv — 获取一个环境变量的值
// 从下面的Code中可以看到相应的环境变量有：QUEUE/REDIS_BACKEND/LOGGING/VERBOSE/VVERBOSE/APP_INCLUDE/INTERVAL/COUNT/PIDFILE

// QUEUE表示队列的名称，可以有多个（用逗号分隔），*表示所有
$QUEUE = getenv('QUEUE');
if(empty($QUEUE)) {
	die("Set QUEUE env var containing the list of queues to work.\n");
}

require_once 'lib/Resque.php';
require_once 'lib/Resque/Worker.php';

// REDIS_BACKEND表示Redis的配置
$REDIS_BACKEND = getenv('REDIS_BACKEND');
if(!empty($REDIS_BACKEND)) {
	Resque::setBackend($REDIS_BACKEND);
}

$logLevel = 0;
// LOGGING/VERBOSE/VVERBOSE表示打印log的级别
$LOGGING = getenv('LOGGING');
$VERBOSE = getenv('VERBOSE');
$VVERBOSE = getenv('VVERBOSE');
if(!empty($LOGGING) || !empty($VERBOSE)) {
	// 只打印message
	$logLevel = Resque_Worker::LOG_NORMAL;
}
else if(!empty($VVERBOSE)) {
	// 只打印message和时间
	$logLevel = Resque_Worker::LOG_VERBOSE;
}

// APP_INCLUDE表示需要额外引入的文件
$APP_INCLUDE = getenv('APP_INCLUDE');
if($APP_INCLUDE) {
	if(!file_exists($APP_INCLUDE)) {
		die('APP_INCLUDE ('.$APP_INCLUDE.") does not exist.\n");
	}

	require_once $APP_INCLUDE;
}

$interval = 5;
// INTERVAL表示进程要等待的间隔时间，单位是秒
$INTERVAL = getenv('INTERVAL');
if(!empty($INTERVAL)) {
	$interval = $INTERVAL;
}

$count = 1;
// QUEUE表示要fork的子进程数
$COUNT = getenv('COUNT');
if(!empty($COUNT) && $COUNT > 1) {
	$count = $COUNT;
}

if($count > 1) {
	for($i = 0; $i < $count; ++$i) {
		// pcntl_fork — 在当前进程当前位置产生分支（子进程）
		// fork是创建了一个子进程，父进程和子进程 都从fork的位置开始向下继续执行，不同的是父进程执行过程中，得到的fork返回值为子进程号，而子进程得到的是0。
		$pid = pcntl_fork();
		if($pid == -1) {
			die("Could not fork worker ".$i."\n");
		}
		// Child, start the worker
		else if(!$pid) {
			// 如果是子进程，$pid为0
			$queues = explode(',', $QUEUE);
			$worker = new Resque_Worker($queues);
			$worker->logLevel = $logLevel;
			fwrite(STDOUT, '*** Starting worker '.$worker."\n");
			$worker->work($interval);
			break;
		}
	}
}
// Start a single worker
else {
	$queues = explode(',', $QUEUE);
	$worker = new Resque_Worker($queues);
	$worker->logLevel = $logLevel;
	// PIDFILE表示要存放PHP进程ID的文件
	$PIDFILE = getenv('PIDFILE');
	if ($PIDFILE) {
		// getmypid — 获取 PHP 进程的 ID
		// 将进程的ID写入PIDFILE中
		file_put_contents($PIDFILE, getmypid()) or
			die('Could not write PID information to ' . $PIDFILE);
	}

	fwrite(STDOUT, '*** Starting worker '.$worker."\n");
	$worker->work($interval);
}
?>
