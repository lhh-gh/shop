# 高并发场景与 MySQL-Redis 数据一致性设计

> 本文档梳理商城系统中所有高并发场景及解决方案，以及 MySQL 与 Redis 缓存层之间的数据不一致问题与应对策略。

---

## 第一部分：高并发场景全景

### 1.1 商城系统高并发场景分级

```
┌────────────────────────────────────────────────────────────────────────┐
│                    高并发场景按危险等级分级                               │
│                                                                        │
│  🔴 致命级（数据错误 = 资金损失）                                        │
│  ├── 场景 A：库存超卖（100 人抢最后 1 件商品）                           │
│  ├── 场景 B：优惠券超领（1000 张券被 1200 人领走）                       │
│  ├── 场景 C：支付回调重复处理（同一笔支付扣款两次 / 发两次货）             │
│  └── 场景 D：订单重复创建（用户双击"提交订单"创建两个订单）               │
│                                                                        │
│  🟡 严重级（体验差 / 逻辑错误）                                         │
│  ├── 场景 E：Token 并发刷新（5 个请求同时 401，同时刷新 Token）           │
│  ├── 场景 F：购物车并发操作（多端同时操作同一购物车）                     │
│  └── 场景 G：默认地址并发切换（两个请求同时设默认 → 出现两个默认地址）     │
│                                                                        │
│  🟢 一般级（性能下降但不出错）                                           │
│  ├── 场景 H：热门商品详情页高频访问                                      │
│  ├── 场景 I：商品搜索高频请求                                           │
│  └── 场景 J：分类列表频繁读取                                           │
│                                                                        │
└────────────────────────────────────────────────────────────────────────┘
```

---

### 1.2 场景 A：库存超卖（致命）

#### 问题描述

```
时间轴：

T1: 库存 stock = 1
T2: 用户 A 读取 stock = 1 → 判断 stock >= 1 → 准备扣减
T3: 用户 B 读取 stock = 1 → 判断 stock >= 1 → 准备扣减
T4: 用户 A 执行 UPDATE stock = stock - 1 → stock = 0 ✓
T5: 用户 B 执行 UPDATE stock = stock - 1 → stock = -1 ✗ 超卖！

原因：先读后写，读和写之间有时间窗口，其他线程可以插入
```

#### 解决方案：数据库乐观锁（WHERE 条件原子扣减）

```sql
-- ✅ 正确：原子操作，读+判断+扣减在同一条 SQL 中完成
UPDATE product_skus
SET stock = stock - :qty
WHERE id = :sku_id AND stock >= :qty;

-- 检查影响行数
-- affected_rows = 0 → 库存不足，拒绝下单
-- affected_rows = 1 → 扣减成功
```

**为什么这能防超卖：**

```
T1: stock = 1
T2: 用户 A: UPDATE stock = stock - 1 WHERE stock >= 1
    MySQL InnoDB 行锁：锁住 id=X 这一行
    stock 从 1 变为 0，affected_rows = 1 ✓
    释放行锁

T3: 用户 B: UPDATE stock = stock - 1 WHERE stock >= 1
    MySQL InnoDB 行锁：锁住 id=X 这一行
    当前 stock = 0，WHERE stock >= 1 不满足
    affected_rows = 0 → 库存不足
    释放行锁

不可能超卖：因为 UPDATE 是原子操作，WHERE 条件在加锁后判断
```

**完整代码：**

```php
// OrderService::deductStock()
private function deductStock(array $items): void
{
    foreach ($items as $item) {
        $affected = DB::table('product_skus')
            ->where('id', $item['sku_id'])
            ->where('stock', '>=', $item['qty'])
            ->update([
                'stock' => DB::raw("stock - {$item['qty']}"),
            ]);

        if ($affected === 0) {
            // 有一个 SKU 库存不足，整个事务回滚
            throw new InsufficientStockException(
                "商品 {$item['title']} 库存不足"
            );
        }
    }
}
```

#### 进阶：高并发秒杀场景（Redis 预扣库存）

如果是秒杀活动（瞬间几千请求抢同一个 SKU），所有请求都打到 MySQL 的同一行会造成行锁排队。此时需要 Redis 前置过滤：

```
┌──────────────────────────────────────────────────────────────────┐
│              秒杀场景：Redis 预扣库存 + MySQL 最终扣减              │
│                                                                  │
│  准备阶段（秒杀开始前）：                                          │
│  SET seckill_stock:{sku_id} 100   ← 将库存预热到 Redis           │
│                                                                  │
│  请求到达（每秒 5000 个）：                                        │
│                                                                  │
│  ┌─────────┐    ┌──────────┐    ┌──────────────┐    ┌────────┐  │
│  │ 请求     │───→│ Redis    │───→│ 队列         │───→│ MySQL  │  │
│  │ 5000/s  │    │ DECR     │    │ 100 个任务   │    │ 100行  │  │
│  └─────────┘    │ 原子-1   │    │ 异步扣库存    │    │ UPDATE │  │
│                 │          │    └──────────────┘    └────────┘  │
│                 │ >0 放行  │                                     │
│                 │ ≤0 拒绝  │  ← 4900 个请求在 Redis 层就被拒绝     │
│                 │ (不碰MySQL)│    只有 100 个请求到达 MySQL           │
│                 └──────────┘                                     │
└──────────────────────────────────────────────────────────────────┘
```

**Redis 预扣是原子操作：**

```php
// SeckillService::tryDeductStock()
public function tryDeductStock(int $skuId, int $qty): bool
{
    $key = "seckill_stock:{$skuId}";

    // DECR 是原子操作，不存在并发问题
    $remaining = Redis::decrBy($key, $qty);

    if ($remaining < 0) {
        // 库存不足，回滚 Redis 计数
        Redis::incrBy($key, $qty);
        return false;
    }

    return true; // Redis 预扣成功，后续异步扣 MySQL
}
```

**更安全的 Lua 脚本版本（DECR 和判断合为原子）：**

```lua
-- seckill_deduct.lua
-- KEYS[1] = seckill_stock:{sku_id}
-- ARGV[1] = qty

local stock = redis.call('GET', KEYS[1])
if not stock then
    return -1  -- key 不存在
end

stock = tonumber(stock)
local qty = tonumber(ARGV[1])

if stock < qty then
    return -2  -- 库存不足
end

redis.call('DECRBY', KEYS[1], qty)
return stock - qty  -- 返回剩余库存
```

```php
// PHP 调用 Lua 脚本
$remaining = Redis::eval(
    file_get_contents(resource_path('lua/seckill_deduct.lua')),
    1,                           // KEYS 数量
    "seckill_stock:{$skuId}",   // KEYS[1]
    $qty                         // ARGV[1]
);

match (true) {
    $remaining === -1 => throw new SeckillNotStartedException(),
    $remaining === -2 => throw new InsufficientStockException(),
    default => // 预扣成功，分发队列任务扣 MySQL
};
```

**Redis 库存与 MySQL 库存不一致怎么办？**

```
场景：Redis 扣了但 MySQL 扣失败（如数据库宕机）

解决：
1. 队列任务带重试机制（MySQL 扣减失败时自动重试 3 次）
2. 最终失败：回滚 Redis（INCRBY 回补），标记订单失败
3. 定时对账：每分钟对比 Redis 库存与 MySQL 库存，不一致则告警
4. 兜底：MySQL 的 WHERE stock >= qty 是最终防线
   即使 Redis 多放了请求进来，MySQL 也不会超卖
```

---

### 1.3 场景 B：优惠券超领（致命）

#### 问题描述

```
优惠券总量 total_count = 1000，已领 claimed_count = 999

T1: 用户 A 读取 claimed_count = 999 → 999 < 1000 → 可以领
T2: 用户 B 读取 claimed_count = 999 → 999 < 1000 → 可以领
T3: 用户 A: UPDATE claimed_count = 1000 ✓
T4: 用户 B: UPDATE claimed_count = 1001 ✗ 超领！
```

#### 解决方案：与库存扣减相同 — WHERE 条件原子更新

```sql
UPDATE coupons
SET claimed_count = claimed_count + 1
WHERE id = :coupon_id
  AND claimed_count < total_count;

-- affected_rows = 0 → 已领完
-- affected_rows = 1 → 领取成功
```

**完整代码：**

```php
// CouponService::claim()
public function claim(int $userId, int $couponId): UserCoupon
{
    $coupon = $this->couponRepo->findOrFail($couponId);

    // 校验：启用、在领取时间内
    $this->validateClaimable($coupon);

    // 校验：用户是否已领过（业务规则：每人限领 1 张）
    $exists = UserCoupon::where('user_id', $userId)
        ->where('coupon_id', $couponId)
        ->exists();
    if ($exists) {
        throw new CouponAlreadyClaimedException();
    }

    // 原子扣减：防止超领
    $affected = DB::table('coupons')
        ->where('id', $couponId)
        ->where('claimed_count', '<', DB::raw('total_count'))
        ->update(['claimed_count' => DB::raw('claimed_count + 1')]);

    if ($affected === 0) {
        throw new CouponSoldOutException();
    }

    // 创建用户优惠券记录
    return UserCoupon::create([
        'user_id'    => $userId,
        'coupon_id'  => $couponId,
        'status'     => 'unused',
        'expires_at' => now()->addDays($coupon->valid_days),
    ]);
}
```

> 注意："每人限领 1 张"的校验存在并发窗口（check-then-insert），兜底方案是给 `user_coupons` 表加唯一约束 `UNIQUE(user_id, coupon_id)`，INSERT 时捕获 `UniqueConstraintViolationException`。

---

### 1.4 场景 C：支付回调重复处理（致命）

