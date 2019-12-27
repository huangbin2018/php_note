<?php

/**
 * @desc    Redis 应用实践
 * @author  Huangbin <huangbin2018@qq.com>
 * @date    2019-12-24
 */
class RedisDemo
{
    /**
     * Redis 连接实例
     * @var \Redis|null
     */
    protected $redis = null;

    /**
     * Redis 连接配置
     * @var array
     */
    protected $config = [
        'host' => '127.0.0.1',
        'port' => '6379',
        'auth' => '',
        'charset' => 'utf-8',
    ];

    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
        // 连接 Redis
        try {
            $redis = new \Redis();
            $rs = $redis->connect($this->config['host'], $this->config['port']);
            if ($rs != '+PONG') {
                $redis = null;
            }
            if ($this->config['auth'] != '') {
                $redis->auth($this->config['auth']);
            }
        } catch (\Exception $e) {
            $redis = null;
            throw new \Exception('Redis 初始化失败 ' . $e->getMessage());
        }

        $this->redis = $redis;
    }

    public function set($key = '', $value = '', $ttl = 0)
    {
        return $this->redis->set($key, $value, $ttl);
    }

    public function get($key = '')
    {
        return $this->redis->get($key);
    }

    /**
     *
     * 127.0.0.1:6379> scan 0 match key99* count 10  # 找出以 key99 开头 key 列表
     * cursor 0         游标
     * match key99*     key 的正则模式
     * count 10         遍历的 limit hint
     * 一直遍历到返回的 cursor 值为 0 时才是真正的结束
     *
     * @param null $match
     * @param int $limit
     * @return void
     */
    public function scan($match = null, $limit = 0)
    {
        $redisType = [
            Redis::REDIS_STRING => 'string',
            Redis::REDIS_SET => 'set',
            Redis::REDIS_LIST => 'list',
            Redis::REDIS_ZSET => 'zset',
            Redis::REDIS_HASH => 'hash',
            Redis::REDIS_NOT_FOUND => 'other',
        ];
        $iterator = null;
        do {
            $keys = $this->redis->scan($iterator, $match, $limit);
            if ($keys !== false) {
                foreach ($keys as $key) {
                    $type = $this->redis->type($key);
                    printf("key: %s, type: %s\n", $key, ($redisType[$type] ?? ''));
                }
            }
        } while ($iterator > 0);
    }


    // ===================================== 利用 Redis 实现分布锁 START ====================================
    // 场景： 串行执行的业务
    /**
     * 分布式锁实现
     * @param string $key
     * @param int $ttl
     * @return bool|int
     */
    public function lock($key = 'lock_key', $ttl = 60)
    {
        $success = false;
        // 1. 尝试加锁, 加随机数为了标识当前实例
        $random = mt_rand(0, 9999);
        $rs = $this->redis->set($key, $random, ['NX', 'EX' => $ttl]); // NX 表示只有当 $key 对应的 key 值不存在的时候才能 SET 成功
        if (!$rs) {
            return $success;
        }

        // 2. 逻辑处理...
        // todo...
        sleep(5);

        // 3. 释放锁, lua 脚本，如果键值的值等于传入的值，才执行删除key操作(保证原子操作)
        $lua = <<<EOF
            if redis.call("get", KEYS[1]) == ARGV[1] then
                return redis.call("del", KEYS[1])
            else
                return 0
            end
EOF;
        $rs = $this->redis->eval($lua, [$key, $random], 1); // 1 表示定义一个 KEYS， ARGV[1] 为 $random
        // lua 脚本返回值为1说明当前线程加锁被正常手动释放了
        if ($rs == 1) {
            $success = true;
        } else {
            $success = 1;
        }

        return $success;
    }
    // ===================================== 利用 Redis 实现分布锁 END ====================================

    // ===================================== 利用 Redis 有序集合实现延时队列 START ====================================
    // 场景： 异步执行的任务
    /**
     * 延时队列消息生成
     * @param string $queue
     * @param array $data
     * @return int
     */
    public function delayMessage($queue = 'delay-queue', $data = [])
    {
        $queue = $queue ?: 'delay-queue';
        $msg = [
            'id' => uniqid('', true), // 保证 id 值唯一
            'data' => $data ?: []
        ];
        $msgJson = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $retryTs = 5; // 5 秒后重试
        return $this->redis->zAdd($queue, $retryTs, $msgJson);
    }

    /**
     * 延时队列消费
     * @param string $queue
     * @return void
     *
     * <pre>
     * $obj = new RedisDemo();
     * $rs = $obj->delayMessage('', ['dddd']);
     * $obj->loopDelayMessage();
     * </pre>
     */
    public function loopDelayMessage($queue = 'delay-queue')
    {
        $queue = $queue ?: 'delay-queue';
        while (true) {
            $values = $this->redis->zRangeByScore($queue, 0, time(), ['limit' => [0, 1]]); // 最多取 1 条
            if (empty($values)) {
                // 延时队列空的，休息 1s
                usleep(1000);
                continue;
            }

            $msgJson = $values[0]; // 取第一条，也只有一条
            $delCount = $this->redis->zRem($queue, $msgJson); // 从有序集中删除指定的成员
            if ($delCount) {
                // 只有删除成功才说明当前实例抢到任务
                // todo... 处理消息(注意异常处理)
                $msg = json_decode($msgJson, true);
                print_r($msg);
            }
        }
    }
    // ===================================== 利用 Redis 有序集合实现延时队列 End =====================================

    // ===================================== 利用位图实现用户签到功能 START ==========================================
    // 适用场景如签到送积分、签到领取奖励等，大致需求如下：
    // 1.用户使用签到功能
    // 2.用户的签到状态用户的周、月签到记录、次数
    // 3.当天有多少用户签到
    // 4.显示用户某个月的签到次数和首次签到时间
    // 对于用户签到数据，如果每条数据都用K/V的方式存储，当用户量大的时候内存开销是非常大的。而位图(BitMap)是由一组bit位组成的，
    // 每个bit位对应0和1两个状态，虽然内部还是采用String类型存储，但Redis提供了一些指令用于直接操作位图，可以把它看作是一个bit数组，数组的下标就是偏移量。
    // 它的优点是内存开销小、效率高且操作简单，很适合用于签到这类场景。
    //
    // Redis提供了以下几个指令用于操作位图：
    // SETBIT GETBIT BITCOUNT BITPOS BITOP BITFIELD
    // BITCOUNT key [start] [end]  # 计算给定字符串中，被设置为 1 的比特位的数量
    // GETBIT key offset # 对 key 所储存的字符串值，获取指定偏移量上的位(bit)
    // SETBIT key offset value # 对 key 所储存的字符串值，设置或清除指定偏移量上的位(bit)
    //
    // 考虑到每月初需要重置连续签到次数，最简单的方式是按用户每月存一条签到数据（也可以每年存一条数据）。
    // Key的格式为 u:sign:uid:yyyyMM，Value则采用长度为4个字节（32位）的位图（最大月份只有31天）。位图的每一位代表一天的签到，1表示已签，0表示未签。
    // 例如 u:sign:1000:201902 表示 ID=1000 的用户在 2019年2月的签到记录。
    // 也可以用日期作为键值，用户id作为index，例如：u:sign:20190201 表示所有用户在 2019年2月1日的签到记录
    /*
        $obj = new RedisDemo();
        $uid = 1;
        $date = date('Ymd');
        $obj->doSignIn($uid, '20191220'); // 指定日期 20191220 签到
        $signFlag = $obj->checkSignStatus($uid, '20191220'); // 获取指定日期 20191220 签到状态
        $signCount = $obj->getSignCount($uid, '201912'); // 获取指定月份 201912 签到次数
        $signCount = $obj->getContinuousMaxSignCount($uid, '20190220'); // 获取指定日期 20191220 前的连续签到次数
        $maxCount = $obj->getContinuousMaxSignCount($uid, '201902'); // 获取指定月份 201902 的最大连续签到天数
        $firstSignDate = $obj->getFirstSignDate($uid, '201912'); // 获取指定月份 201912 的首次签到日期
        $rs = $obj->getSignMap($uid, '201912'); // 获取 201912 当月每天的签到情况
     */

    /**
     * 创建key
     * @param int $uid
     * @param null $date
     * @return string
     */
    private function buildSignInKey($uid = 0, $date = null)
    {
        if ($date) {
            // 传入年月
            if (preg_match('/^2\d{3}((0[1-9])|(1[0-2]))$/', $date)) {
                $ym = $date;
            } else {
                $ym = date('Ym', strtotime($date));
            }
        } else {
            $ym = date('Ym');
        }

        return 'u:signin:' . $uid . ':' . $ym;
    }

    /**
     * 用户签到
     * @param int $uid 用户ID
     * @param null $date 签到日期 20190201
     * @return bool
     * <code>
     * $obj->doSignIn($uid, '20191220');
     * </code>
     */
    public function doSignIn($uid = 0, $date = null)
    {
        $date = $date ?: date('Ymd');
        $dayOfMonth = date('j', strtotime($date));
        $offset = $dayOfMonth - 1;

        // setBit  会返回设置之前的值
        $setBit = $this->redis->setBit($this->buildSignInKey($uid, $date), $offset, 1);
        if ($setBit == 1) {
            // 已经签到过了。。。
            return false;
        } else {
            return true;
        }
    }

    /**
     * 获取签到状态
     * @param int $uid 用户ID
     * @param null $date 日期 20190201
     * @return bool
     * <code>
     * $signFlag = $obj->checkSignStatus($uid, '20191220');
     * </code>
     */
    public function checkSignStatus($uid = 0, $date = null)
    {
        $date = $date ?: date('Ymd');
        $dayOfMonth = date('j', strtotime($date));
        $offset = $dayOfMonth - 1;
        return $this->redis->getBit($this->buildSignInKey($uid, $date), $offset) ? true : false;
    }

    /**
     * 获取当月签到次数统计
     * @param int $uid 用户ID
     * @param null $date 日期 20190201
     * @return int
     * <code>
     * $signCount = $obj->getSignCount($uid, '201912');
     * </code>
     */
    public function getSignCount($uid = 0, $date = null)
    {
        $date = $date ?: date('Ymd');
        return $this->redis->bitCount($this->buildSignInKey($uid, $date));
    }

    /**
     * 统计连续签到次数
     * @param int $uid
     * @param null $date
     * @return int
     * <code>
     * $signCount = $obj->getContinuousMaxSignCount($uid, '20190220');
     * </code>
     */
    public function getContinuousSignCount($uid = 0, $date = null)
    {
        $date = $date ?: date('Ymd');
        $signKey = $this->buildSignInKey($uid, $date);
        $days = date('d', strtotime($date)); // 当天
        $fieldType = 'u' . $days; // 当月当前天数作为位的数量
        $signCount = 0;
        // bitfield 有三个子指令，分别是 get/set/incrby，它们都可以对指定位片段进行读写，但是最多只能处理 64 个连续的位
        // > bitfield SignInKey get u4 0  # 从第一个位开始取 4 个位，结果是无符号数 (u)
        // > bitfield SignInKey get i5 2  # 从第三个位开始取 5 个位，结果是有符号数 (i)
        // 有符号数是指获取的位数组中第一个位是符号位，剩下的才是值,如果第一位是 1，那就是负数
        // 无符号数表示非负数，没有符号位，获取的位数组全部都是值
        $data = $this->redis->rawCommand('bitfield', $signKey, 'get', $fieldType, 0);
        if (empty($data)) {
            return 0;
        }
        //var_dump($data);
        $longV = $data[0];// 01110001
        // $format = '%0' . (PHP_INT_SIZE * 8) . "b\n";
        // printf('  val1=' . $format, $longV);
        // 取低位连续不为0的个数即为连续签到次数，需考虑当天尚未签到的情况
        for ($i = 0; $i < $days; $i++) {
            // 低位为 0 且非当天说明连续签到中断了
            if ($longV >> 1 << 1 == $longV) {
                if ($i > 0) {
                    break;
                }
            } else {
                $signCount += 1;
            }
            $longV >>= 1;
        }

        return $signCount;
    }

    /**
     * 统计当月连续签到最大天数
     * @param int $uid
     * @param null $date
     * @return int
     * <code>
     * $maxCount = $obj->getContinuousMaxSignCount($uid, '201902');
     * </code>
     */
    public function getContinuousMaxSignCount($uid = 0, $date = null)
    {
        $date = $date ?: date('Ymd');
        $signKey = $this->buildSignInKey($uid, $date);
        $days = date('t', strtotime($date));
        $fieldType = 'u' . $days; // 当月当前天数作为位的数量
        $signCount = [];
        $data = $this->redis->rawCommand('bitfield', $signKey, 'get', $fieldType, 0);
        if (empty($data)) {
            return 0;
        }
        $longV = $data[0];
        $index = 0;
        for ($i = 0; $i < $days; $i++) {
            // 低位为 0 说明连续签到中断了
            if ($longV >> 1 << 1 == $longV) {
                $index++;
            } else {
                if (isset($signCount[$index])) {
                    $signCount[$index] += 1;
                } else {
                    $signCount[$index] = 1;
                }
            }
            $longV >>= 1;
        }

        return max($signCount) ?: 0;
    }

    /**
     * 获取当月首次签到日期
     * @param int $uid
     * @param null $date
     * @return null|string
     * <code>
     * $date = $obj->getFirstSignDate($uid, '201912');
     * </code>
     */
    public function getFirstSignDate($uid = 0, $date = null)
    {
        $date = $date ?: date('Ymd');
        $pos = $this->redis->bitpos($this->buildSignInKey($uid, $date), 1);
        if ($pos < 0) {
            return null;
        }

        return date('Y-m-', strtotime($date)) . ($pos + 1);
    }

    /**
     * 获取当月每天的签到情况
     * @param int $uid
     * @param null $date
     * @return array|int ['key' => flag] key 为日期，如2019-12-26，flag为签到标识，0-未签 1-已签
     * <code>
     * $signMap = $obj->getSignMap($uid, '201912');
     * </code>
     */
    public function getSignMap($uid = 0, $date = null)
    {
        $date = $date ?: date('Ymd');
        $signKey = $this->buildSignInKey($uid, $date);
        $days = date('t', strtotime($date)); // 当天
        $fieldType = 'u' . $days; // 当月当前天数作为位的数量
        $data = $this->redis->rawCommand('bitfield', $signKey, 'get', $fieldType, 0);
        if (empty($data)) {
            return 0;
        }
        $signMap = [];
        $longV = $data[0];
        $ym = date('Y-m-', strtotime($date));
        for ($i = $days; $i > 0; $i--) {
            $d = $i > 9 ? $i : ((string)('0' . $i));
            $mapKey = $ym . $d;
            // 由低位到高位，为0表示未签，为1表示已签(右移再左移后和原值相等说明该位是 0)
            if ($longV >> 1 << 1 == $longV) {
                $signMap[$mapKey] = 0;
            } else {
                $signMap[$mapKey] = 1;
            }
            $longV >>= 1;
        }

        // 排序
        ksort($signMap);

        return $signMap;
    }
    // ===================================== 利用位图实现用户签到功能 END ============================================

    // ===================================== 利用 HyperLogLog 实现 UV 统计功能 Start ======================================
    // 场景：统计页面 UV
    // HyperLogLog 提供不精确的去重计数方案，虽然不精确但是也不是非常不精确，标准误差是 0.81%
    // HyperLogLog 提供了3个指令 pfadd/pfcount/pfmerge，根据字面意义很好理解，一个是增加计数，一个是获取计数。
    // pfadd 用法和 set 集合的 sadd 是一样的，来一个用户 ID，就将用户 ID 塞进去就是。
    // pfcount 和 scard 用法是一样的，直接获取计数值。
    // pfmerge，用于将多个 pf 计数值累加在一起形成一个新的 pf 值
    //
    // 127.0.0.1:6379> pfadd codehole user1 user2
    // (integer) 1
    // 127.0.0.1:6379> pfcount codehole
    // (integer) 2
    // 127.0.0.1:6379> pfadd codehole2 user2
    // (integer) 1
    // 127.0.0.1:6379> pfmerge codehole codehole2 # codehole2 合并到 codehole
    // (integer) OK
    /*
        $obj = new RedisDemo();
        $view = '/index';
        $date = date('Ymd');
        $total = 10000;
        for ($i = 1; $i <= $total; $i++) {
            $user = 'uid_' . $i;
            $rs = $obj->insertUV($user, $view, $date);
        }
        $uv = $obj->getUV($view, $date);
        printf("total: %d, uv: %d", $total, $uv);
        printf(", deviation: %.4f\n", (abs($uv-$total) / $total));
     */
    /**
     * 记录UV
     * @param $user
     * @param string $view
     * @param null $date
     * @return bool
     */
    public function insertUV($user, $view = '', $date = null)
    {
        $view = $view ?: '/home';
        $date = $date ?: date('Ymd');
        $key = 'uv:' . $view . ':' . $date;

        return $this->redis->pfAdd($key, [$user]);
    }

    /**
     * 获取UV
     * @param string $view
     * @param null $date
     * @return int
     */
    public function getUV($view = '', $date = null)
    {
        $view = $view ?: '/home';
        $date = $date ?: date('Ymd');
        $key = 'uv:' . $view . ':' . $date;

        return $this->redis->pfCount($key) ?: 0;
    }

    /**
     * 合并指定日期的两个view UV
     * @param string $viewSource    要合并的view
     * @param string $viewDest      合并到的view
     * @param null $date            日期
     * @return bool
     */
    public function mergeUV($viewSource = '', $viewDest = '', $date = null)
    {
        $viewSource = $viewSource ?: '/home';
        $viewDest = $viewDest ?: '/home';
        $date = $date ?: date('Ymd');
        $keySource = 'uv:' . $viewSource . ':' . $date;
        $keyDest = 'uv:' . $viewDest . ':' . $date;

        return $this->redis->pfMerge($keyDest, [$keySource]);
    }
    // ===================================== 利用 HyperLogLog 实现 UV 统计功能 END ========================================

    // ===================================== 利用布隆过滤器(Bloom Filter) 实现记录去重 Start ===============================
    // 场景： 比如我们在使用新闻客户端看新闻时，它会给我们不停地推荐新的内容，它每次推荐时要去重，去掉那些已经看过的内容。
    // 利用布隆过滤器可以实现新闻客户端推荐系统推送去重问题
    // Redis 官方提供的布隆过滤器到了 Redis 4.0 提供了插件功能之后才正式登场。布隆过滤器作为一个插件加载到 Redis Server 中，给 Redis 提供了强大的布隆去重功能
    //
    public function addBF($uid, $value = '')
    {
        $key = 'u:views:' . $uid;
        if (!$this->redis->exists($key)) {
            // > bf.reserve key error_rate initial_size
            $this->redis->rawCommand('bf.reserve', $key, '0.001', 50000);
        }
        return $this->redis->rawCommand('bf.add', $key, $value);
    }

    public function checkBF($uid, $value = '')
    {
        $key = 'u:views:' . $uid;
        return $this->redis->rawCommand('bf.exists', $key, $value);
    }
    // ===================================== 利用布隆过滤器(Bloom Filter) 实现记录去重 End   ===============================

    // ===================================== 利用 Redis 实现简单限流 Start   ==============================================
    // 应用场景：系统要限定用户的某个行为在指定的时间里只能允许发生 N 次
    // 使用 Redis 的数据结构来实现这个限流的功能
    // # 指定用户 uid 的某个行为 actionKey 在特定的时间内 period 只允许发生一定的次数 maxCount
    // function isActionAllowed($uid, $actionKey, $period, $maxCount) bool;
    // # 调用这个接口 , 一分钟内只允许最多回复 5 个帖子
    // $canReply = isActionAllowed(1, 'reply', 60, 5);
    // if $canReply
    //     doReply();
    // else
    //     throw new ActionThresholdOverflowExException();
    /*
        // code...
        $obj = new RedisDemo();
        $uid = 1;
        $bool = $obj->isActionAllowed($uid, 'action1', 60, 5); // 用户 1 的 action1 行为，在 60s 内只能执行 5 次, 返回 tree 说明可以继续执行
     */

    /**
     * 检测 action 是否允许
     *
     * 实现：
     * 通过 zset 的 score 来圈出时间窗口, 我们只需要保留这个时间窗口，窗口之外的数据都可以砍掉,窗口之内的数据就是统计数
     * 用一个 zset 结构记录用户的行为历史，每一个行为都会作为 zset 中的一个 key 保存下来。
     * 同一个用户同一种行为用一个 zset 记录, 这个 zset 的 value(行为发生记录) 只需要填一个保证唯一性的值即可。
     * @param string    $uid            用户标识
     * @param string    $actionKey      action Key
     * @param int       $period         时间间隔
     * @param int       $maxCount       最大限制数
     * @return bool
     */
    public function isActionAllowed($uid, $actionKey, $period, $maxCount)
    {
        $key = sprintf('histaction:%s:%s', $uid, $actionKey);
        $nowTs = microtime(true) * 1000; // 毫秒时间戳

        // 开启管道模式，代表将操作命令暂时放在管道里
        $pipe = $this->redis->multi(\Redis::PIPELINE);

        // 记录行为
        $pipe->zAdd($key, $nowTs, $nowTs); // # value 和 score 都使用毫秒时间戳

        // 移除时间窗口之前的行为记录，剩下的都是时间窗口内的
        $pipe->zRemRangeByScore($key, 0, ($nowTs - $period * 1000));

        // 获取时间窗口内的行为数量
        $pipe->zCard($key);

        // 设置 zset 过期时间，避免冷用户持续占用内存, 过期时间应该等于时间窗口的长度，再多宽限 1s
        $pipe->expire($key, $period + 1);

        // 开始执行管道里所有命令
        list($aAdd, $zRemove, $zCount, $expire) = $pipe->exec();

        // 比较数量是否超标
        return $zCount <= $maxCount;
    }
    // ===================================== 利用 Redis 实现简单限流 End   ================================================

    // ===================================== 利用 Redis 实现漏斗限流 Start   ==============================================
    // 漏斗限流是最常用的限流方法之一，顾名思义，这个算法的灵感源于漏斗（funnel）的结构
    // 漏斗的剩余空间就代表着当前行为可以持续进行的数量，漏嘴的流水速率代表着系统允许该行为的最大频率
    // Redis 4.0 提供了一个限流 Redis 模块，它叫 redis-cell。该模块也使用了漏斗算法，并提供了原子的限流指令
    // 该模块只有1条指令cl.throttle
    // > cl.throttle uid:reply    15 30 60 1
    //                      ▲     ▲  ▲  ▲  ▲
    //                      |     |  |  |  └───── need 1 quota (可选参数，默认值也是1)
    //                      |     |  └──┴─────── 30 operations / 60 seconds 这是漏水速率
    //                      |     └───────────── 15 capacity 这是漏斗容量
    //                      └─────────────────── key uid:reply
    // > cl.throttle uid:reply 15 30 60
    // 1) (integer) 0   # 0 表示允许，1表示拒绝
    // 2) (integer) 15  # 漏斗容量capacity
    // 3) (integer) 14  # 漏斗剩余空间left_quota
    // 4) (integer) -1  # 如果拒绝了，需要多长时间后再试(漏斗有空间了，单位秒)
    // 5) (integer) 2   # 多长时间后，漏斗完全空出来(left_quota==capacity，单位秒)
    //
    /*
        obj = new RedisDemo();
        $rs = $obj->funnel(1, '/home');
        var_dump($rs);
     */
    /**
     * Redis-Cell 漏斗限流
     * @param string    $uid            用户标识
     * @param string    $action         动作标识
     * @param int       $quota          需要的资源数
     * @param int       $capacity       容量
     * @param int       $operations     operations / seconds = 漏水速率
     * @param int       $seconds        operations / seconds = 漏水速率
     * @return array
     */
    public function funnel($uid, $action = '', $quota = 1, $capacity = 60, $operations = 30, $seconds = 60)
    {
        $leakingRate = $operations / $seconds; // operations / seconds 这是漏水速率
        $key = sprintf('u:%d:%s', $uid, $action);
        // cl.throttle uid:reply 15 30 60
        $res = $this->redis->rawCommand('cl.throttle', $key, $capacity, $operations, $seconds, $quota);
        return [
            'is_allow' => $res[0] == 1 ? false : true,
            'capacity' => $res[1],
            'left_quota' => $res[2],
            'wait_seconds' => $res[3],
        ];
    }
    // ===================================== 利用 Redis 实现漏斗限流 End   ================================================

    // ===================================== Redis GeoHash  Start   ====================================================
    // 应用场景： 附近的人
    // 地图元素的位置数据使用二维的经纬度表示，经度范围 (-180, 180]，纬度范围 (-90, 90]，纬度正负以赤道为界，北正南负，经度正负以本初子午线 (英国格林尼治天文台) 为界，东正西负;
    // 现在，如果要计算「附近的人」，也就是给定一个元素的坐标，然后计算这个坐标附近的其它元素，按照距离进行排序
    // 元素的经纬度坐标使用关系数据库 (元素 id, 经度 x, 纬度 y) 存储(数据表需要在经纬度坐标加上双向复合索引 (x, y)):
    // 通过矩形区域来限定元素的数量，然后对区域内的元素进行全量距离计算再排序。这样可以明显减少计算量。
    // 如何划分矩形区域呢？可以指定一个半径 r，使用一条 SQL 就可以圈出来。当用户对筛出来的结果不满意，那就扩大半径继续筛选
    // select id from positions where x0-r < x < x0+r and y0-r < y < y0+r
    //
    // Redis 实现：
    // GeoHash 算法将二维的经纬度数据映射到一维的整数，这样所有的元素都将在挂载到一条线上，距离靠近的二维坐标映射到一维后的点之间距离也会很接近。当我们想要计算「附近的人时」，首先将目标位置映射到这条线上，然后在这个一维的线上获取附近的点就行了
    // 所有的地图元素坐标都将放置于唯一的方格中,然后对这些方格进行整数编码.编码之后，每个地图元素的坐标都将变成一个整数，通过这个整数可以还原出元素的坐标
    // GeoHash 算法会继续对这个整数做一次 base32 编码 (0-9,a-z 去掉 a,i,l,o 四个字母) 变成一个字符串。
    // 在 Redis 里面，经纬度使用 52 位的整数进行编码，放进了 zset 里面，zset 的 value 是元素的 key，score 是 GeoHash 的 52 位整数值。
    // zset 的 score 虽然是浮点数，但是对于 52 位的整数值，它可以无损存储。
    // 在使用 Redis 进行 Geo 查询时，它的内部结构实际上只是一个 zset(skiplist)。
    // 通过 zset 的 score 排序就可以得到坐标附近的其它元素,通过将 score 还原成坐标值就可以得到元素的原始坐标
    // Redis 提供的 Geo 指令:
    /*
     127.0.0.1:6379> geoadd company 116.48105 39.996794 company1 # 增加 (geo 存储结构上使用的是 zset,删除指令可以直接使用 zrem 指令即可)
     127.0.0.1:6379> geodist company company1 company2 km # geodist 指令可以用来计算两个元素之间的距离，携带集合名称、2 个名称和距离单位( m、km、ml、ft，分别代表米、千米、英里和尺)
     127.0.0.1:6379> geopos company company1 # geopos 指令可以获取集合中任意元素的经纬度坐标，可以一次获取多个
     127.0.0.1:6379> geohash company ireader # geohash 可以获取元素的经纬度编码字符串
     # georadiusbymember 指令是最为关键的指令，它可以用来查询指定元素附近的其它元素
     127.0.0.1:6379> georadiusbymember company ireader 20 km count 3 asc # 范围 20 公里以内最多 3 个元素按距离正排，它不会排除自身
     # 三个可选参数 withcoord withdist withhash 用来携带附加参数
     # withdist 很有用，它可以用来显示距离
     127.0.0.1:6379> georadiusbymember company ireader 20 km withcoord withdist withhash count 3 asc
     # georadius 根据坐标值来查询附近的元素
     127.0.0.1:6379> georadius company 116.514202 39.905409 20 km withdist count 3 asc
    */
    // ===================================== Redis GeoHash  End   ======================================================



}

