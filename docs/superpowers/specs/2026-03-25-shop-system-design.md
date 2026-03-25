# 商城系统整体设计文档

## 1. 项目概述

基于 Laravel 12 构建的完整 C 端商城系统，提供商品浏览、购物车、订单、支付、物流、售后等完整电商功能，以及一套支持多平台登录、Token 泄露检测、同平台多设备互踢的用户认证体系。

### 技术栈

- **框架**: Laravel 12 (PHP 8.2+)
- **认证**: JWT Access Token + Database Refresh Token (php-open-source-saver/jwt-auth)
- **数据库**: MySQL
- **缓存**: Laravel Cache (Database driver，可切换 Redis)
- **队列**: Database driver (可切换 Redis)

### 客户端平台

| 平台标识 | 说明 |
|---------|------|
| `app` | 原生 APP (iOS/Android) |
| `mini_program` | 微信/支付宝小程序 |
| `h5` | H5 移动网页 |
| `pc` | PC 网页端 |

## 2. 用户认证体系

### 2.1 认证需求

**用户体验：**
- 登录后 15-30 天内免重新登录
- 多平台登录互不影响（APP 登录不会踢掉 H5）
- 同平台多设备登录踢掉旧设备

**安全性：**
- Token 不可伪造，仅服务端可签发
- Access Token 快速过期（2h），降低泄露风险
- 能发现 Token 被盗取或泄露

**稳定性：**
- Token 具有自解释性（JWT），存储服务异常时仍能提供基本服务

### 2.2 双 Token 机制

| Token 类型 | 格式 | 有效期 | 存储 | 用途 |
|-----------|------|--------|------|------|
| Access Token | JWT (HS256) | 2 小时 | 无需服务端存储 | 请求认证，自包含 user_id/platform |
| Refresh Token | 随机字符串 | 30 天 | 数据库 user_tokens 表 | 刷新 Access Token |

### 2.3 登录方式

1. **手机号 + 短信验证码**
2. **账号（手机号/邮箱）+ 密码**
3. **微信登录**（APP 微信 SDK / 小程序原生 / 公众号网页授权）
4. **支付宝登录**

### 2.4 认证流程

#### 登录流程

```
Client → POST /auth/login/{method} → Server
  1. 验证凭据（密码/验证码/第三方 OAuth）
  2. 识别客户端平台（X-Platform 请求头）
  3. 删除该用户同平台旧 Refresh Token（同平台互踢）
  4. 生成 JWT Access Token（2h，含 user_id, platform, jti）
  5. 生成 Refresh Token（30天，存库，绑定 IP + UA + platform）
  6. 记录安全日志
  7. 返回 { access_token, refresh_token, expires_in }
```

#### 请求认证流程

```
Client → Bearer {access_token} → Middleware
  1. JWT 验签 + 检查过期（纯计算，不查库）
  2. 查 JWT 黑名单缓存（即时踢人检测）
  3. 正常：可选查库检查用户状态（封禁等）
  4. 存储异常：跳过查库，仅依赖 JWT 信息，记录告警
```

#### Token 刷新流程

```
Client → POST /auth/refresh { refresh_token } → Server
  1. 查库验证 Refresh Token 存在且未过期
  2. 校验 IP + User-Agent 是否与创建时一致
  3. 不一致 → Token 泄露，删除 Token，返回 401，记录安全日志
  4. 一致 → 签发新 Access Token，可选旋转 Refresh Token
  5. 返回 { access_token, refresh_token, expires_in }
```

### 2.5 设备互踢机制

**同平台互踢：**
- 新设备登录时，删除该用户同平台所有 Refresh Token
- 旧设备的 Access Token 仍有效最多 2h
- 为实现即时踢掉：将旧设备 JWT 的 jti 写入缓存黑名单（TTL=2h）
- 旧设备下次请求时被 JwtBlacklist 中间件拦截，返回 40104 错误码

**跨平台共存：**
- 不同平台的 Token 互不影响
- 用户可同时在 APP、PC、H5、小程序上登录

**主动踢设备：**
- 用户在安全中心查看所有在线设备
- 可主动踢掉指定设备（删除 Refresh Token + JWT 加黑名单）

