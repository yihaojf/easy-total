<?php
# 是否支持 pthreads 线程模式
define('SUPPORT_THREADS', class_exists('Threaded', false));




class MainWorker
{
    /**
     * 当前进程ID
     *
     * @var int
     */
    public $id = 0;

    /**
     * @var swoole_server
     */
    public $server;

    /**
     * 是否多服务器
     *
     * @var bool
     */
    public $multipleServer = false;

    /**
     * 查询任务列表
     *
     * @var array
     */
    public $queries = [];

    /**
     * 序列设置
     *
     * @var array
     */
    public $series = [];

    /**
     * 保存app相关数据, 每分钟自动清理
     *
     * @var array
     */
    public $jobAppList = [];

    /**
     * 按表分组的序列列表
     *
     * @var array
     */
    public $jobsGroupByTable = [];

    /**
     * 集群服务器列表
     *
     * @var array
     */
    public $clusterServers = [];

    /**
     * ssdb 对象
     *
     * @var redis|RedisCluster
     */
    public $redis;

    /**
     * 是否采用的ssdb
     *
     * @see http://ssdb.io/
     * @var bool
     */
    public $isSSDB = false;

    /**
     * SimpleSSDB 对象
     *
     * @var SimpleSSDB
     */
    public $ssdb;

    /**
     * 推送数据的子进程对象
     *
     * @var swoole_process
     */
    protected $flushProcess;

    /**
     * 子进程ID
     *
     * @var
     */
    protected $flushProcessPID;

    /**
     * 需要刷新的任务数据
     *
     * @var FlushData
     */
    protected $flushData;

    /**
     * 当redis,ssdb等不可写入时程序又需要终止时临时导出的文件路径（确保数据安全）
     *
     * @var string
     */
    protected $dumpFile = '';

    /**
     * 导出的数据内容
     *
     * @var array
     */
    protected $dumpFileData = [];

    /**
     * 记录数据的数组
     *
     * @var array
     */
    protected $buffer = [];

    /**
     * 记录数据的最后时间
     *
     * @var array
     */
    protected $bufferTime = [];

    protected $bufferLen = [];

    /**
     * 是否完成了初始化
     *
     * @var bool
     */
    private $isInit = false;

    protected static $packKey;

    public static $timed;

    public static $serverName;

    public function __construct(swoole_server $server, $id)
    {
        $this->server    = $server;
        $this->id        = $id;
        $this->dumpFile  = (EtServer::$config['server']['dump_path'] ?: '/tmp/') . 'total-dump-'. substr(md5(EtServer::$configFile), 16, 8) . '-'. $id .'.txt';
        $this->flushData = new FlushData();

        # 包数据的key
        self::$packKey  = [
            chr(146).chr(206).chr(85) => 1,
            chr(146).chr(206).chr(86) => 1,
            chr(146).chr(206).chr(87) => 1,
        ];

        self::$timed = time();

        self::$serverName = EtServer::$config['server']['host'].':'. EtServer::$config['server']['port'];

    }