#### 问题描述

```
微信/支付宝会重复推送回调通知（网络超时、重试机制）
同一笔支付可能收到 2-5 次回调

如果每次都处理：
  - 订单状态被更新多次（虽然结果一样，但触发多次事件）
  - OrderPaid 事件触发多次 → 发送多条通知、多次统计
  - 更严重：并发到达时，两个请求同时更新 → 数据竞争
```

#### 解决方案：悲观锁 + 双重检查 + 幂等

```
回调到达
    │
    ├── 1. 验签（失败直接拒绝）
    │
    ├── 2. 查 payment 记录
    │      payment.status == 'paid' ?
    │      ├── YES → 直接返回 SUCCESS（幂等快路径）
    │      └── NO  → 继续处理
    │
    └── 3. 数据库事务 + SELECT FOR UPDATE
           │
           ├── BEGIN TRANSACTION
           ├── SELECT * FROM payments WHERE payment_no = ? FOR UPDATE
           │   （行级悲观锁，其他请求在这里排队等待）
           │
           ├── 再次检查 status（双重检查）
           │   ├── 已是 paid → COMMIT, 返回 SUCCESS
           │   └── 仍是 pending → 继续
           │
           ├── UPDATE payments SET status='paid', paid_at=NOW()
           ├── UPDATE orders SET status='paid', paid_at=NOW()
           ├── COMMIT
           │
           └── 触发 OrderPaid 事件（事务提交后才触发）
```

**时序图 — 并发回调处理：**

```
回调请求A                  回调请求B                 MySQL
    │                         │                       │
    │ 1. 验签通过              │ 1. 验签通过            │
    │                         │                       │
    │ 2. 快速检查              │                       │
    │ payment.status=pending  │                       │
    │ → 需要处理              │                       │
    │                         │                       │
    │ 3. BEGIN TX             │                       │
    │ SELECT FOR UPDATE ──────────────────────────────→│
    │   获得行锁 ✓            │                       │
    │←────────────────────────────────────────────────│
    │                         │                       │
    │                         │ 2. 快速检查            │
    │                         │ payment.status=pending│
    │                         │ → 需要处理            │
    │                         │                       │
    │                         │ 3. BEGIN TX           │
    │                         │ SELECT FOR UPDATE ────→│
    │                         │   等待行锁... ⏳       │
    │                         │  （A 持有锁，B 排队）  │
    │                         │                       │
    │ 4. status 仍是 pending  │                       │
    │ 5. UPDATE status=paid   │                       │
    │ 6. COMMIT（释放行锁） ──────────────────────────→│
    │ 7. 触发 OrderPaid ✓     │                       │
    │                         │                       │
    │                         │   B 获得行锁 ✓        │
    │                         │←──────────────────────│
    │                         │                       │
    │                         │ 4. 双重检查            │
    │                         │ status 已经是 paid！   │
    │                         │ → COMMIT，不处理       │
    │                         │ → 返回 SUCCESS        │
    │                         │                       │
    │ 返回 SUCCESS            │ 返回 SUCCESS          │
```

**完整代码：**

```php
// AbstractPaymentGateway::processPaymentResult()
protected final function processPaymentResult(string $paymentNo, array $callbackData): void
{
    // 幂等快路径：不加锁，快速返回
    $payment = Payment::where('payment_no', $paymentNo)->first();
    if (!$payment) {
        throw new PaymentNotFoundException();
    }
    if ($payment->status === PaymentStatus::Paid) {
        return; // 已处理，直接返回
    }

    // 金额校验
    $this->validateCallbackAmount($payment, $callbackData);

    // 悲观锁事务
    DB::transaction(function () use ($paymentNo, $callbackData) {
        // SELECT FOR UPDATE 锁住这一行
        $payment = Payment::where('payment_no', $paymentNo)
            ->lockForUpdate()
            ->first();

        // 双重检查（加锁后再查一次）
        if ($payment->status !== PaymentStatus::Pending) {
            return; // 其他请求已经处理了
        }

        // 更新支付记录
        $payment->update([
            'status'           => PaymentStatus::Paid,
            'gateway_trade_no' => $callbackData['trade_no'],
            'paid_at'          => now(),
        ]);

        // 更新订单状态
        $payment->order->update([
            'status'         => OrderStatus::Paid,
            'payment_method' => $payment->gateway,
            'paid_at'        => now(),
        ]);
    });

    // 事务提交后才触发事件（保证数据已持久化）
    event(new OrderPaid($payment->order));
}
```

> 为什么用悲观锁而不是乐观锁？支付回调场景中，冲突概率高（微信重复推送），悲观锁直接排队等待比乐观锁反复重试更高效。

---

### 1.5 场景 D：订单重复创建（致命）

#### 问题描述

```
用户在确认订单页快速点击两次"提交订单"按钮
或者网络慢，用户以为没提交，再点一次

结果：同一批购物车商品创建了两个订单，扣了两次库存
```

#### 解决方案：客户端防抖 + 服务端幂等令牌

**第一层：客户端防抖**

```
点击"提交订单" → 按钮立即置灰 + loading → 等待响应 → 恢复
```

**第二层：服务端幂等令牌**

```
┌────────────────────────────────────────────────────────────────┐
│                    幂等令牌流程                                   │
│                                                                │
│  1. 进入确认订单页时，服务端生成一个唯一令牌                       │
│     GET /orders/token → { "token": "abc123" }                  │
│     服务端：SET order_token:abc123 1 EX 600  (Redis, 10分钟)    │
│                                                                │
│  2. 提交订单时携带令牌                                           │
│     POST /orders { ..., idempotency_token: "abc123" }          │
│                                                                │
│  3. 服务端处理：                                                 │
│     用 Redis SETNX 尝试占用令牌                                  │
│     SET order_lock:abc123 1 NX EX 60                           │
│     ├── 设置成功 → 首次请求，正常创建订单                         │
│     └── 设置失败 → 重复请求，拒绝（返回已有订单号）               │
│                                                                │
│  4. 订单创建成功后删除令牌                                       │
│     DEL order_token:abc123                                     │
│                                                                │
└────────────────────────────────────────────────────────────────┘
```

**代码实现：**

```php
// OrderService::createOrder()
public function createOrder(int $userId, array $data, string $idempotencyToken): Order
{
    // 幂等检查：用 Redis SETNX 原子操作
    $lockKey = "order_lock:{$idempotencyToken}";
    $locked = Redis::set($lockKey, '1', 'NX', 'EX', 60);

    if (!$locked) {
        // 重复请求：查找已创建的订单返回
        $existingOrder = Order::where('idempotency_token', $idempotencyToken)->first();
        if ($existingOrder) {
            return $existingOrder;
        }
        throw new DuplicateOrderException('订单正在创建中，请勿重复提交');
    }

    try {
        return DB::transaction(function () use ($userId, $data, $idempotencyToken) {
            // ... 正常创建订单逻辑
            $order = Order::create([
                // ...
                'idempotency_token' => $idempotencyToken,
            ]);
            return $order;
        });
    } catch (\Throwable $e) {
        // 创建失败，释放锁让用户可以重试
        Redis::del($lockKey);
        throw $e;
    }
}
```

---

### 1.6 场景 E：Token 并发刷新（严重）

#### 问题描述

```
页面同时发出 5 个 API 请求
Access Token 刚好过期
5 个请求都收到 401 (code: 40101)
客户端 5 个响应拦截器同时触发刷新

由于 Refresh Token Rotation，第一次刷新后旧 Token 失效
第 2-5 次刷新全部失败 → 用户被强制登出
```

#### 解决方案：客户端 Promise 锁（已在主文档 2.4.5 设计）

```javascript
// Axios 响应拦截器
let isRefreshing = false;
let pendingRequests = [];

axios.interceptors.response.use(null, async (error) => {
    if (error.response?.data?.code === 40101) {
        if (isRefreshing) {
            // 排队等待第一个刷新完成
            return new Promise((resolve) => {
                pendingRequests.push(() => {
                    error.config.headers.Authorization = `Bearer ${getAccessToken()}`;
                    resolve(axios(error.config));
                });
            });
        }

        isRefreshing = true;
        try {
            const { access_token, refresh_token } = await refreshToken();
            saveTokens(access_token, refresh_token);

            // 通知所有排队的请求用新 Token 重试
            pendingRequests.forEach(cb => cb());
            pendingRequests = [];

            // 重试当前请求
            error.config.headers.Authorization = `Bearer ${access_token}`;
            return axios(error.config);
        } catch (refreshError) {
            // 刷新失败 → 清除 Token → 跳转登录
            clearTokens();
            router.push('/login');
            return Promise.reject(refreshError);
        } finally {
            isRefreshing = false;
        }
    }
    return Promise.reject(error);
});
```

**效果：**

```
请求1: 401 → isRefreshing=false → 发起刷新
请求2: 401 → isRefreshing=true  → 排队
请求3: 401 → isRefreshing=true  → 排队
请求4: 401 → isRefreshing=true  → 排队
请求5: 401 → isRefreshing=true  → 排队

请求1 刷新成功 → 获得新 Token
  → 通知请求 2/3/4/5 用新 Token 重试
  → 所有请求成功，用户无感知
```

---

### 1.7 场景 F：购物车并发操作（严重）

#### 问题描述

```
用户同时在 APP 和 H5 操作同一个购物车：
- APP: 加入 SKU-A 数量 2
- H5:  加入 SKU-A 数量 3

期望：SKU-A 数量 = 5
但如果是 先读后写：
  APP 读取数量=0 → 写入 quantity=2
  H5  读取数量=0 → 写入 quantity=3
  结果：quantity=3（丢失了 APP 的操作）
```