### 2.6 Token 泄露检测

**检测时机：** Refresh Token 刷新时

**检测策略（按平台差异化）：**
- **PC 平台**：IP 或 UA 任一不匹配即触发检测
- **移动平台（APP/H5/小程序）**：仅当 IP 和 UA 同时变化时才触发（移动网络频繁切换 IP 属正常行为）
- 检测策略可通过配置调整，支持按平台单独开关

**处理措施：**
1. 该 Refresh Token 立即失效（删除）
2. 记录安全日志（event=token_leak）
3. 返回 401 + 错误码 40103
4. 用户下次登录时可提示："您的账号在异常环境被使用"

### 2.7 第三方登录流程

```
首次登录：
  1. 客户端获取第三方授权码 (code)
  2. 服务端用 code 换取 access_token + openid
  3. 查询 user_social_accounts 无绑定记录
  4. 创建 User + UserSocialAccount
  5. 签发双 Token

再次登录：
  1. 同上获取 openid
  2. 查询 user_social_accounts 找到绑定的 user_id
  3. 直接签发双 Token

绑定手机号：
  第三方登录后若未绑定手机号，引导用户绑定
```

## 3. 业务模块设计

### 3.1 模块划分

```
商城系统
├── 用户认证模块 (Auth)        → 注册/登录/Token/设备/安全
├── 商品模块 (Product)         → 分类/SPU/SKU/属性/搜索
├── 购物车模块 (Cart)          → 加购/调整/选中/删除
├── 订单模块 (Order)           → 下单/状态机/列表/详情
├── 支付模块 (Payment)         → 微信支付/支付宝/回调/退款
├── 物流模块 (Shipping)        → 物流公司/运费模板/轨迹查询
└── 售后模块 (AfterSale)       → 退款/退货/售后状态机
```

### 3.2 订单状态机

```
          ┌─── 超时30min ───→ cancelled
          │
pending ──┼─── 用户取消 ────→ cancelled
          │
          └─── 支付成功 ────→ paid ──→ shipped ──┬──→ completed
                                                 │
                                           用户确认收货
                                           或15天自动确认
```

### 3.3 售后状态机

```
pending → approved → returning → refunded → completed
    │         │
    └→ rejected  └→ cancelled
```

**状态转换触发条件：**
- `pending → approved`：管理员审核通过
- `pending → rejected`：管理员驳回
- `approved → returning`：用户提交退货快递单号（仅 return_refund 类型）
- `approved → refunded`：管理员确认仅退款（refund_only 类型直接跳过 returning）
- `returning → refunded`：管理员确认收到退货后发起退款
- `refunded → completed`：退款到账确认
- `approved → cancelled`：用户在退货前取消售后

## 4. 数据库设计

### 4.1 用户认证相关（4 张表）

#### users 用户表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| phone | VARCHAR(11) UNIQUE | 手机号 |
| email | VARCHAR(255) UNIQUE NULL | 邮箱 |
| password | VARCHAR(255) NULL | 密码（第三方登录可空） |
| nickname | VARCHAR(50) | 昵称 |
| avatar | VARCHAR(255) NULL | 头像 |
| status | TINYINT DEFAULT 1 | 1正常 0禁用 |
| email_verified_at | TIMESTAMP NULL | 邮箱验证时间 |
| phone_verified_at | TIMESTAMP NULL | 手机验证时间 |
| last_login_at | TIMESTAMP NULL | 最后登录时间 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |
| deleted_at | TIMESTAMP NULL | 软删除 |

#### user_social_accounts 第三方登录绑定表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| platform | VARCHAR(20) | wechat_app/wechat_mini/wechat_h5/alipay |
| platform_id | VARCHAR(128) | 第三方 openid |
| union_id | VARCHAR(128) NULL | 微信 union_id |
| nickname | VARCHAR(50) NULL | 第三方昵称 |
| avatar | VARCHAR(255) NULL | 第三方头像 |
| access_token | TEXT NULL | 第三方 access_token（加密存储） |
| refresh_token | TEXT NULL | 第三方 refresh_token（加密存储） |
| token_expires_at | TIMESTAMP NULL | |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