    /**
     * 初始化后会调用
     */
    public function init()
    {
        if ($this->isInit)return true;

        if (!$this->reConnectRedis())
        {
            # 如果没有连上, 则每秒重试一次
            $id = null;
            $id = swoole_timer_tick(1000, function() use (& $id)
            {
                if ($this->reConnectRedis())
                {
                    swoole_timer_clear($id);
                }
            });
            unset($id);
        }

        # 标记成已经初始化过
        $this->isInit = true;

        # 把当前服务器添加到 clusterServers 里
        $this->clusterServers[self::$serverName] = $this->getCurrentServerData() + ['isSelf' => true];

        # 每3秒执行1次
        swoole_timer_tick(3000, function()
        {
            # 更新时间戳
            self::$timed = time();

            # 检查redis
            if (!$this->redis)
            {
                # 重连
                $this->reConnectRedis();
            }

            $this->checkRedis();
        });

        # 刷新间隔时间, 单位毫秒
        $limit = intval(EtServer::$config['server']['merge_time_ms'] ?: 3000);

        if ($this->id > 0)
        {
            # 将每个worker进程的刷新时间平均隔开
            usleep($s = min(1000000, intval(1000 * $limit * $this->id / $this->server->setting['worker_num'])));
        }

        # 推送到task进行数据汇总处理
        swoole_timer_tick($limit, function()
        {
            try
            {
                $this->flush();
            }
            catch (Exception $e)
            {
                # 避免正好在处理数据时redis连接失败抛错导致程序终止, 系统会自动重连
                $this->checkRedis();
            }
        });

        # 读取未处理完的数据
        if (is_file($this->dumpFile))
        {
            foreach (explode("\n", trim(file_get_contents($this->dumpFile))) as $item)
            {
                $tmp = @unserialize($item);
                if ($tmp)
                {
                    $this->dumpFileData[] = $tmp;
                }
            }

            if ($this->dumpFileData)
            {
                $this->flushData = array_shift($this->dumpFileData);
            }

            unlink($this->dumpFile);
        }

        if ($this->redis)
        {
            # 注册服务器
            $this->updateServerStatus();

            # 加载task
            $this->reloadSetting();
        }
        else
        {
            $id = null;
            $id = swoole_timer_tick(3000, function() use (& $id)
            {
                if ($this->redis)
                {
                    $this->updateServerStatus();
                    $this->reloadSetting();

                    # 退出循环
                    swoole_timer_clear($id);
                    unset($id);
                }
            });
            unset($id);
        }

        # 每分钟处理1次
        swoole_timer_tick(60000, function()
        {
            # 清空AppList列表
            $this->jobAppList = [];

            # 更新任务
            $this->updateJob();
        });

        # 每10分钟处理1次
        swoole_timer_tick(1000 * 600, function()
        {
            # 清理老数据
            if ($this->buffer)
            {
                self::$timed = time();
                foreach ($this->buffer as $k => $v)
                {
                    if (self::$timed - $this->bufferTime[$k] > 300)
                    {
                        # 超过5分钟没有更新数据, 则移除
                        info('clear expired data length: '. $this->bufferLen[$k]);

                        unset($this->buffer[$k]);
                        unset($this->bufferTime[$k]);
                        unset($this->bufferLen[$k]);
                    }
                }
            }

            if ($this->redis)
            {
                $this->reloadSetting();

                # 设置内存数
                $this->redis->hSet('server.memory', self::$serverName.'_'.$this->id, serialize([memory_get_usage(true), self::$timed, self::$serverName]));
            }
        });


        # 只有需要第一个进程处理
        if ($this->id == 0)
        {
            # 每3秒通知推送一次
            swoole_timer_tick(3000, function()
            {
                self::$timed = time();

                # 通知 taskWorker 处理, 不占用当前 worker 资源
                for($i = 0; $i < $this->server->setting['task_worker_num']; $i++)
                {
                    $this->server->task('output', $i);
                }
            });

            # 每分钟处理
            swoole_timer_tick(60000, function()
            {
                # 更新服务器信息
                $this->updateServerStatus();
            });

            # 输出到控制台信息
            foreach (FlushData::$queries as $key => $query)
            {
                info("fork sql({$key}): {$query['sql']}");
            }

            # 每小时清理1次
            swoole_timer_tick(3600 * 1000, function()
            {
                $this->server->task('clean');
            });
        }

        return true;
    }