#### 解决方案：UPSERT + 数据库级原子操作

```sql
-- 方案1：INSERT ON DUPLICATE KEY UPDATE（MySQL 原子操作）
INSERT INTO carts (user_id, product_sku_id, quantity, is_checked)
VALUES (:user_id, :sku_id, :qty, 1)
ON DUPLICATE KEY UPDATE
    quantity = LEAST(quantity + :qty, 99);

-- UNIQUE(user_id, product_sku_id) 保证原子性
-- LEAST 保证不超过上限
```

```php
// CartService::add() — 使用 Laravel 的 upsert 或 DB::statement
public function add(int $userId, int $skuId, int $quantity): void
{
    $sku = $this->skuRepo->findOrFail($skuId);
    $this->validateSku($sku);

    // 原子 UPSERT：不存在则插入，存在则累加
    DB::statement("
        INSERT INTO carts (user_id, product_sku_id, quantity, is_checked, created_at, updated_at)
        VALUES (?, ?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            quantity = LEAST(quantity + VALUES(quantity), 99, ?),
            updated_at = NOW()
    ", [$userId, $skuId, $quantity, $sku->stock]);
}
```

---

### 1.8 场景 G：默认地址并发切换（严重）

#### 问题描述

```
两个请求同时设置不同地址为默认：
T1: PATCH /addresses/1/default → 清除旧默认 + 设 1 为默认
T2: PATCH /addresses/2/default → 清除旧默认 + 设 2 为默认

如果交错执行：
  T1 清除旧默认（is_default=0 for all）
  T2 清除旧默认（is_default=0 for all，包括 T1 刚设的 1）
  T1 设 1 为默认
  T2 设 2 为默认
  结果：地址 1 和 2 都是默认 → 违反"有且仅有 1 个默认"规则
```

#### 解决方案：数据库事务 + 排他锁

```php
// AddressService::setDefault()
public function setDefault(int $userId, int $addressId): void
{
    DB::transaction(function () use ($userId, $addressId) {
        // 用户级排他锁：锁住该用户的所有地址行
        // 保证同一用户的默认地址操作串行执行
        UserAddress::where('user_id', $userId)
            ->lockForUpdate()
            ->get();

        // 清除旧默认 + 设置新默认
        UserAddress::where('user_id', $userId)
            ->where('is_default', 1)
            ->update(['is_default' => 0]);

        UserAddress::where('id', $addressId)
            ->where('user_id', $userId)
            ->update(['is_default' => 1]);
    });
}
```

---

### 1.9 场景 H/I/J：高频读取（一般）

#### 解决方案：Redis 缓存层（Repository 装饰器）

```
请求量: 1000 QPS 商品详情

无缓存：1000 QPS 全部打到 MySQL → 数据库压力巨大
有缓存：
  ├── 990 次 Redis 命中 → 0.1ms 返回
  └── 10 次 Redis 未命中 → 查 MySQL → 回填 Redis
  MySQL 实际承受: ~10 QPS
```

这部分在主文档的 Repository 缓存装饰器中已设计。缓存带来的数据一致性问题在第二部分详细分析。

---

### 1.10 高并发解决方案汇总

```
┌──────────────────┬──────────────────────────┬─────────────────────────┐
│ 场景              │ 核心解决方案              │ 实现方式                 │
├──────────────────┼──────────────────────────┼─────────────────────────┤
│ 库存超卖          │ 数据库乐观锁             │ WHERE stock >= qty       │
│ (秒杀)           │ + Redis 预扣库存          │ Lua 脚本原子 DECR       │
├──────────────────┼──────────────────────────┼─────────────────────────┤
│ 优惠券超领        │ 数据库乐观锁             │ WHERE claimed < total    │
│                  │ + 唯一约束防重领           │ UNIQUE(user_id,coupon_id)│
├──────────────────┼──────────────────────────┼─────────────────────────┤
│ 支付回调重复      │ 悲观锁 + 双重检查        │ SELECT FOR UPDATE        │
│                  │ + 幂等快路径              │ status 前置检查          │
├──────────────────┼──────────────────────────┼─────────────────────────┤
│ 订单重复创建      │ 幂等令牌                 │ Redis SETNX              │
│                  │ + 客户端防抖              │ 唯一 token               │
├──────────────────┼──────────────────────────┼─────────────────────────┤
│ Token 并发刷新    │ 客户端 Promise 锁        │ isRefreshing 标志位      │
├──────────────────┼──────────────────────────┼─────────────────────────┤
│ 购物车并发        │ 数据库 UPSERT            │ ON DUPLICATE KEY UPDATE  │
├──────────────────┼──────────────────────────┼─────────────────────────┤
│ 默认地址并发      │ 事务 + 排他锁            │ lockForUpdate()          │
├──────────────────┼──────────────────────────┼─────────────────────────┤
│ 高频读取          │ Redis 缓存层             │ Repository 装饰器        │
└──────────────────┴──────────────────────────┴─────────────────────────┘
```

---

## 第二部分：MySQL 与 Redis 数据一致性

### 2.1 为什么会不一致

```
┌────────────────────────────────────────────────────────────────────┐
│                    数据不一致的根本原因                               │
│                                                                    │
│  MySQL 和 Redis 是两个独立的存储系统，不在同一个事务中               │
│                                                                    │
│  写入 MySQL + 操作 Redis = 两步操作                                 │
│  任何一步失败，或者两步之间有其他请求插入，都会导致不一致             │
│                                                                    │
│  ┌───────┐    步骤1     ┌───────┐    步骤2     ┌───────┐          │
│  │ 业务   │───────────→│ MySQL │───────────→│ Redis │          │
│  │ 代码   │    写入     │       │   删除缓存  │       │          │
│  └───────┘             └───────┘             └───────┘          │
│                │                    │                              │
│                └── 这两步之间 ──────┘                              │
│                    可能发生任何事                                   │
└────────────────────────────────────────────────────────────────────┘
```

### 2.2 六种不一致场景详解

#### 场景 1：先更新 MySQL，后删除 Redis — 删除失败

```
操作：更新商品价格 99 → 199

T1: UPDATE products SET price=199   ← MySQL 成功 ✓
T2: DEL product:100                 ← Redis 失败 ✗ (网络抖动)

结果：
  MySQL: price = 199（新值）
  Redis: price = 99（旧值）
  用户看到的是旧价格 99 ← 不一致！

持续时间：直到 Redis 缓存自然过期（可能是 1 小时）
```

#### 场景 2：先删除 Redis，后更新 MySQL — 读请求插入

```
请求 A: 更新商品价格 99 → 199
请求 B: 读取商品详情

T1: A: DEL product:100              ← Redis 删除成功 ✓
T2: B: GET product:100 → null       ← 缓存未命中
T3: B: SELECT * FROM products       ← 查 MySQL，读到 price=99（旧值）
T4: A: UPDATE products SET price=199 ← MySQL 更新成功
T5: B: SET product:100 {price:99}   ← 把旧值写回 Redis

结果：
  MySQL: price = 199（新值）
  Redis: price = 99（旧值，被请求 B 写回）
  不一致！且会持续到下次缓存过期
```

#### 场景 3：先更新 MySQL，后删除 Redis — 读请求在中间

```
请求 A: 更新商品价格 99 → 199
请求 B: 读取商品详情

T1: A: UPDATE products SET price=199 ← MySQL 更新成功
    （还没来得及删 Redis）
T2: B: GET product:100 → {price:99}  ← Redis 命中旧值
T3: A: DEL product:100               ← Redis 删除成功

结果：请求 B 拿到了旧值
但这是短暂不一致（T2-T3 之间的窗口，通常只有毫秒级）
T3 之后新请求会查 MySQL 并回填正确值
```

#### 场景 4：缓存穿透 — 查询不存在的数据

```
请求：GET /products/999999 (不存在的商品)

每次请求：
  1. 查 Redis → null（未命中）
  2. 查 MySQL → null（不存在）
  3. 不缓存（因为是 null）
  4. 下次请求重复以上流程

如果有人恶意请求不存在的 ID：
  所有请求都穿透到 MySQL → MySQL 压力暴增
```

#### 场景 5：缓存雪崩 — 大量缓存同时过期

```
场景：全量同步时给所有商品缓存设了相同的 TTL

T0: 1000 个商品缓存同时写入，TTL = 3600s
T0+3600: 1000 个缓存同时过期
  → 1000 个请求同时穿透到 MySQL
  → MySQL 短时间内收到大量查询 → 可能宕机
```

#### 场景 6：缓存击穿 — 热 key 过期瞬间大量请求

```
场景：首页推荐的热门商品缓存过期

T0: product:100 缓存过期
T1: 100 个请求同时到达，全部缓存未命中
T2: 100 个请求同时查 MySQL
T3: 100 个请求同时写缓存（重复写入）
```

---

### 2.3 解决方案全景