唯一约束：UNIQUE(platform, platform_id)

#### user_tokens 设备/Refresh Token 表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| token | VARCHAR(64) UNIQUE | Refresh Token（SHA256 哈希存储） |
| platform | VARCHAR(20) | app/mini_program/h5/pc |
| device_name | VARCHAR(100) NULL | 设备名称 |
| device_id | VARCHAR(128) NULL | 设备唯一标识 |
| client_ip | VARCHAR(45) | 登录 IP（支持 IPv6） |
| user_agent | VARCHAR(500) | 登录 UA |
| last_active_at | TIMESTAMP | 最后活跃时间 |
| expires_at | TIMESTAMP | 过期时间 |
| created_at | TIMESTAMP | |

索引：INDEX(user_id, platform)

> 注：此表不使用 Eloquent `updated_at`（`const UPDATED_AT = null`），手动管理 `last_active_at`。

#### security_logs 安全日志表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| event | VARCHAR(50) | login/logout/token_leak/device_kicked/password_changed |
| platform | VARCHAR(20) NULL | 平台 |
| client_ip | VARCHAR(45) | IP |
| user_agent | VARCHAR(500) NULL | UA |
| detail | JSON NULL | 额外信息 |
| created_at | TIMESTAMP | |

索引：INDEX(user_id, event)

### 4.2 商品相关（4 张表）

#### categories 商品分类表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| parent_id | BIGINT UNSIGNED DEFAULT 0 | 父分类ID |
| name | VARCHAR(50) | 分类名 |
| icon | VARCHAR(255) NULL | 分类图标 |
| sort_order | INT DEFAULT 0 | 排序 |
| is_enabled | TINYINT DEFAULT 1 | 是否启用 |
| level | TINYINT DEFAULT 1 | 层级 1/2/3 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

索引：INDEX(parent_id)

#### products 商品 SPU 表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| category_id | BIGINT UNSIGNED FK | 分类ID |
| shipping_template_id | BIGINT UNSIGNED FK NULL | 运费模板ID（NULL 则使用默认模板） |
| title | VARCHAR(255) | 商品标题 |
| subtitle | VARCHAR(255) NULL | 副标题 |
| main_image | VARCHAR(255) | 主图 |
| images | JSON | 商品图片组 |
| description | TEXT NULL | 商品详情（富文本） |
| base_price | DECIMAL(10,2) | 基础价格（列表展示用） |
| sales_count | INT UNSIGNED DEFAULT 0 | 销量 |
| review_count | INT UNSIGNED DEFAULT 0 | 评价数 |
| status | TINYINT DEFAULT 0 | 0下架 1上架 |
| sort_order | INT DEFAULT 0 | 排序 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |
| deleted_at | TIMESTAMP NULL | 软删除 |

索引：INDEX(category_id, status)

#### product_skus SKU 表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| product_id | BIGINT UNSIGNED FK | 商品ID |
| title | VARCHAR(255) | SKU 名称 |
| attributes | JSON | 规格属性 {"颜色":"红色","尺码":"XL"} |
| price | DECIMAL(10,2) | SKU 价格 |
| original_price | DECIMAL(10,2) NULL | 原价（划线价） |
| stock | INT UNSIGNED DEFAULT 0 | 库存 |
| weight | DECIMAL(8,2) NULL | 重量（kg，用于按重量计算运费） |
| sku_code | VARCHAR(50) UNIQUE NULL | SKU 编码 |
| image | VARCHAR(255) NULL | SKU 图片 |
| sort_order | INT DEFAULT 0 | 排序 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

索引：INDEX(product_id)

#### product_attributes 商品属性定义表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| product_id | BIGINT UNSIGNED FK | 商品ID |
| name | VARCHAR(50) | 属性名 |
| values | JSON | 可选值 |
| sort_order | INT DEFAULT 0 | 排序 |

索引：INDEX(product_id)

> 注：此表不使用 Eloquent 时间戳（`$timestamps = false`）。

### 4.3 交易相关（9 张表）