    /**
     * 接受到数据
     *
     * @param swoole_server $server
     * @param $fd
     * @param $fromId
     * @param $data
     * @return bool
     */
    public function onReceive(swoole_server $server, $fd, $fromId, $data)
    {
        if (substr($data, -3) !== "==\n")
        {
            $this->buffer[$fromId]    .= $data;
            $this->bufferTime[$fromId] = self::$timed;
            $this->bufferLen[$fromId] += strlen($data);

            # 支持json格式
            $arr = null;
            if ($this->buffer[$fromId][0] === '[')
            {
                # 尝试完整数据包
                $arr = @json_decode($this->buffer[$fromId], true);
            }
            elseif ($data[0] === '[')
            {
                # 尝试当前的数据
                $arr = @json_decode($data, true);
            }

            if ($arr)
            {
                # 能够解析出json, 直接跳转到处理json的地方
                unset($this->buffer[$fromId]);
                unset($this->bufferTime[$fromId]);
                unset($this->bufferLen[$fromId]);

                goto jsonFormat;
            }
            elseif ($this->bufferLen[$fromId] > 10000000)
            {
                # 超过10MB
                unset($this->buffer[$fromId]);
                unset($this->bufferTime[$fromId]);
                unset($this->bufferLen[$fromId]);

                $server->close($fd);
            }

            return true;
        }
        elseif (isset($this->buffer[$fromId]) && $this->buffer[$fromId])
        {
            # 拼接 buffer
            $data = $this->buffer[$fromId] . $data;

            unset($this->buffer[$fromId]);
            unset($this->bufferTime[$fromId]);
            unset($this->bufferLen[$fromId]);

            debug("accept data length ". strlen($data));
        }

        if ($this->flushData->jobs > 200)
        {
            # 积累的任务太多, 不再接受处理新数据
            return false;
        }

        if ($data[0] === '[')
        {
            # 解析数据
            $arr = @json_decode(rtrim($data, "/=\n\r "), true);

            jsonFormat:
            $msgPack           = false;
            $delayParseRecords = false;
        }
        else
        {
            # msgPack方式解析
            $msgPack           = true;
            $arr               = @msgpack_unpack($data);
            $delayParseRecords = false;

            if (!is_array($arr))
            {
                debug("close client {$fd}.");
                $server->close($fd);
                return false;
            }

            if ($arr && is_array($arr) && !is_array($arr[1]))
            {
                # 标记成需要再解析数据, 暂时不解析
                $delayParseRecords = true;
            }
        }

        if (!$arr || !is_array($arr))
        {
            debug('error data: ' . $data);

            # 把客户端关闭了
            $server->close($fd);
            return false;
        }

        $tag = $arr[0];
        if (!$tag)
        {
            debug('data not found tag: ' . $data);

            # 把客户端关闭了
            $server->close($fd);
            return false;
        }

        if (IS_DEBUG)
        {
            debug("worker: $this->id, tag: $tag, data length: " . strlen($data));
        }

        # example: xd.game.hsqj.consume : $app = hsqj, $table = consume
        # example: consume: $app = '', $table = consume
        list($app, $table) = array_splice(explode('.', $tag), -2);
        if (!$table)
        {
            $table = $app;
            $app   = 'default';
        }

        if (isset($this->jobsGroupByTable[$table]) && $this->jobsGroupByTable[$table])
        {
            # 没有相应tag的任务, 直接跳过
            $haveTask = true;
        }
        else
        {
            $haveTask = false;
        }

        if ($delayParseRecords || is_array($arr[1]))
        {
            # 多条数据
            # [tag, [[time,record], [time,record], ...], option]
            $option  = $arr[2] ?: [];
            $records = $arr[1];
        }
        else
        {
            # 单条数据
            # [tag, time, record, option]
            $option  = $arr[3] ?: [];
            $records = [[$arr[1], $arr[2]]];
        }

        if ($option['chunk'])
        {
            $ackData = ['ack' => $option['chunk']];
            $isSend  = false;
        }
        else
        {
            $ackData = null;
            $isSend  = true;
        }

        if ($haveTask)
        {
            # 有任务需要处理

            if ($delayParseRecords)
            {
                # 解析数据
                $this->parseRecords($records);
            }

            # 说明:
            # 这边的 job 是根据 sql 生成出的数据序列处理任务, 通常情况下是 1个 sql 对应1个序列任务
            # 但2个相同 group by, from 和 where 条件的 sql 则共用一个序列任务, 例如:
            # select count(*) from test group time 1h where id in (1,2,3)
            # 和
            # select sum(value) from test group time 1h where id in (1,2,3) save as test_sum
            # 占用相同序列任务
            #
            # 这样设计的目的是共享相同序列减少数据运算,储存开销

            $jobs = [];
            foreach ($this->jobsGroupByTable[$table] as $key => $job)
            {
                if (!$job['allApp'] && ($job['for'] && !$job['for'][$app]))
                {
                    # 这个任务是为某个APP定制的
                    continue;
                }

                $jobs[$key] = $job;
            }

            if ($jobs)
            {
                try
                {
                    $count = count($records);

                    if (IS_DEBUG)
                    {
                        debug("worker: $this->id, tag: $tag, records count: " . $count);
                    }

                    # 统计用的当前时间的key
                    $dayKey = date('Ymd,H:i');

                    # 记录APP统计的起始时间
                    $appBeginTime = microtime(1);

                    $this->flushData->beginJob();
                    foreach ($jobs as $key => $job)
                    {
                        if (!isset($this->jobAppList[$key][$app]))
                        {
                            # 增加app列表映射, 用于后期数据管理
                            $this->flushData->apps[$key][$app] = $this->jobAppList[$key][$app] = self::$timed;
                        }

                        # 记录当前任务的起始时间
                        $beginTime = microtime(1);
                        foreach ($records as $record)
                        {
                            # 处理数据
                            $this->doJob($job, $app, isset($record[1]['time']) && $record[1]['time'] > 0 ? $record[1]['time'] : $record[0], $record[1]);
                        }

                        # 序列的统计数据
                        $this->flushData->counter[$key][$dayKey]['total'] += $count;
                        $this->flushData->counter[$key][$dayKey]['time']  += 1000000 * (microtime(1) - $beginTime);
                    }

                    # APP的统计数据
                    $this->flushData->counterApp[$app][$dayKey]['total'] += $count;
                    $this->flushData->counterApp[$app][$dayKey]['time']  += 1000000 * (microtime(1) - $appBeginTime);

                    # 标记为更新, 当此值为 true 时系统才会触发推送数据功能
                    $this->flushData->setUpdated(true);
                }
                catch (Exception $e)
                {
                    # 执行中报错, 可能是redis出问题了
                    warn($e->getMessage());

                    # 重置临时数据
                    $this->flushData->restore();

                    # 关闭连接
                    $server->close($fd);

                    # 检查连接
                    $this->checkRedis();

                    return false;
                }
            }
        }
        else
        {
            $jobs = null;
        }

        if ($ackData)
        {
            # ACK 确认
            if ($msgPack)
            {
                $isSend = $server->send($fd, $tmp = msgpack_pack($ackData));
            }
            else
            {
                $isSend = $server->send($fd, $tmp = json_encode($ackData));
            }

            if (IS_DEBUG && !$isSend)
            {
                debug("send ack data fail. fd: $fd, data: $tmp");
            }
        }

        if ($jobs)
        {
            if ($isSend)
            {
                # 发送成功
                # 标记为任务完成
                $this->flushData->endJob();

                # 计数器增加
                $count = count($records);
                if ($count > 0)
                {
                    EtServer::$counter->add($count);
                }
            }
            else
            {
                # 发送失败, 恢复数据
                $this->flushData->restore();
            }
        }

        return true;
    }

