Resque的相关内容
==============
php-resque的设计

在Resque中，一个后台任务被抽象为由三种角色共同完成：
* Job | 任务 ： 一个Job就是一个需要在后台完成的任务，比如本文举例的发送邮件，就可以抽象为一个Job。在Resque中一个Job就是一个Class。
* Queue | 队列 ： 也就是上文的消息队列，在Resque中，队列则是由Redis实现的。Resque还提供了一个简单的队列管理器，可以实现将Job插入/取出队列等功能。
* Worker | 执行者 ： 负责从队列中取出Job并执行，可以以守护进程的方式运行在后台。
那么基于这个划分，一个后台任务在Resque下的基本流程是这样的：

在Resque中，有一个很重要的设计：一个Worker，可以处理一个队列，也可以处理很多个队列，并且可以通过增加Worker的进程/线程数来加快队列的执行速度。

流程如下：
* 将一个后台任务编写为一个独立的Class，这个Class就是一个Job。
* 在需要使用后台程序的地方，系统将Job Class的名称以及所需参数放入队列。
* 以命令行方式开启一个Worker，并通过参数指定Worker所需要处理的队列。
* Worker作为守护进程运行，并且定时检查队列。
* 当队列中有Job时，Worker取出Job并运行，即实例化Job Class并执行Class中的方法。

## php-resque的使用
编写一个Worker
其实php-resque已经给出了简单的例子， demo/job.php文件就是一个最简单的Job：
```php
class PHP_Job
{
    public function perform()
    {
        sleep(120);
        fwrite(STDOUT, 'Hello!');
    }
}
```
这个Job就是在120秒后向STDOUT输出字符Hello!

在Resque的设计中，一个Job必须存在一个perform方法，Worker则会自动运行这个方法。

将Job插入队列
php-resque也给出了最简单的插入队列实现 demo/queue.php：
```php
if(empty($argv[1])) {
    die('Specify the name of a job to add. e.g, php queue.php PHP_Job');
}

require __DIR__ . '/init.php';
date_default_timezone_set('GMT');
Resque::setBackend('127.0.0.1:6379');

$args = array(
    'time' => time(),
    'array' => array(
        'test' => 'test',
    ),
);

$jobId = Resque::enqueue('default', $argv[1], $args, true);
echo "Queued job ".$jobId."\n\n";
```
在这个例子中，queue.php需要以cli方式运行，将cli接收到的第一个参数作为Job名称，插入名为'default'的队列，同时向屏幕输出刚才插入队列的Job Id。在终端输入：
```shell
cd demo
php queue.php PHP_Job
```
结果可以看到屏幕上输出：
```shell
Queued job 52f5abf5344094efc417e7ea8f1aa083
```
即Job已经添加成功。注意这里的Job名称与我们编写的Job Class名称保持一致：PHP_Job
在这个时候连接redis-cli，可以看到有如下三个key：
```shell
1) "resque:job:52f5abf5344094efc417e7ea8f1aa083:status"
2) "resque:queue:default"
3) "resque:queues"
```
分别用如下命令查看其类型：
```shell
type resque:job:52f5abf5344094efc417e7ea8f1aa083:status
type resque:queue:default
type resque:queues
```
其类型分别是：string/list/set
取出resque:job:52f5abf5344094efc417e7ea8f1aa083:status的内容查看：
```shell
get resque:job:52f5abf5344094efc417e7ea8f1aa083:status
```
其内容如下：
```shell
"{\"status\":1,\"updated\":1438095296,\"started\":1438095296}"
```
其中的status表示Job运行状态，updated表示更新时间，started表示开始时间。
这里存放的是job执行状态的信息。
php-resque同样也提供了查看Job运行状态的例子，直接运行：
```shell
php check_status.php 52f5abf5344094efc417e7ea8f1aa083
```
可以看到输出为：
```shell
Tracking status of 52f5abf5344094efc417e7ea8f1aa083. Press [break] to stop. 
Status of 52f5abf5344094efc417e7ea8f1aa083 is: 1
```
我们刚才创建的Job状态为1。在Resque中，一个Job有以下4种状态：

* Resque_Job_Status::STATUS_WAITING = 1; (等待)
* Resque_Job_Status::STATUS_RUNNING = 2; (正在执行)
* Resque_Job_Status::STATUS_FAILED = 3; (失败)
* Resque_Job_Status::STATUS_COMPLETE = 4; (结束)

取出resque:queue:default的内容查看(key中的default是在之前代码中定义的queue的名称)：
```shell
lrange resque:queue:default 0 -1
```
其内容如下：
```shell
1) "{\"class\":\"PHP_Job\",\"args\":[{\"time\":1438095296,\"array\":{\"test\":\"test\"}}],\"id\":\"52f5abf5344094efc417e7ea8f1aa083\"}"
```
其中的class表示Job的类，args表示Job执行时的参数，id表示Job的ID，可以根据这个ID去查询Job执行状态的信息。
这里存放的是每个要执行的Job的相关信息。因为只添加了一个，所以在default的队列中，只有一个值。