#### user_addresses 收货地址表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| name | VARCHAR(50) | 收货人 |
| phone | VARCHAR(11) | 手机号 |
| province | VARCHAR(20) | 省 |
| city | VARCHAR(20) | 市 |
| district | VARCHAR(20) | 区 |
| address | VARCHAR(255) | 详细地址 |
| is_default | TINYINT DEFAULT 0 | 默认地址 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

索引：INDEX(user_id)

#### carts 购物车表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| product_sku_id | BIGINT UNSIGNED FK | SKU ID |
| quantity | INT UNSIGNED DEFAULT 1 | 数量 |
| is_checked | TINYINT DEFAULT 1 | 是否选中 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

唯一约束：UNIQUE(user_id, product_sku_id)

#### orders 订单表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| order_no | VARCHAR(32) UNIQUE | 订单号 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| address_snapshot | JSON | 收货地址快照 |
| total_amount | DECIMAL(10,2) | 商品总额 |
| discount_amount | DECIMAL(10,2) DEFAULT 0 | 优惠金额（预留，V1默认为0） |
| shipping_fee | DECIMAL(10,2) DEFAULT 0 | 运费 |
| pay_amount | DECIMAL(10,2) | 实付金额（= total_amount - discount_amount + shipping_fee） |
| status | VARCHAR(20) DEFAULT 'pending' | 订单状态 |
| payment_method | VARCHAR(20) NULL | 支付方式 |
| paid_at | TIMESTAMP NULL | 支付时间 |
| shipped_at | TIMESTAMP NULL | 发货时间 |
| completed_at | TIMESTAMP NULL | 完成时间 |
| cancelled_at | TIMESTAMP NULL | 取消时间 |
| cancel_reason | VARCHAR(255) NULL | 取消原因 |
| remark | VARCHAR(255) NULL | 买家备注 |
| platform | VARCHAR(20) | 下单平台 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |
| deleted_at | TIMESTAMP NULL | 软删除 |

索引：INDEX(user_id, status), INDEX(order_no) ← 冗余，UNIQUE 已含索引

> 注：order_no 的 UNIQUE 约束已自动创建索引，无需额外 INDEX。实际迁移中只需 UNIQUE 约束。

#### order_items 订单商品表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| order_id | BIGINT UNSIGNED FK | 订单ID |
| product_id | BIGINT UNSIGNED | 商品ID |
| product_sku_id | BIGINT UNSIGNED | SKU ID |
| title | VARCHAR(255) | 商品名快照 |
| sku_title | VARCHAR(255) | SKU 名快照 |
| image | VARCHAR(255) | 商品图快照 |
| price | DECIMAL(10,2) | 单价快照 |
| quantity | INT UNSIGNED | 数量 |

索引：INDEX(order_id)

> 注：此表不使用 Eloquent 时间戳（`$timestamps = false`），随订单一起创建，不会单独更新。

#### payments 支付记录表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| payment_no | VARCHAR(32) UNIQUE | 支付单号 |
| order_id | BIGINT UNSIGNED FK | 订单ID |
| user_id | BIGINT UNSIGNED | 用户ID |
| gateway | VARCHAR(20) | wechat/alipay |
| amount | DECIMAL(10,2) | 支付金额 |
| status | VARCHAR(20) DEFAULT 'pending' | 支付状态 |
| gateway_trade_no | VARCHAR(64) NULL | 第三方交易号 |
| gateway_response | JSON NULL | 第三方原始数据 |
| paid_at | TIMESTAMP NULL | 支付时间 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

索引：INDEX(order_id)

#### express_companies 物流公司表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| name | VARCHAR(50) | 公司名 |
| code | VARCHAR(20) UNIQUE | 编码 |
| is_enabled | TINYINT DEFAULT 1 | 是否启用 |
| sort_order | INT DEFAULT 0 | 排序 |

> 注：此表不使用 Eloquent 时间戳（`$timestamps = false`），数据通过 seeder 初始化。

#### shipping_templates 运费模板表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| name | VARCHAR(50) | 模板名 |
| charge_type | VARCHAR(10) | weight/piece |
| rules | JSON | 运费规则 |
| is_default | TINYINT DEFAULT 0 | 默认模板 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