    /**
     * @param swoole_server $server
     * @param $fromWorkerId
     * @param $message
     * @return null
     */
    public function onPipeMessage(swoole_server $server, $fromWorkerId, $message)
    {
        switch ($message)
        {
            case 'task.reload':
                # 更新配置
                $this->reloadSetting();
                break;
        }
    }

    public function onFinish($server, $task_id, $data)
    {

    }

    protected function parseRecords(& $recordsData)
    {
        if (!is_array($recordsData))
        {
            # 解析里面的数据
            $tmpArr = [];
            $tmp    = $recordsData[0];
            $length = strlen($recordsData);

            for ($i = 1; $i < $length; $i++)
            {
                $isEnd = $length == $i + 1;
                if ($isEnd || isset(self::$packKey[substr($recordsData, $i, 3)]))
                {
                    if ($isEnd)
                    {
                        $tmp .= $recordsData[$i];
                    }

                    $tmpRecord = @msgpack_unpack($tmp);
                    if (false !== $tmpRecord)
                    {
                        $tmpArr[] = $tmpRecord;

                        # 重置临时字符串
                        $tmp = '';
                    }
                }
                $tmp .= $recordsData[$i];
            }

            $recordsData = $tmpArr;
        }
    }


    protected function reConnectRedis()
    {
        if (EtServer::$config['redis'][0])
        {
            list ($host, $port) = explode(':', EtServer::$config['redis'][0]);
        }
        else
        {
            $host = EtServer::$config['redis']['host'];
            $port = EtServer::$config['redis']['port'];
        }

        try
        {
            if (EtServer::$config['redis']['hosts'] && count(EtServer::$config['redis']['hosts']) > 1)
            {
                if (IS_DEBUG && $this->id == 0)
                {
                    debug('redis hosts: '. implode(', ', EtServer::$config['redis']['hosts']));
                }

                $redis = new RedisCluster(null, EtServer::$config['redis']['hosts']);
            }
            else
            {
                $redis = new redis();

                if (false === $redis->connect($host, $port))
                {
                    throw new Exception('connect redis error');
                }
            }

            $this->redis = $redis;

            if (false === $redis->time(0))
            {
                # 大部分用redis的操作, 部分不兼容的用这个对象来处理
                $this->isSSDB = true;
                require_once __DIR__ . '/SSDB.php';

                $this->ssdb = new SimpleSSDB($host, $port);
            }

            $id = null;
            unset($id);

            FlushData::$redis = $this->redis;
            FlushData::$ssdb  = $this->ssdb;

            return true;
        }
        catch (Exception $e)
        {
            if ($this->id == 0 && time() % 10 == 0)
            {
                debug($e->getMessage());
                info('redis server is not start, wait start redis://' . (EtServer::$config['redis']['hosts'] ? implode(', ', EtServer::$config['redis']['hosts']) : $host .':'. $port));
            }

            return false;
        }
    }