$obj = new RedisDemo();
for ($i=0; $i< 100; $i++) {
    $obj->set('key_'.$i, $i, 3600);
}
$obj->scan(null, 10);
//var_dump($rs);

die;
/**
 * 单机漏斗算法
 * 每次灌水前都会被调用以触发漏水，给漏斗腾出空间来。能腾出多少空间取决于过去了多久以及流水的速率
 */
class Funnel
{
    /**
     * 漏斗容量
     * @var int
     */
    protected $capacity = 0;

    /**
     * 漏嘴流水速率
     * @var float
     */
    protected $leakingRate = 0.0;

    /**
     * 漏斗剩余空间
     * @var int
     */
    protected $leftQuota = 0;

    /**
     * 上一次漏水时间
     * @var int
     */
    protected $leakingTs = 0;

    protected $isFirst = false;

    public function __construct($capacity, $leakingRate = 0.0)
    {
        $this->capacity = $capacity;
        $this->leakingRate = $leakingRate;
        $this->leftQuota = $capacity;
        $this->leakingTs = 0;
    }

    /**
     * 漏水(腾空间)
     * @return float|int
     */
    public function makeSpace()
    {
        $nowTs = microtime(true);
        $deltaTs = $nowTs - $this->leakingTs; // 距离上一次漏水过去了多久
        $deltaQuota = $deltaTs * $this->leakingRate;  // 又可以腾出不少空间了
        if ($deltaQuota < 0) { // 间隔时间太长，整数数字过大溢出
            $this->leftQuota = $this->capacity;
            $this->leakingTs = $nowTs; // 更新漏水时间
            return $this->leftQuota;
        }

        if ($deltaQuota < 1) {
            // 腾的空间太少，等下次
            return 0;
        }

        $this->leftQuota += $deltaQuota; // 增加剩余空间
        if ($this->leftQuota > $this->capacity) {
            // 剩余空间不得高于容量
            $this->leftQuota = $this->capacity;
        }

        $this->leakingTs = $nowTs; // 更新漏水时间

        return $this->leftQuota;
    }

    /**
     * 灌水
     * @param $quota
     * @return bool
     */
    public function watering($quota)
    {
        $this->makeSpace();
        if ($this->leftQuota >= $quota) {
            // 判断剩余空间是否足够
            $this->leftQuota -= $quota;
            return true;
        }

        return false;
    }
}


function isActionAllowed($uid, $action, $capacity = 20, $leakingRate = 0.5)
{
    $key = sprintf('%s:u:%s', $action, $uid);
    static $funnels = [];
    if (!isset($funnels[$key])) {
        $funnels[$key] = new Funnel($capacity, $leakingRate);
    }

    $quota = 1; // 需要1个quota
    return $funnels[$key]->watering($quota);
}

$capacity = 20;     //  漏斗容量
$leakingRate = 0.5; // 漏嘴流水速率 quota/s
$uid = 1;
$action = 'reply';
$count = rand(5, 100);
for ($i = 1; $i <= $count; $i++) {
    $isAllowed = isActionAllowed($uid, $action, $capacity, $leakingRate);
    printf("index: %d, action: %s \n", $i, ($isAllowed ? 'true' : 'false'));
    sleep(1);
}