#### shipments 物流信息表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| order_id | BIGINT UNSIGNED FK | 订单ID |
| express_company_id | BIGINT UNSIGNED FK | 物流公司ID |
| tracking_no | VARCHAR(50) | 快递单号 |
| status | VARCHAR(20) DEFAULT 'shipped' | 物流状态 |
| traces | JSON NULL | 物流轨迹 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

索引：INDEX(order_id)

#### after_sales 售后表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| after_sale_no | VARCHAR(32) UNIQUE | 售后单号 |
| order_id | BIGINT UNSIGNED FK | 订单ID |
| order_item_id | BIGINT UNSIGNED FK NULL | 订单商品ID（NULL 表示整单售后） |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| type | VARCHAR(20) | refund_only/return_refund |
| status | VARCHAR(20) DEFAULT 'pending' | 售后状态 |
| reason | VARCHAR(255) | 申请原因 |
| description | TEXT NULL | 详细说明 |
| images | JSON NULL | 凭证图片 |
| refund_amount | DECIMAL(10,2) | 退款金额 |
| admin_remark | VARCHAR(255) NULL | 管理员备注 |
| tracking_no | VARCHAR(50) NULL | 退货快递单号 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

索引：INDEX(order_id), INDEX(user_id, status)

### 4.4 表关系

```
users ─┬─ user_social_accounts (1:N)
       ├─ user_tokens (1:N)
       ├─ security_logs (1:N)
       ├─ user_addresses (1:N)
       ├─ carts (1:N)
       ├─ orders ──┬─ order_items (1:N)
       │           ├─ payments (1:N)
       │           ├─ shipments (1:N)
       │           └─ after_sales (1:N)
       └─ after_sales (1:N)

categories ─── products ──┬─ product_skus (1:N)
                          └─ product_attributes (1:N)
```

共 **17 张表**。

## 5. API 设计

### 5.1 统一响应格式

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

分页响应在 data 中包含 `items` 和 `pagination` 字段。

### 5.2 业务错误码

| 错误码 | 说明 |
|--------|------|
| 0 | 成功 |
| 40100 | 未认证 |
| 40101 | Access Token 过期 |
| 40102 | Refresh Token 过期 |
| 40103 | Token 泄露检测触发 |
| 40104 | 设备被踢 |
| 40105 | 账号被禁用 |
| 40106 | 短信验证码错误 |
| 40107 | 密码错误 |
| 40108 | 账号不存在 |
| 42201 | 库存不足 |
| 42202 | 商品已下架 |
| 42203 | 订单状态不允许此操作 |
| 42204 | 购物车商品已失效 |
| 42205 | 收货地址不存在 |
| 50001 | 支付网关调用失败 |
| 50002 | 支付回调验签失败 |
| 50003 | 退款失败 |

### 5.3 API 路由

#### 公开接口（无需认证）

```
POST   /api/v1/auth/register              注册
POST   /api/v1/auth/login/password         账号密码登录
POST   /api/v1/auth/login/sms              手机验证码登录
POST   /api/v1/auth/login/wechat           微信登录
POST   /api/v1/auth/login/alipay           支付宝登录
POST   /api/v1/auth/sms/send              发送短信验证码
POST   /api/v1/auth/password/reset         重置密码（手机验证码验证）
GET    /api/v1/products                    商品列表
GET    /api/v1/products/{id}               商品详情
GET    /api/v1/categories                  分类列表
```

#### Token 刷新接口（无需 JWT，需 Refresh Token）

```
POST   /api/v1/auth/refresh                刷新 Token（提交 refresh_token）
```

#### 第三方回调接口（无需认证，需验签）

```
POST   /api/v1/payments/notify/{gateway}    支付回调（微信/支付宝服务器调用）
```

#### 认证接口（需 JWT Token）