    /**
     * 处理数据
     *
     * @param $queryKey
     * @param $option
     * @param $app
     * @param $table
     * @param $time
     * @param $item
     */
    protected function doJob($option, $app, $time, $item)
    {
        if ($option['where'])
        {
            if (false === self::checkWhere($option['where'], $item))
            {
                # 不符合where条件
                return;
            }
        }

        $key = $option['key'];
        $fun = $option['function'];

        # 分组值
        $groupValue = '';
        if ($option['groupBy'])
        {
            foreach ($option['groupBy'] as $group)
            {
                $groupValue .= '_'. $item[$group];
            }
        }

        # 多序列分组统计数据
        foreach ($option['groupTime'] as $timeOptKey => $timeOpt)
        {
            # Exp: $groupTimeKey = 1M

            if ($timeOptKey === 'none')
            {
                # 不分组
                $timeKey = null;
                $uniqid  = "$key,none,{$app}{$groupValue}";
                $id      = $groupValue ? substr($groupValue, 1) : md5(json_decode($item, JSON_UNESCAPED_UNICODE));
            }
            else
            {
                # 获取时间key, Exp: 20160610123
                $timeKey = getTimeKey($time, $timeOpt[0], $timeOpt[1]);

                # 数据的键, Exp: abcde123af32,1d,hsqj,20160506123_123_abc
                $uniqid  = "$key,$timeOptKey,$app,{$timeKey}{$groupValue}";

                # 数据的ID
                $id      = "{$timeOptKey}_{$timeKey}{$groupValue}";
            }

            if (strlen($uniqid) > 100)
            {
                # 防止key太长
                $uniqid = substr($uniqid, 0, 60) .',hash,' . md5($uniqid);
            }

            # 记录唯一值
            if (isset($fun['dist']))
            {
                foreach ($fun['dist'] as $field => $t)
                {
                    if (true === $t)
                    {
                        # 单字段
                        $k = $item[$field];
                    }
                    else
                    {
                        # 多字段
                        $k = [];
                        foreach ($t as $f)
                        {
                            $k[] = $item[$f];
                        }
                        $k = implode('_', $k);
                    }
                    $this->flushData->setDist("dist,$uniqid,$field", $k);
                }
            }

            # 更新统计数据
            $total = $this->totalData($this->flushData->total[$uniqid], $item, $fun, isset($item['microtime']) && $item['microtime'] ? $item['microtime'] : $time);
            if ($total)
            {
                $this->flushData->setTotal($uniqid, $total);
            }

            # 标记任务
            $this->flushData->setJobs("$key,$timeOptKey", $id, [$uniqid, $time, $timeKey, $app, $item]);
        }

        return;
    }

    /**
     * 退出程序
     */
    public function shutdown()
    {
        if ($this->flushProcess && $this->flushProcessPID)
        {
            # 结束子进程
            if (!$this->flushProcess->write('exit'))
            {
                # 如果通知失败则kill掉它
                swoole_process::kill($this->flushProcessPID, SIGINT);
            }

            # 回收子进程
            while (swoole_process::wait(true));
            $this->flushProcess    = null;
            $this->flushProcessPID = null;
        }

        if (EtServer::$config['server']['flush_at_shutdown'])
        {
            $this->flush();
        }

        $this->dumpData();
    }

    /**
     * 在程序退出时保存数据
     */
    public function dumpData()
    {
        if ($this->dumpFileData)foreach ($this->dumpFileData as $item)
        {
            file_put_contents($this->dumpFile, serialize($item)."\n", FILE_APPEND);
        }

        if ($this->flushData->updated)
        {
            # 有数据
            file_put_contents($this->dumpFile, serialize($this->flushData)."\n", FILE_APPEND);
        }
    }


    /**
     * 更新相关设置
     *
     * @use $this->updateJob()
     * @return bool
     */
    protected function reloadSetting()
    {
        if (!$this->redis)return false;

        # 更新集群服务器列表
        $servers = $this->redis->hGetAll('servers');
        if ($servers)
        {
            foreach ($servers as $key => $item)
            {
                $item = @json_decode($item, true);
                if ($item)
                {
                    if ($key === self::$serverName)
                    {
                        $item['isSelf'] = true;
                    }
                    else
                    {
                        $item['isSelf'] = false;
                    }

                    $servers[$key] = $item;
                }
                else
                {
                    unset($servers[$key]);
                }
            }
        }
        else
        {
            $servers = $this->getCurrentServerData() + ['isSelf' => true];
        }
        $this->clusterServers = $servers;

        # 更新序列设置
        FlushData::$series  = array_map('unserialize', $this->redis->hGetAll('series'));

        # 更新查询任务设置
        FlushData::$queries = array_map('unserialize', $this->redis->hGetAll('queries'));

        # 更新任务
        $this->updateJob();

        return true;
    }