```
┌────────────────────────────────────────────────────────────────────────┐
│                  MySQL-Redis 一致性保障策略                              │
│                                                                        │
│  策略1: Cache-Aside Pattern（旁路缓存）                                 │
│  ├── 读：先 Redis，未命中查 MySQL，回填 Redis                           │
│  ├── 写：先写 MySQL，再删 Redis                                        │
│  └── 我们采用的基础策略                                                 │
│                                                                        │
│  策略2: 延迟双删                                                        │
│  ├── 先删 Redis → 写 MySQL → 延迟 N ms 后再删一次 Redis                │
│  └── 解决"先删后写"期间旧值被写回的问题                                 │
│                                                                        │
│  策略3: TTL 兜底                                                        │
│  ├── 所有缓存设置合理的过期时间                                         │
│  └── 即使出现不一致，最多持续 TTL 时间                                  │
│                                                                        │
│  策略4: 缓存空值                                                        │
│  ├── 查询结果为空时缓存一个特殊标记（短 TTL）                           │
│  └── 防止缓存穿透                                                       │
│                                                                        │
│  策略5: 互斥锁防击穿                                                    │
│  ├── 缓存未命中时用 Redis 锁保证只有一个请求查 MySQL                    │
│  └── 其他请求等待或返回旧值                                              │
│                                                                        │
│  策略6: TTL 随机化防雪崩                                                │
│  ├── 在基础 TTL 上加随机偏移                                            │
│  └── 避免大量缓存同时过期                                               │
│                                                                        │
└────────────────────────────────────────────────────────────────────────┘
```

### 2.4 我们采用的方案：Cache-Aside + 延迟双删 + TTL 兜底

#### 2.4.1 读流程

```
               ┌────────────┐
               │  读请求到达  │
               └──────┬─────┘
                      │
               ┌──────▼──────┐
               │ 查询 Redis   │
               │ GET key      │
               └──────┬──────┘
                      │
              ┌───────┴───────┐
              │               │
          命中(HIT)        未命中(MISS)
              │               │
              ▼               ▼
        ┌──────────┐   ┌──────────────┐
        │ 直接返回  │   │ 互斥锁尝试   │
        │ Redis 值 │   │ SETNX lock   │
        └──────────┘   └──────┬───────┘
                              │
                      ┌───────┴───────┐
                      │               │
                  获得锁            未获得锁
                      │               │
                      ▼               ▼
               ┌────────────┐  ┌────────────────┐
               │ 查 MySQL   │  │ 短暂等待(50ms) │
               │ SELECT ... │  │ 再查 Redis     │
               └──────┬─────┘  └────────────────┘
                      │
               ┌──────▼──────┐
               │ 结果为空？   │
               └──────┬──────┘
                  ┌───┴───┐
                  │       │
                 空      有值
                  │       │
                  ▼       ▼
           ┌──────────┐ ┌──────────────────┐
           │缓存空标记 │ │ SET key value    │
           │TTL=60s   │ │ TTL=正常TTL      │
           │(防穿透)  │ │ + random(0,300s) │
           └──────────┘ │ (防雪崩)          │
                        └──────────────────┘
```

#### 2.4.2 写流程（延迟双删）

```
               ┌──────────────┐
               │  写请求到达   │
               └──────┬───────┘
                      │
               ┌──────▼──────┐
               │ 1. 写 MySQL  │
               │ UPDATE ...   │
               └──────┬──────┘
                      │
               ┌──────▼──────────┐
               │ 2. 立即删 Redis  │
               │ DEL key         │
               │ (删除失败不阻塞) │
               └──────┬──────────┘
                      │
               ┌──────▼──────────────────┐
               │ 3. 投递延迟删除队列任务   │
               │ DelayedCacheDeletion     │
               │ delay: 500ms            │
               └──────┬──────────────────┘
                      │
              (500ms 后执行)
                      │
               ┌──────▼──────────┐
               │ 4. 再次删 Redis  │
               │ DEL key         │
               │ (兜底删除)       │
               └─────────────────┘
```

**为什么要延迟双删：**

```
问题场景（只删一次）：
  T1: 写请求: UPDATE MySQL, price=199
  T2: 读请求: GET Redis → null → SELECT MySQL → 读到 price=99 (事务未提交/主从延迟)
  T3: 写请求: DEL Redis
  T4: 读请求: SET Redis {price:99}  ← 旧值被写回!

延迟双删：
  T1: 写请求: UPDATE MySQL, price=199
  T2: 写请求: DEL Redis（第一次删）
  T3: 读请求: GET Redis → null → SELECT MySQL → {price:99}（可能还是旧值）
  T4: 读请求: SET Redis {price:99}（旧值被写回）
  T5: (500ms 后) 写请求: DEL Redis（第二次删，把 T4 写回的旧值删掉）
  T6: 新的读请求: GET Redis → null → SELECT MySQL → {price:199}（新值）
  T7: SET Redis {price:199} ✓

延迟时间 500ms > 一次读请求的耗时（通常 < 100ms）
确保"被写回的旧值"一定能被第二次删除清掉
```

#### 2.4.3 完整代码实现

**缓存装饰器 — 读操作：**

```php
// CachingProductRepository.php
class CachingProductRepository implements ProductRepositoryInterface
{
    private const CACHE_PREFIX = 'product:';
    private const DEFAULT_TTL = 3600; // 1 小时
    private const NULL_TTL = 60;      // 空值缓存 60 秒
    private const LOCK_TTL = 5;       // 互斥锁 5 秒
    private const TTL_JITTER = 300;   // 随机偏移 0-300 秒

    public function __construct(
        private ProductRepository $eloquent,
        private CacheManager $cache,
    ) {}

    public function findById(int $id): ?Product
    {
        $key = self::CACHE_PREFIX . $id;

        // 1. 先查缓存
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            // 命中：如果是空标记返回 null，否则返回数据
            return $cached === '__NULL__' ? null : $cached;
        }

        // 2. 缓存未命中：互斥锁防击穿
        $lockKey = "lock:{$key}";
        $locked = Redis::set($lockKey, '1', 'NX', 'EX', self::LOCK_TTL);

        if (!$locked) {
            // 未获得锁：短暂等待后再试一次缓存
            usleep(50_000); // 50ms
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached === '__NULL__' ? null : $cached;
            }
            // 仍然未命中，直接查 MySQL（降级，不等锁）
        }

        try {
            // 3. 查 MySQL
            $product = $this->eloquent->findById($id);

            // 4. 回填缓存
            if ($product) {
                // 正常值：TTL + 随机偏移（防雪崩）
                $ttl = self::DEFAULT_TTL + random_int(0, self::TTL_JITTER);
                $this->cache->put($key, $product, $ttl);
            } else {
                // 空值缓存（防穿透）
                $this->cache->put($key, '__NULL__', self::NULL_TTL);
            }

            return $product;
        } finally {
            Redis::del($lockKey);
        }
    }
}
```

**缓存装饰器 — 写操作（延迟双删）：**

```php
// CachingProductRepository.php（续）
public function update(int $id, array $data): Product
{
    // 1. 先写 MySQL
    $product = $this->eloquent->update($id, $data);

    // 2. 立即删缓存（第一次删）
    $this->invalidateCache($id);

    // 3. 投递延迟双删任务（第二次删，500ms 后执行）
    DelayedCacheDeletion::dispatch(self::CACHE_PREFIX . $id)
        ->delay(now()->addMilliseconds(500));

    return $product;
}

private function invalidateCache(int $id): void
{
    $key = self::CACHE_PREFIX . $id;

    try {
        $this->cache->forget($key);
    } catch (\Throwable $e) {
        // 删除失败不阻塞业务，记录日志
        // TTL 兜底会自动过期
        Log::warning("缓存删除失败: {$key}", ['error' => $e->getMessage()]);
    }
}
```

**延迟双删队列任务：**

```php
// Jobs/DelayedCacheDeletion.php
class DelayedCacheDeletion implements ShouldQueue
{
    public int $tries = 3;        // 最多重试 3 次
    public int $backoff = 1;      // 重试间隔 1 秒

    public function __construct(
        private string $cacheKey,
    ) {}

    public function handle(): void
    {
        Cache::forget($this->cacheKey);
    }
}
```

### 2.5 各场景对应解决方案明细

#### 场景 1 解决：删除失败 → 延迟双删 + TTL 兜底

```
第一次删失败 → 500ms 后队列任务再删一次（带 3 次重试）
如果还失败 → TTL 自然过期（最多 1h+5min 的不一致窗口）
```

#### 场景 2 解决：旧值被写回 → 延迟双删清除

```
旧值被写回 Redis 后，延迟 500ms 的第二次删除会清掉它
下次读请求重新查 MySQL 得到新值
```

#### 场景 3 解决：短暂不一致 → 可接受

```
"先写 MySQL 后删 Redis"之间的窗口通常只有毫秒级
这个级别的短暂不一致在商城场景中完全可接受
（用户刷新一次页面就能看到新数据）
```

#### 场景 4 解决：缓存穿透 → 空值缓存 + 参数校验

```php
// 1. 空值缓存（已在上面代码中实现）
if (!$product) {
    $this->cache->put($key, '__NULL__', 60); // 缓存 60 秒
}

// 2. 参数校验（Controller 层）
// ID 必须是正整数，非法 ID 直接拒绝，不查库
public function show(int $id)
{
    if ($id <= 0) {
        abort(404);
    }
    // ...
}

// 3. 布隆过滤器（可选，大数据量时使用）
// Redis 的布隆过滤器模块，O(1) 判断 ID 是否可能存在
```

#### 场景 5 解决：缓存雪崩 → TTL 随机化 + 永不过期策略

```php
// TTL 随机化（已在上面代码中实现）
$ttl = self::DEFAULT_TTL + random_int(0, self::TTL_JITTER);
// 1h 的缓存实际 TTL 为 3600-3900 秒
// 1000 个商品缓存的过期时间分散在 5 分钟内

// 分类缓存（几乎不变）：使用逻辑过期而非物理过期
// 缓存永不过期，value 中记录逻辑过期时间
// 逻辑过期后异步更新，期间返回旧值
class CachingCategoryRepository
{
    public function getTree(): array
    {
        $cached = $this->cache->get('category_tree');

        if ($cached) {
            if ($cached['expires_at'] > now()->timestamp) {
                return $cached['data']; // 未过期，直接返回
            }

            // 逻辑过期：异步刷新，先返回旧值
            RefreshCategoryCache::dispatch();
            return $cached['data']; // 返回旧值，不穿透
        }

        // 首次加载
        return $this->refreshAndCache();
    }
}
```