```
POST   /api/v1/auth/logout                 登出
GET    /api/v1/auth/devices                在线设备列表
DELETE /api/v1/auth/devices/{id}           踢掉指定设备
POST   /api/v1/auth/password/change        修改密码

GET    /api/v1/user/profile                获取个人信息
PUT    /api/v1/user/profile                修改个人信息
POST   /api/v1/user/phone/bind             绑定手机号（第三方登录用户）
GET    /api/v1/user/addresses              收货地址列表
POST   /api/v1/user/addresses              创建收货地址
PUT    /api/v1/user/addresses/{id}         修改收货地址
DELETE /api/v1/user/addresses/{id}         删除收货地址

GET    /api/v1/cart                         购物车列表
POST   /api/v1/cart                         加入购物车
PUT    /api/v1/cart/{id}                    修改数量/选中状态
DELETE /api/v1/cart/{id}                    删除购物车项

POST   /api/v1/orders                      创建订单
GET    /api/v1/orders                       订单列表
GET    /api/v1/orders/{no}                  订单详情
POST   /api/v1/orders/{no}/cancel           取消订单
POST   /api/v1/orders/{no}/confirm          确认收货
POST   /api/v1/orders/{no}/pay              发起支付
GET    /api/v1/orders/{no}/shipment         查询物流轨迹

POST   /api/v1/after-sales                  申请售后
GET    /api/v1/after-sales                  售后列表
GET    /api/v1/after-sales/{no}             售后详情
POST   /api/v1/after-sales/{no}/tracking    提交退货快递单号
POST   /api/v1/after-sales/{no}/cancel      取消售后申请
```

### 5.4 中间件栈

```
公开接口:     RateLimiter → ForceJsonResponse → PlatformIdentify → Controller
认证接口:     RateLimiter → ForceJsonResponse → PlatformIdentify → JwtAuthenticate → JwtBlacklist → Controller
刷新接口:     RateLimiter → ForceJsonResponse → TokenLeakDetection → Controller
第三方回调:   RateLimiter → ForceJsonResponse → GatewaySignatureVerify → Controller
```

## 6. 代码架构

### 6.1 目录结构

```
app/
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── Auth/                    认证控制器
│   │   │   ├── RegisterController
│   │   │   ├── LoginController
│   │   │   ├── TokenController
│   │   │   └── DeviceController
│   │   ├── User/                    用户控制器
│   │   │   ├── ProfileController
│   │   │   └── AddressController
│   │   ├── Product/                 商品控制器
│   │   │   ├── ProductController
│   │   │   └── CategoryController
│   │   ├── CartController
│   │   ├── Order/                   订单控制器
│   │   │   ├── OrderController
│   │   │   └── PaymentController
│   │   ├── ShipmentController
│   │   └── AfterSaleController
│   ├── Middleware/                   中间件
│   │   ├── ForceJsonResponse
│   │   ├── JwtAuthenticate
│   │   ├── JwtBlacklist
│   │   ├── TokenLeakDetection
│   │   ├── PlatformIdentify
│   │   └── GatewaySignatureVerify
│   ├── Requests/Api/V1/             表单验证
│   └── Resources/Api/V1/            API 资源转换
│
├── Models/                          17 个 Eloquent 模型
├── Services/                        业务逻辑层
│   ├── Auth/
│   │   ├── AuthService              认证核心
│   │   ├── JwtService               JWT 管理
│   │   ├── DeviceService            设备/互踢
│   │   ├── SmsService               短信验证码
│   │   └── SocialAuth/              第三方登录（工厂模式）
│   ├── Product/ProductService
│   ├── Cart/CartService
│   ├── Order/
│   │   ├── OrderService
│   │   └── OrderNoGenerator
│   ├── Payment/
│   │   ├── PaymentService
│   │   └── Gateway/                 支付网关（策略模式）
│   ├── Shipping/ShippingService
│   └── AfterSale/AfterSaleService
│
├── Enums/                           PHP 8.2+ 枚举
│   ├── OrderStatus
│   ├── PaymentStatus
│   ├── AfterSaleStatus
│   ├── AfterSaleType
│   ├── Platform
│   └── SecurityEvent
│
├── Events/                          事件
├── Listeners/                       监听器
├── Jobs/                            队列任务
└── Exceptions/                      自定义异常
```

### 6.2 架构模式

| 模式 | 应用场景 |
|------|----------|
| 三层架构 | Controller → Service → Model |
| 工厂模式 | 第三方登录 SocialAuthManager |
| 策略模式 | 支付网关 PaymentGatewayInterface |
| 事件驱动 | 登录日志、订单状态变更通知、库存扣减 |
| 枚举 | 状态管理（订单/支付/售后/平台） |