    /**
     * 更新任务
     */
    protected function updateJob()
    {
        $job = [];
        self::$timed = time();

        foreach (FlushData::$queries as $key => $opt)
        {
            if (!$opt['use'])
            {
                if ($this->id == 0)
                {
                    debug("query not use, key: {$opt['key']}, table: {$opt['table']}");
                }
                continue;
            }
            elseif ($opt['deleteTime'] > 0)
            {
                # 已经标记为移除了的任务
                $seriesKey = $opt['seriesKey'];
                if (FlushData::$series[$seriesKey])
                {
                    $k = array_search($key, FlushData::$series[$seriesKey]['queries']);
                    if (false !== $k)
                    {
                        unset(FlushData::$series[$seriesKey]['queries'][$k]);
                        FlushData::$series[$seriesKey]['queries'] = array_values(FlushData::$series[$seriesKey]['queries']);
                    }
                }

                continue;
            }

            # 当前序列的key
            $seriesKey = $opt['seriesKey'];

            if (!FlushData::$series[$seriesKey])
            {
                # 被意外删除? 动态更新序列
                FlushData::$series[$seriesKey] = Manager::createSeriesByQueryOption($opt);

                # 更新服务器的
                $this->redis->hSet('series', $seriesKey, serialize(FlushData::$series[$seriesKey]));
            }

            if (FlushData::$series[$seriesKey]['start'] && FlushData::$series[$seriesKey]['start'] - self::$timed > 60)
            {
                # 还没到还是时间
                continue;
            }

            if (FlushData::$series[$seriesKey]['end'] && self::$timed > FlushData::$series[$seriesKey]['end'])
            {
                # 已经过了结束时间
                continue;
            }

            $job[$opt['table']][$seriesKey] = FlushData::$series[$seriesKey];
        }

        $this->jobsGroupByTable = $job;
    }

    /**
     * 更新服务器状态
     *
     * @return bool
     */
    protected function updateServerStatus()
    {
        if (!$this->redis)return false;

        return $this->redis->hSet('servers', self::$serverName, json_encode($this->getCurrentServerData())) ? true : false;
    }

    /**
     * 获取当前服务器集群数据
     *
     * @return array
     */
    protected function getCurrentServerData()
    {
        return [
            'stats'      => $this->server->stats(),
            'updateTime' => self::$timed,
            'api'        => 'http://'. EtServer::$config['manager']['host'] .':'. EtServer::$config['manager']['port'] .'/api/',
        ];
    }

    /**
     * 刷新数据到redis,ssdb 刷新间隔默认3秒
     *
     * @return bool
     */
    protected function flush()
    {
        if ($this->flushProcess || !$this->redis)return;

        if ($this->flushData->updated)
        {
            try
            {
                # TODO 通过多线程方式推送待测试
//                if (SUPPORT_THREADS)
//                {
//                    # 通过线程来提交
//                    $this->flushByThreads();
//                }
//                else
//                {
                    $time = microtime(1);
                    FlushData::doFlush($this->flushData);
                    debug('do flush use time: '. (microtime(1) - $time) .'s');
//                }
            }
            catch (Exception $e)
            {
                warn($e->getMessage());

                # 如果有错误则检查下
                $this->checkRedis();
            }

            # 目前发现会存在个别进程回收不了的问题, 所以暂时不用
            /*
            $count = count($this->flushData->jobs);
            if ($this->flushData->dist)foreach (array_keys($this->flushData->dist) as $key)
            {
                $count += count($this->flushData->dist[$key]);
            }
            foreach (array_keys($this->flushData->jobs) as $key)
            {
                $count += count($this->flushData->jobs[$key]);
            }

            debug('push job item count: '. $count);

            if ($count < 1000)
            {
                # 如果任务内容很少, 直接处理掉
                $this->doFlush($this->flushData);
            }
            else
            {
                $this->flushBySubProcess();
            }
            */
        }
        elseif ($this->dumpFileData)
        {
            $this->flushData = array_shift($this->dumpFileData);
        }
    }

    /**
     * 通过线程来进行推送数据
     *
     * @return bool
     */
    protected function flushByThreads()
    {
        if (is_array($this->flushData))
        {
            $thread = new FlushData();
            foreach ($this->flushData as $key => $value)
            {
                $thread[$key] = $value;
            }
        }
        elseif (is_object($this->flushData))
        {
            $thread = $this->flushData;
        }
        else
        {
            return false;
        }

        # 启动新线程
        if ($thread->start())
        {
            # 成功执行, 创建一个新的 flushData 对象供接下来的数据处理
            $this->flushData = new FlushData();

            return true;
        }
        else
        {
            # 启动失败, 则按常规方式推送
            return FlushData::doFlush($this->flushData);
        }
    }