#### 场景 6 解决：缓存击穿 → 互斥锁

```
已在 2.4.3 代码中实现：
  1. Redis SETNX 互斥锁
  2. 获得锁的请求查 MySQL 并回填
  3. 未获得锁的请求短暂等待后再试
  4. 最终降级：直接查 MySQL（不让用户无限等待）
```

---

### 2.6 各 Repository 缓存一致性策略汇总

```
┌─────────────────────┬──────────┬─────────┬──────────┬──────────┬──────────┐
│ Repository           │ TTL      │ 延迟双删│ 空值缓存 │ 互斥锁   │ TTL随机化│
├─────────────────────┼──────────┼─────────┼──────────┼──────────┼──────────┤
│ ProductRepository    │ 1h       │ ✓       │ ✓        │ ✓        │ ✓ ±5min │
│  (商品详情 by ID)    │          │         │          │          │          │
├─────────────────────┼──────────┼─────────┼──────────┼──────────┼──────────┤
│ CategoryRepository   │ 24h      │ ✓       │ ✗        │ ✓        │ 逻辑过期 │
│  (分类树)            │ 逻辑过期 │         │(不可能空)│          │          │
├─────────────────────┼──────────┼─────────┼──────────┼──────────┼──────────┤
│ ProductSkuRepository │ 30min    │ ✓       │ ✓        │ ✓        │ ✓ ±3min │
│  (SKU详情 by ID)     │          │         │          │          │          │
├─────────────────────┼──────────┼─────────┼──────────┼──────────┼──────────┤
│ UserRepository       │ 1h       │ ✓       │ ✓        │ ✗        │ ✓ ±5min │
│  (用户基础信息)      │          │         │          │(低并发)  │          │
├─────────────────────┼──────────┼─────────┼──────────┼──────────┼──────────┤
│ OrderRepository      │ 不缓存   │ -       │ -        │ -        │ -       │
│ CartRepository       │ 不缓存   │ -       │ -        │ -        │ -       │
│ PaymentRepository    │ 不缓存   │ -       │ -        │ -        │ -       │
│  (实时性要求高)      │          │         │          │          │          │
└─────────────────────┴──────────┴─────────┴──────────┴──────────┴──────────┘
```

**不缓存的 Repository 不存在 MySQL-Redis 一致性问题**，因为所有读写直接走 MySQL。

只有被缓存装饰器包装的 Repository 才需要关注一致性。

---

### 2.7 监控与告警

```
┌────────────────────────────────────────────────────────────────────┐
│                    缓存一致性监控                                    │
│                                                                    │
│  1. 缓存命中率监控                                                  │
│     Redis INFO 统计 hit/miss 比例                                  │
│     命中率 < 80% 告警（可能存在穿透或缓存配置问题）                  │
│                                                                    │
│  2. 缓存删除失败监控                                                │
│     DelayedCacheDeletion 任务失败次数                               │
│     失败 > 10次/分钟 告警（Redis 可能有问题）                       │
│                                                                    │
│  3. MySQL-Redis 定期对账                                            │
│     每小时抽样 100 条热门商品                                       │
│     对比 MySQL 和 Redis 中的价格/库存                               │
│     不一致比例 > 1% 告警                                           │
│                                                                    │
│  4. Redis 内存监控                                                  │
│     内存使用 > 80% 告警                                            │
│     防止 OOM 导致缓存大面积失效                                     │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘
```

**对账定时任务：**

```php
// CheckCacheConsistency（每小时执行）
class CheckCacheConsistency extends Command
{
    public function handle(): void
    {
        // 抽样检查热门商品
        $productIds = Product::where('status', 1)
            ->orderByDesc('sales_count')
            ->limit(100)
            ->pluck('id');

        $inconsistent = 0;

        foreach ($productIds as $id) {
            $mysqlProduct = Product::find($id);
            $cachedProduct = Cache::get("product:{$id}");

            if ($cachedProduct && $cachedProduct !== '__NULL__') {
                // 比对关键字段
                if ((float) $mysqlProduct->base_price !== (float) $cachedProduct->base_price
                    || (int) $mysqlProduct->status !== (int) $cachedProduct->status) {
                    $inconsistent++;
                    Log::warning("缓存不一致: product:{$id}", [
                        'mysql_price'  => $mysqlProduct->base_price,
                        'cached_price' => $cachedProduct->base_price,
                    ]);
                    // 主动修复：删除不一致的缓存
                    Cache::forget("product:{$id}");
                }
            }
        }

        if ($inconsistent > 1) {
            // 告警
            Log::error("缓存一致性检查: {$inconsistent}/100 不一致");
        }
    }
}
```

---

### 2.8 一致性保障层级总结

```
┌────────────────────────────────────────────────────────────────────┐
│               缓存一致性保障五道防线                                  │
│                                                                    │
│  第一道: Cache-Aside 基本模式                                       │
│  │ 读：先 Redis，Miss 查 MySQL 回填                                │
│  │ 写：先写 MySQL，再删 Redis                                      │
│  │ 保证：大多数情况下的一致性                                       │
│  │                                                                 │
│  第二道: 延迟双删                                                   │
│  │ 写 MySQL 后，500ms 再删一次 Redis                               │
│  │ 保证：防止旧值在"先删后写"间被写回                               │
│  │                                                                 │
│  第三道: TTL 自然过期                                               │
│  │ 所有缓存都有过期时间                                             │


---

## 第三部分：Redis Lua 脚本详细设计

### 3.1 为什么需要 Lua 脚本

#### 3.1.1 核心问题：Redis 命令的非原子组合

```
场景：库存扣减

普通 Redis 命令（两步操作，有并发窗口）：
  步骤1: GET seckill_stock:100    → 返回 1
  步骤2: DECRBY seckill_stock:100 1

问题：
  T1: 线程A GET → 1（判断可以买）
  T2: 线程B GET → 1（判断可以买）
  T3: 线程A DECRBY → 0 ✓
  T4: 线程B DECRBY → -1 ✗ 超卖！

原因：GET 和 DECRBY 是两条独立命令
     Redis 虽然单线程，但命令之间可以被其他客户端的命令插入

Lua 脚本（一步原子操作）：
  整个脚本在 Redis 中原子执行
  执行期间不会被任何其他命令打断
  等价于把"读 + 判断 + 写"合成一条命令
```

#### 3.1.2 Lua 脚本 vs 普通命令 vs Redis 事务

```
┌──────────────────┬────────────┬────────────┬──────────────┐
│                  │ 普通命令   │ MULTI/EXEC │ Lua 脚本      │
│                  │ (单条)     │ (事务)     │ (EVAL)        │
├──────────────────┼────────────┼────────────┼──────────────┤
│ 原子性           │ 单命令原子 │ 批量原子   │ 整段逻辑原子  │
│ 能否读后判断再写  │ ✗          │ ✗ (*)      │ ✓             │
│ 条件逻辑(if/else)│ ✗          │ ✗          │ ✓             │
│ 多 key 操作      │ ✗          │ ✓          │ ✓             │
│ 性能（网络往返）  │ N 次       │ 1 次       │ 1 次          │
│ 复杂度           │ 简单       │ 简单       │ 需要写 Lua    │
└──────────────────┴────────────┴────────────┴──────────────┘

(*) MULTI/EXEC 在执行前无法读取中间结果来做判断
    所有命令是"盲"提交的，无法根据上一步的值决定下一步操作
```

#### 3.1.3 Lua 脚本在 Redis 中的执行原理

```
┌────────────────────────────────────────────────────────────┐
│                Redis 单线程模型 + Lua 执行                   │
│                                                            │
│  Redis 主线程（单线程，串行处理所有命令）                     │
│                                                            │
│  命令队列: [CMD-1] [CMD-2] [EVAL lua_script] [CMD-3] ...  │
│                                                            │
│  执行到 EVAL 时：                                           │
│  ┌────────────────────────────────────────────┐            │
│  │  Lua 虚拟机（嵌入在 Redis 进程内）           │            │
│  │                                            │            │
│  │  1. redis.call('GET', key)   → 读取值      │            │
│  │  2. if value > 0 then        → 条件判断    │            │
│  │  3. redis.call('DECRBY', key, 1) → 写入    │            │
│  │  4. return 剩余值             → 返回结果    │            │
│  │                                            │            │
│  │  整个执行期间：                              │            │
│  │  • 不会被 CMD-3 或任何其他客户端命令打断     │            │
│  │  • 等价于一条原子命令                        │            │
│  │  • 所有 redis.call 直接在进程内调用          │            │
│  │    不经过网络，零开销                        │            │
│  └────────────────────────────────────────────┘            │
│                                                            │
│  Lua 执行完毕后，才处理 CMD-3                               │
│                                                            │
│  ⚠️ 注意：Lua 脚本不能太慢                                 │
│  执行超过 lua-time-limit (默认5s) Redis 开始接受 KILL 命令  │
│  但其他正常命令仍然阻塞                                     │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

#### 3.1.4 Laravel 中调用 Lua 脚本的方式