取出resque:queues的内容查看：
```shell
smembers resque:queues
```
其内容如下：
```shell
1) "default"
```
这里存放的是所有队列的名称。因为只有一个，所以在queues的集合中，只有一个值。

因为没有Worker运行，所以刚才创建的Job还是等待状态。
运行Worker
这次我们直接编写demo/resque.php：
```php
date_default_timezone_set('GMT');
require 'job.php';
require '../bin/resque';
```
可以看到一个Worker至少需要两部分：

可以直接包含Job类文件，也可以使用php的自动加载机制，指定好Job Class所在路径并能实现自动加载
包含Resque的默认Worker： bin/resque
在终端中运行：
```shell
QUEUE=default php resque.php
```
前面的QUEUE部分是设置环境变量，我们指定当前的Worker只负责处理default队列。也可以使用
```shell
QUEUE=* php resque.php
```
来处理所有队列。

运行后输出为
```shell
#!/usr/bin/env php
*** Starting worker jun-Ubuntu:23437:*
```
用ps指令检查一下：
```shell
ps aux | grep resque
```
可以看到有一个php的守护进程已经在运行了
```shell
jun      23437  1.0  0.3 314148 14884 pts/16   S+   23:23   0:00 php resque.php
```
在这个时候再连接到redis-cli，查看key，可以看到如下key：
```shell
1) "resque:job:52f5abf5344094efc417e7ea8f1aa083:status"
2) "resque:workers"
3) "resque:queues"
4) "resque:worker:jun-Ubuntu:25122:*:started"
5) "resque:worker:jun-Ubuntu:25122:*"
```
分别查看新增的key是什么类型：
```shell
type resque:workers
type resque:worker:jun-Ubuntu:25122:*:started
type resque:worker:jun-Ubuntu:25122:*
```
其类型分别是set/string/string
分别取出其内容，命令就不再写了，请参考之前的内容
resque:workers中的内容如下：
```shell
1) "jun-Ubuntu:25122:*"
```
这里存放的是所有worker的进程ID。因为只有一个，所以在workers的集合中，只有一个值。
resque:worker:jun-Ubuntu:25122:*:started中的内容如下(key中的jun-Ubuntu:25122:*是worker的host+进程ID+queue的名称)：
```shell
"Tue Jul 28 15:29:37 GMT 2015"
```
这里存放的是Job启动的时间。
resque:worker:jun-Ubuntu:25122:*中的内容如下(key中的jun-Ubuntu:25122:*是worker的host+进程ID+queue的名称)：
```shell
"{\"queue\":\"default\",\"run_at\":\"Tue Jul 28 15:29:37 GMT 2015\",\"payload\":{\"class\":\"PHP_Job\",\"args\":[{\"time\":1438097296,\"array\":{\"test\":\"test\"}}],\"id\":\"52f5abf5344094efc417e7ea8f1aa083\"}}"
```
这里存放的是这个worker当前执行的Job的所有信息。
于此同时，resque:job:52f5abf5344094efc417e7ea8f1aa083:status中的内容变为如下内容：
```shell
"{\"status\":2,\"updated\":1438097377}"
```
状态变为2(正在执行)了。
也可以使用之前的检查Job指令
```shell
php check_status.php 52f5abf5344094efc417e7ea8f1aa083
```
2分钟后再连接到redis-cli上去查看key，可以看到如下key：
```shell
1) "resque:job:52f5abf5344094efc417e7ea8f1aa083:status"
2) "resque:workers"
3) "resque:stat:processed"
4) "resque:stat:processed:jun-Ubuntu:25122:*"
5) "resque:queues"
6) "resque:worker:jun-Ubuntu:25122:*:started"
```
其中的resque:stat:processed和resque:stat:processed:jun-Ubuntu:25122:*都是string类型，分别表示所有worker执行job成功的个数和worker为jun-Ubuntu:25122:*的执行job成功的个数。
这个时候再去查看以下resque:job:52f5abf5344094efc417e7ea8f1aa083:status的内容，发现状态已经变为4(结束)了。
也可以使用之前的检查Job指令查看，其结果如下：
```shell
Status of 52f5abf5344094efc417e7ea8f1aa083 is: 4
```
这表示任务已经运行完毕，同时屏幕上应该可以看到输出的Hello!

至此我们已经成功的完成了一个最简单的Resque实例的全部演示，更复杂的情况以及遗留的问题会在下一次的日志中说明。

总结一下Redis中的key对应的内容及其含义如下：
* resque:workers (set) - 存放所有的worker，每一个值都是{worker host}:{进程ID}:{queue的名称}
* resque:queues (set) - 存放所有queue的名称
* resque:queue:default (list) - 保存这个队列中等待执行的Job
* resque:job:52f5abf5344094efc417e7ea8f1aa083:status (string) - 存放job的状态信息
* resque:stat:processed (string) - 保存所有worker执行job成功的个数
* resque:stat:processed:jun-Ubuntu:25122:* (string) - 保存一个worker执行job成功的个数
* resque:worker:jun-Ubuntu:25122:*:started (string) - 保存一个worker的启动时间
* resque:worker:jun-Ubuntu:25122:* (string) - 保存一个worker当前执行的Job的所有信息