    /**
     * 通过子进程推送数据
     *
     * 适用于数据量比较多的情况下避免卡住worker进程继续接受数据
     */
    protected function flushBySubProcess()
    {
        # 如果任务数比较多, 则创建一个子进程去推送数据, 避免阻塞主进程接受数据
        $time    = microtime(1);
        $process = new swoole_process(function(swoole_process $worker)
        {
            if ($this->dumpFileData)
            {
                # 子进程不用处理这个数据, 避免在dump时误导出数据, 所以先清空掉
                $this->dumpFileData = [];
            }

            # 清理没必要的数据
            $this->server     = null;
            $this->buffer     = [];
            $this->bufferTime = [];
            $this->bufferLen  = [];

            $tick = null;

            # 结束程序
            $exit = function() use ($worker, & $tick)
            {
                # 退出循环
                swoole_event_del($worker->pipe);

                # 移除异步获取数据
                if ($tick)swoole_timer_clear($tick);

                # 移除信号监听
                swoole_process::signal(SIGINT, null);

                # 执行导出数据
                $this->dumpData();

                $worker->daemon(true);

                # 退出子进程
                $worker->exit();
            };

            # 监听一个退出信号
            swoole_process::signal(SIGINT, function($signo) use ($exit)
            {
                $exit();
                exit;
            });

            # 接受主进程的消息通知
            swoole_event_add($worker->pipe, function($pipe) use ($worker, $exit)
            {
                if ($worker->read() === 'exit')
                {
                    # 收到一个退出程序的请求
                    $exit();
                }
            });

            # 运行推送
            $run = function()
            {
                try
                {
                    # 重新连接redis, ssdb
                    $this->reConnectRedis();

                    # 刷新数据
                    return FlushData::doFlush($this->flushData);
                }
                catch (Exception $e)
                {
                    warn($e->getMessage());

                    # 如果有错误则检查下
                    $this->checkRedis();
                }

                if (!$this->flushData->updated)
                {
                    return true;
                }
                else
                {
                    return false;
                }
            };

            if ($run())
            {
                # 如果返回true表示任务完成, 通知主进程回收进程
                $worker->write('done');
                $exit();
            }
            else
            {
                # 如果每一次性处理完毕, 放在异步里每秒钟重试一次
                $tick = swoole_timer_tick(1000, function() use ($run, $worker, $exit)
                {
                    if ($run())
                    {
                        # 任务完成
                        $worker->write('done');
                        $exit();
                    }
                });
            }

        }, false);


        # 启动子进程
        if ($pid = $process->start())
        {
            # 收到子进程发来的就绪信息
            # 把主进程的数据清空以便接受新的数据
            $this->flushData       = [];
            $this->flushProcess    = $process;
            $this->flushProcessPID = $pid;

            # 进入异步监听方式
            swoole_event_add($process->pipe, function($pipe) use ($time, $process)
            {
                if ('done' === $process->read())
                {
                    # 执行关闭
                    $process->kill($this->flushProcessPID);

                    # 回收子进程
                    while (swoole_process::wait(true));

                    # 释放变量
                    $this->flushProcess    = null;
                    $this->flushProcessPID = null;

                    # 任务结束, 移除异步监听
                    swoole_event_del($process->pipe);

                    # 记录总耗时
                    $useTime = 1000000 * (microtime(1) - $time);

                    list($k1, $k2) = explode(',', date('Ymd,H:i'));

                    # 记录统计数
                    $this->redis->hIncrBy("counter.allpushtime.$k1", $k2, $useTime);

                    debug("push data with process use {$useTime}ns");
                }
            });
        }
        else
        {
            $this->flushProcess    = null;
            $this->flushProcessPID = null;
            $process->close();
            unset($process);
            while (swoole_process::wait(false));
        }
    }

    /**
     * 检查redis连接, 如果ping不通则将 `$this->redis` 设置成 null
     */
    protected function checkRedis()
    {
        try
        {
            if ($this->redis && false === @$this->redis->ping(0))
            {
                throw new Exception('redis closed');
            }
        }
        catch(Exception $e)
        {
            $this->redis = null;
            $this->ssdb  = null;

            FlushData::$redis = null;
            FlushData::$ssdb  = null;
        }
    }