```php
// 方式一：内联 Lua 字符串
$result = Redis::eval(
    "local v = redis.call('GET',KEYS[1]) ...",  // Lua 代码
    1,                                           // KEYS 数量
    'some_key',                                  // KEYS[1]
    'arg1'                                       // ARGV[1]
);

// 方式二：从文件加载（推荐，便于维护和测试）
$script = file_get_contents(resource_path('lua/seckill_deduct.lua'));
$result = Redis::eval($script, 1, "seckill_stock:{$skuId}", $qty);

// 方式三：EVALSHA（预加载脚本，避免每次传输完整代码）
// 首次：SCRIPT LOAD 返回 SHA1 哈希
$sha = Redis::script('load', $script);
// 后续：用哈希调用，节省网络带宽
$result = Redis::evalsha($sha, 1, "seckill_stock:{$skuId}", $qty);
```

**Lua 脚本参数规范：**

```lua
-- KEYS[n]: Redis key，用于 Redis Cluster 的 key 路由
-- ARGV[n]: 业务参数
--
-- 为什么要分 KEYS 和 ARGV？
-- Redis Cluster 需要知道脚本操作哪些 key，以便路由到正确的节点
-- 所有 key 必须通过 KEYS[] 传入，不能硬编码在脚本中
```

### 3.2 本系统中全部 Lua 脚本场景

```
┌────────────────────────────────────────────────────────────────┐
│              商城系统 Lua 脚本使用全景                            │
│                                                                │
│  脚本 1: 秒杀库存扣减           seckill_deduct.lua             │
│  脚本 2: 滑动窗口频率限制       rate_limiter.lua                │
│  脚本 3: 分布式锁（可重入）     reentrant_lock.lua              │
│  脚本 4: JWT 黑名单批量写入     jwt_blacklist_batch.lua         │
│  脚本 5: 幂等令牌验证并消费     idempotency_consume.lua         │
│  脚本 6: 优惠券原子领取         coupon_claim.lua                │
│                                                                │
│  文件存放: resources/lua/                                      │
│  管理方式: LuaScriptManager 统一加载 + EVALSHA 调用             │
└────────────────────────────────────────────────────────────────┘
```

---

### 3.3 脚本 1：秒杀库存扣减

**场景：** 秒杀活动，瞬间几千请求抢同一个 SKU

**为什么不能用普通命令：**

```
DECRBY 本身是原子的，但无法判断扣减后是否为负数
DECRBY seckill_stock:100 1  → 如果当前是 0，结果变成 -1 → 超卖
需要 "先读判断再扣" 的原子操作
```

**Lua 脚本：**

```lua
-- resources/lua/seckill_deduct.lua
--
-- 功能: 原子检查库存并扣减
-- KEYS[1] = seckill_stock:{sku_id}
-- ARGV[1] = 扣减数量
-- 返回值:
--   >= 0  扣减成功，返回剩余库存
--   -1    key 不存在（秒杀未开始或已结束）
--   -2    库存不足

local key = KEYS[1]
local qty = tonumber(ARGV[1])

-- 1. 检查 key 是否存在
local stock = redis.call('GET', key)
if not stock then
    return -1
end

stock = tonumber(stock)

-- 2. 检查库存是否充足
if stock < qty then
    return -2
end

-- 3. 原子扣减
local remaining = redis.call('DECRBY', key, qty)
return remaining
```

**PHP 调用封装：**

```php
class SeckillStockService
{
    private string $scriptSha;

    public function __construct()
    {
        $script = file_get_contents(resource_path('lua/seckill_deduct.lua'));
        $this->scriptSha = Redis::script('load', $script);
    }

    /**
     * 秒杀库存预扣
     *
     * @return int 剩余库存
     * @throws SeckillNotStartedException  秒杀未开始
     * @throws InsufficientStockException  库存不足
     */
    public function deduct(int $skuId, int $qty = 1): int
    {
        $key = "seckill_stock:{$skuId}";

        $result = Redis::evalsha($this->scriptSha, 1, $key, $qty);

        return match (true) {
            $result === -1 => throw new SeckillNotStartedException(),
            $result === -2 => throw new InsufficientStockException(),
            default        => (int) $result,
        };
    }

    /**
     * 秒杀库存回滚（下单失败时）
     */
    public function rollback(int $skuId, int $qty = 1): void
    {
        Redis::incrBy("seckill_stock:{$skuId}", $qty);
    }

    /**
     * 秒杀开始前：将 MySQL 库存预热到 Redis
     */
    public function warmUp(int $skuId, int $stock): void
    {
        Redis::set("seckill_stock:{$skuId}", $stock);
    }
}
```

**完整时序图：**

```
用户(5000并发)        SeckillStockService        Redis(Lua)        队列        MySQL
    │                        │                      │               │           │
    │ POST /seckill/buy      │                      │               │           │
    │ {sku_id: 100}          │                      │               │           │
    │───────────────────────→│                      │               │           │
    │                        │                      │               │           │
    │                        │ EVALSHA seckill_      │               │           │
    │                        │ deduct.lua           │               │           │
    │                        │ KEYS: stock:100      │               │           │
    │                        │ ARGV: 1              │               │           │
    │                        │─────────────────────→│               │           │
    │                        │                      │               │           │
    │                        │                      │ GET stock:100 │           │
    │                        │                      │ → stock=50    │           │
    │                        │                      │ 50 >= 1 ✓     │           │
    │                        │                      │ DECRBY → 49   │           │
    │                        │                      │               │           │
    │                        │ return 49 (成功)     │               │           │
    │                        │←─────────────────────│               │           │
    │                        │                      │               │           │
    │                        │ 预扣成功              │               │           │
    │                        │ 分发异步任务 ─────────────────────────→│           │
    │                        │                      │               │           │
    │  200 {下单中...}       │                      │  CreateSeckill│           │
    │←───────────────────────│                      │  Order        │           │
    │                        │                      │               │           │
    │                        │                      │               │ DB事务:   │
    │                        │                      │               │ 扣MySQL库存
    │                        │                      │               │ 创建订单  │
    │                        │                      │               │──────────→│
    │                        │                      │               │           │
    │  === 同时，第 4999 个请求 ===                  │               │           │
    │                        │                      │               │           │
    │ POST /seckill/buy      │                      │               │           │
    │───────────────────────→│                      │               │           │
    │                        │ EVALSHA ...           │               │           │
    │                        │─────────────────────→│               │           │
    │                        │                      │ GET → 0       │           │
    │                        │                      │ 0 < 1 ✗       │           │
    │                        │ return -2 (库存不足) │               │           │
    │                        │←─────────────────────│               │           │
    │                        │                      │               │           │
    │  200 {已售罄}          │                      │               │           │
    │←───────────────────────│     不碰 MySQL！      │               │           │
```

---

### 3.4 脚本 2：滑动窗口频率限制

**场景：** 短信发送（60s 内只能发 1 次）、登录尝试（1 分钟内最多 5 次）、API 通用限流

**为什么不能用普通 INCR + EXPIRE：**

```
普通方案: INCR counter:{key}  → 如果结果=1 则 EXPIRE counter:{key} 60

问题1（固定窗口）：
  T=0:00  用户发了 4 次（在 0:00-0:59 窗口）
  T=0:59  发第 5 次 ✓（到达上限）
  T=1:01  计数器过期重置 → 又可以发 5 次
  实际在 0:59-1:01 这 2 秒内发了 10 次！

问题2（INCR 和 EXPIRE 不原子）：
  INCR 成功但 EXPIRE 失败 → key 永不过期 → 永远被限流
```

**Lua 脚本 — 滑动窗口：**

```lua
-- resources/lua/rate_limiter.lua
--
-- 功能: 滑动窗口频率限制（基于 Sorted Set）
-- KEYS[1] = rate_limit:{type}:{identifier}
--           例如 rate_limit:sms:13812345678
--           例如 rate_limit:login:192.168.1.1
-- ARGV[1] = 窗口大小（秒），如 60
-- ARGV[2] = 窗口内最大次数，如 5
-- ARGV[3] = 当前时间戳（毫秒）
-- ARGV[4] = key 的过期时间（秒），= 窗口大小
--
-- 返回值:
--   0    允许通过
--   > 0  被限流，返回需要等待的秒数（到下一个窗口起始请求过期的时间）

local key = KEYS[1]
local window = tonumber(ARGV[1]) * 1000    -- 转换为毫秒
local max_count = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local expire_seconds = tonumber(ARGV[4])

-- 1. 清除窗口外的旧记录
local window_start = now - window
redis.call('ZREMRANGEBYSCORE', key, '-inf', window_start)

-- 2. 统计当前窗口内的请求数
local current_count = redis.call('ZCARD', key)

-- 3. 判断是否超限
if current_count >= max_count then
    -- 取最早的一条记录，计算还需等待多久
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    if #oldest > 0 then
        local oldest_time = tonumber(oldest[2])
        local wait_ms = oldest_time + window - now
        return math.ceil(wait_ms / 1000)  -- 返回等待秒数
    end
    return 1
end

-- 4. 未超限：记录本次请求
redis.call('ZADD', key, now, now .. ':' .. math.random(100000))

-- 5. 设置 key 过期时间（防止垃圾堆积）
redis.call('EXPIRE', key, expire_seconds)

-- 6. 返回 0 表示放行
return 0
```

**滑动窗口工作原理图：**