## 7. 关键实现细节

### 7.1 库存扣减（防超卖）

使用数据库乐观锁：
```sql
UPDATE product_skus SET stock = stock - {qty} WHERE id = {id} AND stock >= {qty}
```
影响行数为 0 则库存不足，抛出 InsufficientStockException。

### 7.2 订单号生成

格式：年月日时分秒(14位) + 随机数(6位) + 用户ID后4位 = 24位
数据库 UNIQUE 约束兜底唯一性。

### 7.3 支付回调幂等性

- payment_no 唯一约束
- 回调处理前先查 payment.status，已支付则直接返回成功
- 整个回调处理在数据库事务中

### 7.4 JWT 黑名单

- 存储：Laravel Cache（key: `jwt_blacklist:{jti}`，value: 1）
- TTL：与 Access Token 有效期一致（2h），自动过期清理
- 中间件检查：`Cache::has('jwt_blacklist:' . $jti)`

### 7.5 短信验证码

- 缓存存储：`sms_code:{phone}` → `{code}`，TTL 5分钟
- 频率限制：同一手机号 60 秒内只能发 1 次
- 验证后立即删除，防止重放

### 7.6 订单创建事务

整个下单流程在数据库事务中执行：
1. 校验购物车商品有效性（商品上架、SKU存在）
2. 乐观锁扣减库存（失败则回滚）
3. 创建 Order 记录
4. 创建 OrderItem 记录（快照商品信息）
5. 清除已下单的购物车条目
6. 事务提交
7. 触发 OrderCreated 事件

### 7.7 定时任务

| 频率 | 任务 | 说明 |
|------|------|------|
| 每分钟 | CloseExpiredOrders | 关闭超时未支付订单（30min） |
| 每天 | CleanExpiredTokens | 清理过期 Refresh Token |
| 每天 | AutoConfirmOrder | 自动确认收货（15天） |
| 每6小时 | QueryShipmentTrace | 批量查询物流轨迹 |

### 7.8 频率限制

| 端点类别 | 限制 | 说明 |
|---------|------|------|
| 短信发送 | 1次/60秒/手机号 | 防止短信轰炸 |
| 登录接口 | 5次/分钟/IP | 防止暴力破解 |
| Token 刷新 | 10次/分钟/用户 | 正常使用不会触发 |
| 一般 API | 60次/分钟/用户 | 通用限制 |
| 商品浏览 | 120次/分钟/IP | 公开接口宽松限制 |
| 支付回调 | 30次/分钟/IP | 第三方服务器调用 |

## 8. 异常处理

全局异常处理器统一捕获并转换为 API 响应：

| 异常类型 | HTTP 状态码 | 业务错误码 |
|---------|-------------|-----------|
| ValidationException | 422 | 字段级错误 |
| AuthenticationException | 401 | 401xx |
| ModelNotFoundException | 404 | - |
| TokenLeakException | 401 | 40103 |
| DeviceKickedException | 401 | 40104 |
| InsufficientStockException | 422 | 42201 |
| 其他异常 | 500 | 生产环境隐藏详情 |

## 9. 测试策略

- **Feature Tests**：覆盖所有 API 端点的成功/失败场景
- **Unit Tests**：覆盖核心 Service 逻辑
- **关键场景**：4种登录、Token 刷新/过期、泄露检测、同平台互踢、JWT 黑名单、下单流程、库存扣减防超卖、支付回调幂等性、订单状态机
- **工具**：PHPUnit

## 10. 实现顺序

按模块依赖关系推荐以下实现顺序：

1. **基础设施** — 统一响应格式、异常处理、中间件
2. **用户认证** — 注册/登录/JWT/Refresh Token/设备管理/泄露检测
3. **商品模块** — 分类/SPU/SKU/属性
4. **购物车** — 依赖商品模块
5. **订单** — 依赖购物车和商品
6. **支付** — 依赖订单
7. **物流** — 依赖订单
8. **售后** — 依赖订单和支付