    protected function totalData($total, $current, $fun, $time)
    {
        if (!$total)$total = [];

        if (isset($fun['sum']))
        {
            # 相加的数值
            foreach ($fun['sum'] as $field => $t)
            {
                $total['sum'][$field] += $current[$field];
            }
        }

        if (isset($fun['count']))
        {
            foreach ($fun['count'] as $field => $t)
            {
                $total['count'][$field] += 1;
            }
        }

        if (isset($fun['last']))
        {
            foreach ($fun['last'] as $field => $t)
            {
                $tmp = $total['last'][$field];

                if (!$tmp || $tmp[1] < $time)
                {
                    $total['last'][$field] = [$current[$field], $time];
                }
            }
        }

        if (isset($fun['first']))
        {
            foreach ($fun['first'] as $field => $t)
            {
                $tmp = $total['first'][$field];

                if (!$tmp || $tmp[1] > $time)
                {
                    $total['first'][$field] = [$current[$field], $time];
                }
            }
        }

        if (isset($fun['min']))
        {
            foreach ($fun['min'] as $field => $t)
            {
                if (isset($total['min'][$field]))
                {
                    $total['min'][$field] = min($total['min'][$field], $current[$field]);
                }
                else
                {
                    $total['min'][$field] = $current[$field];
                }
            }
        }

        if (isset($fun['max']))
        {
            foreach ($fun['max'] as $field => $t)
            {
                if (isset($total['max'][$field]))
                {
                    $total['max'][$field] = max($total['max'][$field], $current[$field]);
                }
                else
                {
                    $total['max'][$field] = $current[$field];
                }
            }
        }

        return $total;
    }

    protected static function checkWhere($opt, $data)
    {
        if (isset($opt['$type']))
        {
            # 当前的类型: && 或 ||
            $type = $opt['$type'];

            foreach ($opt['$item'] as $item)
            {
                if (is_array($item) && isset($item['$type']))
                {
                    # 子分组条件
                    $rs = self::checkWhere($opt, $data);
                }
                else
                {
                    $rs    = false;
                    $isIn  = false;
                    $value = $data[$item['field']];
                    if ($item['typeM'])
                    {
                        switch ($item['typeM'])
                        {
                            case '%':
                            case 'mod':
                                $value = $value % $item['mValue'];
                                break;
                            case '>>';
                                $value = $value >> $item['mValue'];
                                break;
                            case '<<';
                                $value = $value << $item['mValue'];
                                break;
                            case '-';
                                if (is_numeric($item['mValue']))
                                {
                                    $value = $value - $item['mValue'];
                                }
                                else
                                {
                                    $value = $value - $data[$item['mValue']];
                                }
                                break;
                            case '+';
                                if (is_numeric($item['mValue']))
                                {
                                    $value = $value + $item['mValue'];
                                }
                                else
                                {
                                    $value = $value + $data[$item['mValue']];
                                }
                                break;
                            case '*';
                            case 'x';
                                if (is_numeric($item['mValue']))
                                {
                                    $value = $value * $item['mValue'];
                                }
                                else
                                {
                                    $value = $value * $data[$item['mValue']];
                                }
                                break;

                            case '/';
                                if (is_numeric($item['mValue']))
                                {
                                    $value = $value / $item['mValue'];
                                }
                                else
                                {
                                    $value = $value / $data[$item['mValue']];
                                }
                                break;

                            case 'func':
                                switch ($item['fun'])
                                {
                                    case 'from_unixtime':
                                        $value = @date($item['arg'], $value);
                                        break;

                                    case 'unix_timestamp':
                                        $value = @strtotime($value);
                                        break;

                                    case 'in':
                                        $isIn = true;
                                        $rs = in_array($data[$item['field']], $item['arg']);
                                        break;

                                    case 'not_in':
                                        $isIn = true;
                                        $rs = !in_array($data[$item['field']], $item['arg']);
                                        break;

                                    default:
                                        if (is_callable($item['fun']))
                                        {
                                            try
                                            {
                                                $value = @call_user_func($item['fun'], $value, $item['arg']);
                                            }
                                            catch (Exception $e)
                                            {
                                                $value = false;
                                            }
                                        }
                                        break;
                                }
                                break;
                        }
                    }

                    if (!$isIn)
                    {
                        $rs = self::checkWhereEx($value, $item['value'], $item['type']);
                    }
                }

                if ($type === '&&')
                {
                    # 并且的条件, 返回了 false, 则不用再继续判断了
                    if ($rs === false)return false;
                }
                else
                {
                    # 或, 返回成功则不用再判断了
                    if ($rs === true)return true;
                }
            }
        }

        return true;
    }

    protected static function checkWhereEx($v1, $v2, $type)
    {
        switch ($type)
        {
            case '>';
                if ($v1 > $v2)
                {
                    return true;
                }
                break;
            case '<';
                if ($v1 < $v2)
                {
                    return true;
                }
                break;
            case '>=';
                if ($v1 >= $v2)
                {
                    return true;
                }
                break;
            case '<=';
                if ($v1 <= $v2)
                {
                    return true;
                }
                break;
            case '!=';
                if ($v1 != $v2)
                {
                    return true;
                }
                break;
            case '=';
            default :
                if ($v1 == $v2)
                {
                    return true;
                }
                break;
        }

        return false;
    }
}