```
时间轴 (60 秒窗口, 最多 3 次)：

T=0s   T=15s  T=30s  T=45s  T=60s  T=75s  T=90s
  │      │      │      │      │      │      │
  ├──R1──┤      │      │      │      │      │
  │      ├──R2──┤      │      │      │      │
  │      │      ├──R3──┤      │      │      │
  │      │      │      │      │      │      │
  │      │      │  T=40s: 请求 R4              │
  │      │      │  窗口 = [T-60, T] = [-20, 40]
  │      │      │  窗口内: R1(0), R2(15), R3(30) → 3 个
  │      │      │  3 >= 3 → 限流！
  │      │      │  最早记录 R1 在 T=0
  │      │      │  等到 T=60 R1 才出窗口
  │      │      │  等待时间 = 60-40 = 20 秒
  │      │      │      │      │      │      │
  │      │      │      │  T=61s: 请求 R4          │
  │      │      │      │  窗口 = [1, 61]
  │      │      │      │  R1 出窗口（T=0 < 1）
  │      │      │      │  窗口内: R2, R3 → 2 个
  │      │      │      │  2 < 3 → 放行 ✓
```

**PHP 调用封装：**

```php
class RateLimiter
{
    private string $scriptSha;

    public function __construct()
    {
        $script = file_get_contents(resource_path('lua/rate_limiter.lua'));
        $this->scriptSha = Redis::script('load', $script);
    }

    /**
     * 检查频率限制
     *
     * @param string $type       限制类型 (sms, login, api)
     * @param string $identifier 标识符 (手机号, IP, user_id)
     * @param int    $maxCount   窗口内最大次数
     * @param int    $windowSec  窗口大小（秒）
     * @return RateLimitResult
     */
    public function attempt(string $type, string $identifier, int $maxCount, int $windowSec): RateLimitResult
    {
        $key = "rate_limit:{$type}:{$identifier}";
        $nowMs = (int) (microtime(true) * 1000);

        $waitSeconds = Redis::evalsha(
            $this->scriptSha, 1, $key,
            $windowSec, $maxCount, $nowMs, $windowSec + 10
        );

        return new RateLimitResult(
            allowed: $waitSeconds === 0,
            retryAfter: (int) $waitSeconds,
        );
    }
}

// 值对象
class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $retryAfter,  // 被限流时需等待的秒数
    ) {}
}
```

**各场景的限流参数：**

```php
// SmsService::sendCode()
$result = $this->rateLimiter->attempt('sms', $phone, maxCount: 1, windowSec: 60);
if (!$result->allowed) {
    throw new TooManyRequestsException("请 {$result->retryAfter} 秒后再试");
}

// LoginController（中间件中）
$result = $this->rateLimiter->attempt('login', $ip, maxCount: 5, windowSec: 60);

// API 通用限流（中间件中）
$result = $this->rateLimiter->attempt('api', $userId, maxCount: 60, windowSec: 60);

// 支付回调（来自第三方服务器）
$result = $this->rateLimiter->attempt('callback', $ip, maxCount: 30, windowSec: 60);
```

---

### 3.5 脚本 3：可重入分布式锁

**场景：** 订单创建、定时任务（确保只有一个实例运行）、批量操作互斥

**为什么 SETNX 不够：**

```
问题1: 不可重入
  同一个请求的两个方法都需要锁 → 第二次 SETNX 失败 → 死锁

问题2: 持有者误释放
  线程 A 获得锁，处理超时，锁自动过期
  线程 B 获得锁
  线程 A 处理完毕，DEL 删掉了线程 B 的锁！

问题3: 非原子的 "检查 + 删除"
  线程 A: GET lock → 是自己的 → 准备删除
  （此时锁过期，线程 B 获得锁）
  线程 A: DEL lock → 删掉了线程 B 的锁！
```

**Lua 脚本 — 加锁：**

```lua
-- resources/lua/reentrant_lock.lua
--
-- 功能: 可重入分布式锁 — 加锁
-- KEYS[1] = lock:{resource}
-- ARGV[1] = 持有者标识 (request_id / worker_id)
-- ARGV[2] = 过期时间（秒）
--
-- 返回值:
--   1  获得锁成功
--   0  获得锁失败（被其他持有者占用）

local key = KEYS[1]
local owner = ARGV[1]
local ttl = tonumber(ARGV[2])

-- 检查锁是否存在
local current_owner = redis.call('HGET', key, 'owner')

if not current_owner then
    -- 锁不存在，直接获取
    redis.call('HSET', key, 'owner', owner)
    redis.call('HSET', key, 'reentrant_count', 1)
    redis.call('EXPIRE', key, ttl)
    return 1
end

if current_owner == owner then
    -- 同一持有者，重入 +1
    redis.call('HINCRBY', key, 'reentrant_count', 1)
    redis.call('EXPIRE', key, ttl)  -- 续期
    return 1
end

-- 被其他持有者占用
return 0
```

**Lua 脚本 — 解锁：**

```lua
-- resources/lua/reentrant_unlock.lua
--
-- 功能: 可重入分布式锁 — 解锁
-- KEYS[1] = lock:{resource}
-- ARGV[1] = 持有者标识
--
-- 返回值:
--   1  解锁成功（完全释放）
--   0  重入计数减 1（还未完全释放）
--  -1  不是锁的持有者，拒绝解锁

local key = KEYS[1]
local owner = ARGV[1]

local current_owner = redis.call('HGET', key, 'owner')

-- 不是持有者，拒绝
if current_owner ~= owner then
    return -1
end

-- 重入计数 -1
local count = redis.call('HINCRBY', key, 'reentrant_count', -1)

if count <= 0 then
    -- 完全释放
    redis.call('DEL', key)
    return 1
end

-- 还有重入层级未释放
return 0
```

**PHP 封装：**

```php
class DistributedLock
{
    private string $lockSha;
    private string $unlockSha;

    public function __construct()
    {
        $this->lockSha = Redis::script('load',
            file_get_contents(resource_path('lua/reentrant_lock.lua')));
        $this->unlockSha = Redis::script('load',
            file_get_contents(resource_path('lua/reentrant_unlock.lua')));
    }

    /**
     * 获取锁
     *
     * @param string $resource  资源标识 (如 "order:create:10001")
     * @param int    $ttlSec    锁的超时时间
     * @param string $owner     持有者标识 (默认用 request ID)
     * @param int    $waitMs    等待时间（0=不等待，立即返回）
     * @param int    $retryMs   重试间隔
     */
    public function acquire(
        string $resource,
        int $ttlSec = 10,
        ?string $owner = null,
        int $waitMs = 0,
        int $retryMs = 50,
    ): ?LockToken {
        $owner ??= request()?->id() ?? Str::uuid()->toString();
        $key = "lock:{$resource}";
        $deadline = microtime(true) * 1000 + $waitMs;

        do {
            $result = Redis::evalsha($this->lockSha, 1, $key, $owner, $ttlSec);

            if ($result === 1) {
                return new LockToken($key, $owner, $ttlSec);
            }

            if ($waitMs === 0) {
                return null; // 不等待，立即返回失败
            }

            usleep($retryMs * 1000);
        } while (microtime(true) * 1000 < $deadline);

        return null; // 等待超时
    }

    /**
     * 释放锁
     */
    public function release(LockToken $token): bool
    {
        $result = Redis::evalsha($this->unlockSha, 1, $token->key, $token->owner);
        return $result >= 0;
    }
}

// 值对象
class LockToken
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly int $ttlSec,
    ) {}
}
```

**使用示例：**

```php
// 订单创建：防止同一用户并发创建
$lock = $this->distributedLock->acquire(
    resource: "order:create:{$userId}",
    ttlSec: 30,
    waitMs: 3000,  // 最多等 3 秒
);

if (!$lock) {
    throw new TooManyRequestsException('订单创建中，请稍后');
}

try {
    $order = $this->doCreateOrder($userId, $data);
    return $order;
} finally {
    $this->distributedLock->release($lock);
}
```

---

### 3.6 脚本 4：JWT 黑名单批量写入

**场景：** 管理员封禁用户 → 所有平台的 JWT 都要加入黑名单（可能 3-5 个 key），需要原子完成

**Lua 脚本：**

```lua
-- resources/lua/jwt_blacklist_batch.lua
--
-- 功能: 批量将 JWT jti 加入黑名单
-- KEYS[1..n] = jwt_blacklist:{jti_1}, jwt_blacklist:{jti_2}, ...
-- ARGV[1] = TTL（秒）= Access Token 有效期
--
-- 返回值: 成功加入的数量

local ttl = tonumber(ARGV[1])
local count = 0

for i, key in ipairs(KEYS) do
    redis.call('SET', key, '1')
    redis.call('EXPIRE', key, ttl)
    count = count + 1
end

return count
```

**PHP 调用：**

```php
// DeviceService::kickAllDevices()
public function kickAllDevices(int $userId): void
{
    $tokens = $this->userTokenRepo->getByUser($userId);

    if ($tokens->isEmpty()) return;

    $jtiKeys = $tokens
        ->map(fn ($t) => "jwt_blacklist:{$t->last_jwt_jti}")
        ->all();

    // 一次 Lua 调用批量写入，原子操作
    $script = file_get_contents(resource_path('lua/jwt_blacklist_batch.lua'));
    Redis::eval($script, count($jtiKeys), ...$jtiKeys, 7200);

    // 删除所有 Refresh Token
    $this->userTokenRepo->deleteByUser($userId);
}
```

**对比：不用 Lua 要发 N 次命令**

```
不用 Lua（N 次网络往返）：
  SET jwt_blacklist:jti_1 1 EX 7200   ← 第 1 次网络往返
  SET jwt_blacklist:jti_2 1 EX 7200   ← 第 2 次网络往返
  SET jwt_blacklist:jti_3 1 EX 7200   ← 第 3 次网络往返

  如果第 2 次失败但第 1、3 成功 → 部分踢出 → 不一致

用 Lua（1 次网络往返，原子执行）：
  EVAL script 3 jti_1 jti_2 jti_3 7200  ← 1 次网络往返
  全部成功或全部不执行
```

---

### 3.7 脚本 5：幂等令牌验证并消费

**场景：** 下单幂等。需要"检查令牌存在 + 标记为已使用"原子完成

**Lua 脚本：**

```lua
-- resources/lua/idempotency_consume.lua
--
-- 功能: 原子验证并消费幂等令牌
-- KEYS[1] = order_token:{token}    — 令牌是否合法
-- KEYS[2] = order_lock:{token}     — 令牌是否已被使用
-- ARGV[1] = 锁的过期时间（秒）
--
-- 返回值:
--   1   令牌有效且首次使用，消费成功
--   0   令牌已被消费过（重复请求）
--  -1   令牌不存在或已过期（非法请求）

local token_key = KEYS[1]
local lock_key = KEYS[2]
local lock_ttl = tonumber(ARGV[1])

-- 1. 检查令牌是否存在（是否通过 GET /orders/token 签发的）
local exists = redis.call('EXISTS', token_key)
if exists == 0 then
    return -1
end

-- 2. 尝试占用（SETNX 原子操作）
local locked = redis.call('SET', lock_key, '1', 'NX', 'EX', lock_ttl)
if not locked then
    return 0  -- 已被消费
end

-- 3. 删除令牌（已消费，不可再用）
redis.call('DEL', token_key)

return 1
```

**PHP 调用：**

```php
// OrderService::createOrder()
public function createOrder(int $userId, array $data, string $token): Order
{
    $script = file_get_contents(resource_path('lua/idempotency_consume.lua'));

    $result = Redis::eval(
        $script,
        2,                        // KEYS 数量
        "order_token:{$token}",   // KEYS[1]
        "order_lock:{$token}",    // KEYS[2]
        60                        // ARGV[1] 锁 60 秒
    );

    match ($result) {
        -1 => throw new InvalidTokenException('无效的下单令牌，请刷新页面'),
        0  => throw new DuplicateOrderException('订单正在创建中，请勿重复提交'),
        1  => null, // 验证通过，继续创建
    };

    try {
        return DB::transaction(fn () => $this->doCreateOrder($userId, $data));
    } catch (\Throwable $e) {
        // 创建失败，回滚令牌让用户可以重试
        Redis::set("order_token:{$token}", '1', 'EX', 600);
        Redis::del("order_lock:{$token}");
        throw $e;
    }
}
```

---

### 3.8 脚本 6：优惠券库存原子扣减

**场景：** 高并发领取优惠券（如双 11 发券），Redis 前置拦截

```lua
-- resources/lua/coupon_claim.lua
--
-- 功能: 原子检查优惠券剩余量并扣减，同时检查用户是否已领
-- KEYS[1] = coupon_remaining:{coupon_id}    — 剩余可领数量
-- KEYS[2] = coupon_claimed:{coupon_id}      — 已领取用户集合 (SET)
-- ARGV[1] = user_id
--
-- 返回值:
--   1   领取成功
--   0   已领完（库存不足）
--  -1   该用户已领过

local remaining_key = KEYS[1]
local claimed_key = KEYS[2]
local user_id = ARGV[1]

-- 1. 检查用户是否已领
local already_claimed = redis.call('SISMEMBER', claimed_key, user_id)
if already_claimed == 1 then
    return -1
end

-- 2. 检查剩余量并扣减
local remaining = tonumber(redis.call('GET', remaining_key) or '0')
if remaining <= 0 then
    return 0
end

-- 3. 原子扣减 + 记录用户
redis.call('DECRBY', remaining_key, 1)
redis.call('SADD', claimed_key, user_id)

return 1
```

```php
// CouponService::claim() — Redis 前置检查 + MySQL 最终写入
public function claim(int $userId, int $couponId): UserCoupon
{
    // 第一层：Redis Lua 原子检查（挡住 99% 的无效请求）
    $script = file_get_contents(resource_path('lua/coupon_claim.lua'));
    $result = Redis::eval(
        $script, 2,
        "coupon_remaining:{$couponId}",
        "coupon_claimed:{$couponId}",
        $userId
    );

    match ($result) {
        -1 => throw new CouponAlreadyClaimedException(),
        0  => throw new CouponSoldOutException(),
    };

    // 第二层：MySQL 最终写入（Redis 只是前置过滤，MySQL 才是权威）
    try {
        return DB::transaction(function () use ($userId, $couponId) {
            // 数据库级防超领（兜底）
            $affected = DB::table('coupons')
                ->where('id', $couponId)
                ->where('claimed_count', '<', DB::raw('total_count'))
                ->update(['claimed_count' => DB::raw('claimed_count + 1')]);

            if ($affected === 0) {
                throw new CouponSoldOutException();
            }

            return UserCoupon::create([
                'user_id'   => $userId,
                'coupon_id' => $couponId,
                'status'    => 'unused',
            ]);
        });
    } catch (\Throwable $e) {
        // MySQL 失败，回滚 Redis
        Redis::incrBy("coupon_remaining:{$couponId}", 1);
        Redis::sRem("coupon_claimed:{$couponId}", $userId);
        throw $e;
    }
}
```

---

### 3.9 脚本管理：LuaScriptManager

统一管理所有 Lua 脚本的加载和调用：

```php
class LuaScriptManager
{
    /** @var array<string, string> 脚本名 → SHA1 */
    private array $shaMap = [];

    /** 注册的脚本列表 */
    private const SCRIPTS = [
        'seckill_deduct'       => 'lua/seckill_deduct.lua',
        'rate_limiter'         => 'lua/rate_limiter.lua',
        'reentrant_lock'       => 'lua/reentrant_lock.lua',
        'reentrant_unlock'     => 'lua/reentrant_unlock.lua',
        'jwt_blacklist_batch'  => 'lua/jwt_blacklist_batch.lua',
        'idempotency_consume'  => 'lua/idempotency_consume.lua',
        'coupon_claim'         => 'lua/coupon_claim.lua',
    ];

    /**
     * 启动时预加载所有脚本到 Redis（获取 SHA1）
     * 在 ServiceProvider::boot() 中调用
     */
    public function loadAll(): void
    {
        foreach (self::SCRIPTS as $name => $path) {
            $script = file_get_contents(resource_path($path));
            $this->shaMap[$name] = Redis::script('load', $script);
        }
    }

    /**
     * 通过名称调用脚本
     */
    public function run(string $name, int $numKeys, ...$args): mixed
    {
        $sha = $this->shaMap[$name]
            ?? throw new \RuntimeException("Lua 脚本未注册: {$name}");

        try {
            return Redis::evalsha($sha, $numKeys, ...$args);
        } catch (RedisException $e) {
            // SHA 不存在（Redis 重启过），重新加载
            if (str_contains($e->getMessage(), 'NOSCRIPT')) {
                $this->loadAll();
                return Redis::evalsha($this->shaMap[$name], $numKeys, ...$args);
            }
            throw $e;
        }
    }
}
```

**注册到 ServiceProvider：**

```php
// AppServiceProvider::boot()
public function boot(LuaScriptManager $lua): void
{
    $lua->loadAll();
}

// 使用
$result = $lua->run('seckill_deduct', 1, "seckill_stock:100", 1);
$result = $lua->run('rate_limiter', 1, "rate_limit:sms:138xxx", 60, 1, $nowMs, 70);
```

---

### 3.10 Lua 脚本文件结构

```
resources/
└── lua/
    ├── seckill_deduct.lua          秒杀库存原子扣减
    ├── rate_limiter.lua            滑动窗口频率限制
    ├── reentrant_lock.lua          分布式锁 — 加锁
    ├── reentrant_unlock.lua        分布式锁 — 解锁
    ├── jwt_blacklist_batch.lua     JWT 黑名单批量写入
    ├── idempotency_consume.lua     幂等令牌验证并消费
    └── coupon_claim.lua            优惠券库存原子扣减
```

### 3.11 Lua 脚本注意事项

```
┌────────────────────────────────────────────────────────────────┐
│                    Lua 脚本使用注意事项                          │
│                                                                │
│  1. 脚本必须短小快速                                            │
│     Redis 单线程，脚本执行期间所有其他命令阻塞                    │
│     经验值：脚本执行时间 < 5ms                                  │
│     禁止：在脚本中做复杂计算、大量循环、调用 TIME 等              │
│                                                                │
│  2. 所有 key 必须通过 KEYS[] 传入                               │
│     硬编码 key 名在 Redis Cluster 下会报错                      │
│     因为 Cluster 需要知道 key 在哪个 slot                       │
│                                                                │
│  3. EVALSHA 优先于 EVAL                                        │
│     EVAL 每次传输完整脚本文本（可能 1KB+）                      │
│     EVALSHA 只传 40 字节的 SHA1 哈希                            │
│     NOSCRIPT 时回退重新 SCRIPT LOAD                             │
│                                                                │
│  4. 脚本内禁止调用非确定性命令                                   │
│     禁止：TIME, RANDOMKEY, SRANDMEMBER (无 count)              │
│     原因：Redis 主从复制需要脚本在副本上产生相同结果              │
│                                                                │
│  5. 错误处理                                                    │
│     redis.call() 出错会中止脚本并返回错误                       │
│     redis.pcall() 出错返回错误对象，脚本继续执行                 │
│     一般用 redis.call()，让错误暴露出来                          │
│                                                                │
│  6. 返回值类型对应                                              │
│     Lua number  → Redis integer                                │
│     Lua string  → Redis bulk string                            │
│     Lua table   → Redis array                                  │
│     Lua boolean true  → Redis integer 1                        │
│     Lua boolean false → Redis nil                              │
│     Lua nil     → Redis nil                                    │
│                                                                │
└────────────────────────────────────────────────────────────────┘
```
