# 商城系统整体设计文档

## 1. 项目概述

基于 Laravel 12 构建的完整 C 端商城系统，提供商品浏览、购物车、订单、支付、物流、售后等完整电商功能，以及一套支持多平台登录、Token 泄露检测、同平台多设备互踢的用户认证体系。

### 技术栈

- **框架**: Laravel 12 (PHP 8.2+)
- **认证**: JWT Access Token + Database Refresh Token (php-open-source-saver/jwt-auth)
- **数据库**: MySQL
- **缓存**: Redis（Repository 缓存层 + JWT 黑名单 + 短信验证码 + 频率限制）
- **队列**: Database driver (可切换 Redis)
- **支付**: wechatpay/wechatpay-php (微信支付 v3)，alipaysdk/openapi-sdk-php (支付宝)

### 客户端平台

| 平台标识 | 说明 | 技术方案 |
|---------|------|---------|
| `app` | 原生 APP (iOS/Android) | uniapp 或 flutter |
| `mini_program` | 微信/支付宝小程序 | uniapp 或原生小程序 |
| `h5` | H5 移动网页 | uniapp H5 或独立前端 |
| `pc` | PC 网页端 | Web 前端 |

> APP 端使用 uniapp 或 flutter 开发，省市区等字典数据由客户端本地维护，服务端仅提供纯 API 接口。

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

### 2.4 Token 体系详细设计

#### 2.4.1 Token 结构

**Access Token (JWT) 结构：**

```
Header:
{
  "alg": "HS256",
  "typ": "JWT"
}

Payload:
{
  "iss": "shop-api",              // 签发者
  "sub": 10001,                   // 用户ID (user_id)
  "jti": "a1b2c3d4e5f6",         // Token唯一标识（用于黑名单）
  "iat": 1711353600,              // 签发时间
  "exp": 1711360800,              // 过期时间（签发后2h）
  "plat": "app",                  // 登录平台
  "stat": 1                       // 用户状态（1正常 0禁用）
}

Signature:
  HMACSHA256(base64(header) + "." + base64(payload), SECRET_KEY)
```

**Refresh Token 结构：**

```
原始值: 64位随机字符串（crypto-safe random）
  示例: "f8a3b7c1d9e2f4a6b8c0d2e4f6a8b0c2d4e6f8a0b2c4d6e8f0a2b4c6d8e0f2"

存储值: SHA256(原始值)
  → 数据库存哈希值，即使数据库泄露攻击者也无法使用

返回给客户端: 原始值
客户端存储: 原始值（安全存储：Keychain/SharedPreferences/HttpOnly Cookie）
```

#### 2.4.2 Token 派发（签发流程）

**时序图 — 密码登录签发 Token：**

```
┌────────┐          ┌────────────┐        ┌──────────┐      ┌───────┐     ┌───────┐
│ Client │          │ LoginCtrl  │        │AuthService│      │  MySQL │     │ Redis │
└───┬────┘          └─────┬──────┘        └────┬─────┘      └───┬───┘     └───┬───┘
    │                     │                    │                 │             │
    │ POST /auth/login/password               │                 │             │
    │ Headers: X-Platform: app                │                 │             │
    │ Body: {phone, password}                 │                 │             │
    │────────────────────>│                    │                 │             │
    │                     │                    │                 │             │
    │                     │ login(credentials) │                 │             │
    │                     │───────────────────>│                 │             │
    │                     │                    │                 │             │
    │                     │                    │ 1. 查询用户      │             │
    │                     │                    │ SELECT * FROM users            │
    │                     │                    │ WHERE phone=?   │             │
    │                     │                    │────────────────>│             │
    │                     │                    │     user record │             │
    │                     │                    │<────────────────│             │
    │                     │                    │                 │             │
    │                     │                    │ 2. 验证密码                    │
    │                     │                    │ Hash::check(password, hash)    │
    │                     │                    │ → 失败则抛 AuthException(40107)│
    │                     │                    │                 │             │
    │                     │                    │ 3. 检查用户状态                │
    │                     │                    │ → status=0 则抛 AuthException(40105)
    │                     │                    │                 │             │
    │                     │                    │ 4. 查同平台旧Token             │
    │                     │                    │ SELECT id,jti FROM user_tokens │
    │                     │                    │ WHERE user_id=? AND platform='app'
    │                     │                    │────────────────>│             │
    │                     │                    │   old_tokens[]  │             │
    │                     │                    │<────────────────│             │
    │                     │                    │                 │             │
    │                     │                    │ 5. 旧Token的jti加入JWT黑名单   │
    │                     │                    │ 遍历 old_tokens:              │
    │                     │                    │ SET jwt_blacklist:{jti} 1     │
    │                     │                    │ EX 7200 (2h)   │             │
    │                     │                    │────────────────────────────>  │
    │                     │                    │                 │       OK    │
    │                     │                    │  <────────────────────────────│
    │                     │                    │                 │             │
    │                     │                    │ 6. 删除同平台旧Refresh Token   │
    │                     │                    │ DELETE FROM user_tokens        │
    │                     │                    │ WHERE user_id=? AND platform='app'
    │                     │                    │────────────────>│             │
    │                     │                    │            done │             │
    │                     │                    │<────────────────│             │
    │                     │                    │                 │             │
    │                     │                    │ 7. 生成JWT Access Token        │
    │                     │                    │ payload = {                    │
    │                     │                    │   sub: user_id,               │
    │                     │                    │   jti: new_uuid,              │
    │                     │                    │   plat: "app",                │
    │                     │                    │   exp: now + 2h               │
    │                     │                    │ }                              │
    │                     │                    │ access_token = JWT.sign(payload, SECRET)
    │                     │                    │                 │             │
    │                     │                    │ 8. 生成Refresh Token           │
    │                     │                    │ raw_token = random(64)         │
    │                     │                    │ INSERT INTO user_tokens {      │
    │                     │                    │   user_id, platform: "app",   │
    │                     │                    │   token: SHA256(raw_token),   │
    │                     │                    │   client_ip, user_agent,      │
    │                     │                    │   device_name, expires_at: now+30d
    │                     │                    │ }               │             │
    │                     │                    │────────────────>│             │
    │                     │                    │         inserted│             │
    │                     │                    │<────────────────│             │
    │                     │                    │                 │             │
    │                     │                    │ 9. 记录安全日志               │
    │                     │                    │ INSERT INTO security_logs      │
    │                     │                    │ {user_id, event:"login", ...} │
    │                     │                    │────────────────>│             │
    │                     │                    │                 │             │
    │                     │ {access_token, refresh_token, expires_in: 7200}    │
    │                     │<───────────────────│                 │             │
    │                     │                    │                 │             │
    │  200 OK             │                    │                 │             │
    │  {code:0, data: {access_token, refresh_token, expires_in}}│             │
    │<────────────────────│                    │                 │             │
```

**时序图 — 微信登录签发 Token：**

```
┌────────┐     ┌──────────┐     ┌────────────┐     ┌──────────────┐     ┌───────┐
│ Client │     │LoginCtrl │     │ AuthService│     │SocialAuthMgr │     │ MySQL │
└───┬────┘     └────┬─────┘     └─────┬──────┘     └──────┬───────┘     └───┬───┘
    │               │                 │                    │                 │
    │ 1. 客户端先通过微信SDK获取授权码 code                                  │
    │               │                 │                    │                 │
    │ POST /auth/login/wechat         │                    │                 │
    │ Headers: X-Platform: app        │                    │                 │
    │ Body: {code: "wx_auth_code"}    │                    │                 │
    │──────────────>│                 │                    │                 │
    │               │                 │                    │                 │
    │               │ loginByWechat() │                    │                 │
    │               │────────────────>│                    │                 │
    │               │                 │                    │                 │
    │               │                 │ getWechatUser(code)│                 │
    │               │                 │───────────────────>│                 │
    │               │                 │                    │                 │
    │               │                 │                    │ 2. code换取access_token
    │               │                 │                    │ POST https://api.weixin.qq.com
    │               │                 │                    │   /sns/oauth2/access_token
    │               │                 │                    │   ?code=wx_auth_code
    │               │                 │                    │                 │
    │               │                 │                    │ 3. 获取用户信息 │
    │               │                 │                    │ GET /sns/userinfo│
    │               │                 │                    │   ?access_token=...&openid=...
    │               │                 │                    │                 │
    │               │                 │  {openid, unionid, nickname, avatar}│
    │               │                 │<───────────────────│                 │
    │               │                 │                    │                 │
    │               │                 │ 4. 查绑定关系                       │
    │               │                 │ SELECT * FROM user_social_accounts   │
    │               │                 │ WHERE platform='wechat_app'          │
    │               │                 │   AND platform_id={openid}           │
    │               │                 │────────────────────────────────────> │
    │               │                 │                                      │
    │               │                 │ ┌─── 未找到（首次登录）──────────────┐│
    │               │                 │ │ 5a. 创建用户                      ││
    │               │                 │ │ INSERT INTO users {nickname,avatar}││
    │               │                 │ │ 5b. 创建绑定关系                  ││
    │               │                 │ │ INSERT INTO user_social_accounts   ││
    │               │                 │ │ {user_id, platform, platform_id,  ││
    │               │                 │ │  union_id, nickname, avatar}      ││
    │               │                 │ └───────────────────────────────────┘│
    │               │                 │                                      │
    │               │                 │ ┌─── 已找到（再次登录）─────────────┐│
    │               │                 │ │ 5c. 获取关联的 user_id           ││
    │               │                 │ └───────────────────────────────────┘│
    │               │                 │                    │                 │
    │               │                 │ 6. 后续流程与密码登录相同（步骤4-9）│
    │               │                 │    同平台互踢 → 签发双Token → 安全日志
    │               │                 │                    │                 │
    │  200 OK {access_token, refresh_token, expires_in}    │                 │
    │<──────────────│                 │                    │                 │
```

#### 2.4.3 Token 存储方案

**服务端存储：**

```
┌─────────────────────────────────────────────────────────────────┐
│                        服务端 Token 存储                        │
├─────────────────┬───────────────────────────────────────────────┤
│                 │                                               │
│  MySQL          │  user_tokens 表                               │
│  (持久化存储)    │  ├── Refresh Token (SHA256哈希)               │
│                 │  ├── 用户ID + 平台 + 设备信息                  │
│                 │  ├── 创建时 IP + User-Agent (泄露检测基准)     │
│                 │  ├── 过期时间 (30天)                           │
│                 │  └── 最后活跃时间                              │
│                 │                                               │
├─────────────────┼───────────────────────────────────────────────┤
│                 │                                               │
│  Redis          │  1. JWT 黑名单                                │
│  (高速缓存)     │     Key:  jwt_blacklist:{jti}                 │
│                 │     Value: 1                                  │
│                 │     TTL:   7200s (2h，与Access Token同寿命)    │
│                 │                                               │
│                 │  2. 短信验证码                                 │
│                 │     Key:  sms_code:{phone}                    │
│                 │     Value: {code}                              │
│                 │     TTL:   300s (5分钟)                        │
│                 │                                               │
│                 │  3. 二次验证 Token                             │
│                 │     Key:  verify_token:{token}                │
│                 │     Value: {user_id}                           │
│                 │     TTL:   300s (5分钟)                        │
│                 │                                               │
│                 │  4. 登录失败计数                               │
│                 │     Key:  login_fail:{phone}                  │
│                 │     Value: {count}                             │
│                 │     TTL:   900s (15分钟)                       │
│                 │                                               │
├─────────────────┼───────────────────────────────────────────────┤
│                 │                                               │
│  JWT 自身       │  Access Token 不存储在服务端                   │
│  (无状态)       │  ├── 签名保证不可伪造                          │
│                 │  ├── payload 自包含用户信息                    │
│                 │  └── 过期时间自包含，验签时自动检查             │
│                 │                                               │
└─────────────────┴───────────────────────────────────────────────┘
```

**客户端存储（按平台）：**

```
┌──────────────┬─────────────────────────┬────────────────────────┐
│ 平台          │ Access Token 存储       │ Refresh Token 存储     │
├──────────────┼─────────────────────────┼────────────────────────┤
│ APP (iOS)    │ 内存 (变量)              │ Keychain (加密)        │
│ APP (Android)│ 内存 (变量)              │ EncryptedSharedPrefs   │
│ 小程序       │ 内存 (变量/globalData)   │ wx.setStorageSync      │
│ H5           │ 内存 (变量/Vuex/Pinia)   │ HttpOnly Secure Cookie │
│ PC Web       │ 内存 (变量/Vuex/Pinia)   │ HttpOnly Secure Cookie │
├──────────────┴─────────────────────────┴────────────────────────┤
│ 原则：Access Token 仅存内存，页面刷新/App重启后丢失，           │
│       靠 Refresh Token 重新获取。Refresh Token 持久化安全存储。  │
└─────────────────────────────────────────────────────────────────┘
```

#### 2.4.4 Token 认证（请求验证流程）

**时序图 — 正常 API 请求认证：**

```
┌────────┐     ┌────────────┐    ┌─────────────┐   ┌────────────┐   ┌───────┐  ┌──────────┐
│ Client │     │JwtAuth MW  │    │JwtBlacklist │   │OptionalDB  │   │ Redis │  │Controller│
└───┬────┘     └─────┬──────┘    └──────┬──────┘   └─────┬──────┘   └───┬───┘  └────┬─────┘
    │                │                  │                 │               │           │
    │ GET /api/v1/orders               │                 │               │           │
    │ Authorization: Bearer {jwt}       │                 │               │           │
    │───────────────>│                  │                 │               │           │
    │                │                  │                 │               │           │
    │                │ 1. 解析JWT       │                 │               │           │
    │                │ 从Header提取token │                 │               │           │
    │                │                  │                 │               │           │
    │                │ 2. 验证签名       │                 │               │           │
    │                │ HMAC-SHA256验签   │                 │               │           │
    │                │ → 签名无效: 401  │                 │               │           │
    │                │                  │                 │               │           │
    │                │ 3. 检查过期       │                 │               │           │
    │                │ exp > now ?      │                 │               │           │
    │                │ → 过期: 401      │                 │               │           │
    │                │ (code: 40101)    │                 │               │           │
    │                │                  │                 │               │           │
    │                │ 4. 提取payload   │                 │               │           │
    │                │ {sub,jti,plat}   │                 │               │           │
    │                │ 注入Request      │                 │               │           │
    │                │                  │                 │               │           │
    │                │ next(request) ──>│                 │               │           │
    │                │                  │                 │               │           │
    │                │                  │ 5. 查JWT黑名单  │               │           │
    │                │                  │ GET jwt_blacklist:{jti}         │           │
    │                │                  │───────────────────────────────> │           │
    │                │                  │                 │         null  │           │
    │                │                  │  <──────────────────────────────│           │
    │                │                  │ → 不在黑名单，放行              │           │
    │                │                  │ → 在黑名单: 401 (code: 40104)  │           │
    │                │                  │                 │               │           │
    │                │                  │ next(request) ─>│               │           │
    │                │                  │                 │               │           │
    │                │                  │                 │ 6. [可选]查库  │           │
    │                │                  │                 │ 检查用户状态   │           │
    │                │                  │                 │ SELECT status  │           │
    │                │                  │                 │ FROM users     │           │
    │                │                  │                 │ WHERE id=?     │           │
    │                │                  │                 │               │           │
    │                │                  │                 │ → status=0:   │           │
    │                │                  │                 │   401(40105)  │           │
    │                │                  │                 │               │           │
    │                │                  │                 │ → DB异常:     │           │
    │                │                  │                 │   跳过，记录   │           │
    │                │                  │                 │   告警日志，   │           │
    │                │                  │                 │   信任JWT数据  │           │
    │                │                  │                 │               │           │
    │                │                  │                 │ next(request)────────────>│
    │                │                  │                 │               │           │
    │                │                  │                 │               │  处理业务  │
    │                │                  │                 │               │           │
    │  200 OK {code: 0, data: {...}}   │                 │               │           │
    │<──────────────────────────────────────────────────────────────────────────────│
```

**认证决策流程图：**

```
                    ┌──────────────────┐
                    │  收到API请求      │
                    │  Authorization:  │
                    │  Bearer {jwt}    │
                    └────────┬─────────┘
                             │
                    ┌────────▼─────────┐
                    │  JWT 验签         │
                    │  (纯计算,不查库)  │
                    └────────┬─────────┘
                             │
                   ┌─────────▼──────────┐
                   │  签名有效?          │
                   └─────────┬──────────┘
                        NO / │ \ YES
                       ┌─────┘  └─────┐
                       ▼              ▼
                 ┌───────────┐  ┌───────────┐
                 │ 401 未认证│  │ Token过期? │
                 └───────────┘  └─────┬─────┘
                                 YES / │ \ NO
                                ┌─────┘  └──────┐
                                ▼               ▼
                         ┌───────────┐   ┌─────────────┐
                         │401 (40101)│   │查Redis黑名单 │
                         │Token过期  │   │jwt_blacklist │
                         │→客户端应  │   │  :{jti}      │
                         │ 刷新Token │   └──────┬──────┘
                         └───────────┘    HIT / │ \ MISS
                                        ┌─────┘  └──────┐
                                        ▼               ▼
                                 ┌───────────┐   ┌───────────────┐
                                 │401 (40104)│   │[可选] 查库     │
                                 │设备被踢   │   │检查用户状态    │
                                 └───────────┘   └───────┬───────┘
                                                    OK / │ \ 禁用
                                                   ┌────┘  └────┐
                                                   ▼            ▼
                                             ┌──────────┐ ┌──────────┐
                                             │ 放行     │ │401(40105)│
                                             │→Controller│ │账号禁用  │
                                             └──────────┘ └──────────┘
```

#### 2.4.5 Token 刷新（详细设计）

##### 为什么需要刷新

```
问题：Access Token 有效期只有 2h，用户不可能每 2h 重新登录一次
解决：用长期有效的 Refresh Token（30天）来静默获取新的 Access Token
效果：用户感知上 30 天内免登录，实际 Access Token 每 2h 轮换一次（安全+体验兼得）
```

##### 刷新触发时机

```
┌────────────────────────────────────────────────────────────────┐
│                     Access Token 生命线                         │
│                                                                │
│  签发         正常使用                    过期      刷新        │
│  ├──────────────────────────────────────────┤                  │
│  T0                                        T0+2h              │
│  │                                          │                  │
│  │  这段时间内所有请求正常通过               │                  │
│  │  JWT 验签 → 黑名单检查 → 放行            │                  │
│  │                                          │                  │
│  │                                          ▼                  │
│  │                                    请求收到 401              │
│  │                                    code: 40101              │
│  │                                    "Token已过期"            │
│  │                                          │                  │
│  │                                          ▼                  │
│  │                                    客户端自动触发刷新        │
│  │                                    POST /auth/refresh       │
│  │                                    {refresh_token: "xxx"}   │
│  │                                          │                  │
│  │                                          ▼                  │
│  │                                    获得新的双Token           │
│  │                                    重试原始请求              │
│  │                                          │                  │
│  │  ├──────────────────────────────────────────┤               │
│  │  T0+2h          新的 2h 周期               T0+4h            │
│  │                                                             │
│  │  ... 循环往复，直到 Refresh Token 30天过期 ...               │
│  │                                                             │
└────────────────────────────────────────────────────────────────┘
```

##### 服务端刷新完整流程

**时序图 — Token 刷新：**

```
┌────────┐       ┌────────────┐      ┌──────────┐      ┌───────┐     ┌───────┐
│ Client │       │ TokenCtrl  │      │AuthService│      │ MySQL │     │ Redis │
└───┬────┘       └─────┬──────┘      └────┬─────┘      └───┬───┘     └───┬───┘
    │                  │                   │                │             │
    │ Access Token 过期 (前端收到401 code:40101)             │             │
    │                  │                   │                │             │
    │ POST /auth/refresh                   │                │             │
    │ Body: {refresh_token: "f8a3b7c1..."}│                │             │
    │─────────────────>│                   │                │             │
    │                  │                   │                │             │
    │                  │ refresh(token)    │                │             │
    │                  │──────────────────>│                │             │
    │                  │                   │                │             │
    │                  │                   │ 1. 计算哈希查库│             │
    │                  │                   │ hash = SHA256(raw_token)     │
    │                  │                   │ SELECT * FROM user_tokens    │
    │                  │                   │ WHERE token = {hash}         │
    │                  │                   │───────────────>│             │
    │                  │                   │  token_record  │             │
    │                  │                   │<───────────────│             │
    │                  │                   │                │             │
    │                  │                   │ → 未找到: 401 (40102)        │
    │                  │                   │ → 已过期: 401 (40102)        │
    │                  │                   │                │             │
    │                  │                   │ 2. 泄露检测（详见 2.4.7）    │
    │                  │                   │ → 检测到泄露: 401 (40103)    │
    │                  │                   │                │             │
    │                  │                   │ 3. 检测通过，签发新Token     │
    │                  │                   │                │             │
    │                  │                   │ 3a. 生成新 Access Token      │
    │                  │                   │ JWT.sign({sub, jti:new, plat, exp:+2h})
    │                  │                   │                │             │
    │                  │                   │ 3b. 旋转 Refresh Token       │
    │                  │                   │ new_raw = random(64)         │
    │                  │                   │ UPDATE user_tokens SET       │
    │                  │                   │   token=SHA256(new_raw),     │
    │                  │                   │   client_ip=当前IP,          │
    │                  │                   │   user_agent=当前UA,         │
    │                  │                   │   last_active_at=now         │
    │                  │                   │ WHERE id = ?   │             │
    │                  │                   │───────────────>│             │
    │                  │                   │                │             │
    │                  │ {access_token(新), refresh_token(新), expires_in}│
    │                  │<──────────────────│                │             │
    │                  │                   │                │             │
    │  200 OK          │                   │                │             │
    │  {access_token, refresh_token, expires_in: 7200}     │             │
    │<─────────────────│                   │                │             │
    │                  │                   │                │             │
    │ 客户端丢弃旧双Token，保存新双Token    │                │             │
```

##### Refresh Token 旋转（Rotation）

```
为什么要旋转？
  每次刷新时不仅签发新 Access Token，还签发新 Refresh Token，旧的立即失效。

好处：
  1. 缩小 Refresh Token 泄露窗口（即使被盗，用一次就失效）
  2. 配合泄露检测：如果旧 Token 再次被使用，说明存在两方持有同一 Token

旋转流程：
  ┌─ 刷新前 ──────────────────────────────────────┐
  │ user_tokens: {token: SHA256(refresh_A), ...}  │
  │ 客户端持有: refresh_A                          │
  └───────────────────────────────────────────────┘
                        │
                     刷新请求
                        │
                        ▼
  ┌─ 刷新后 ──────────────────────────────────────┐
  │ user_tokens: {token: SHA256(refresh_B), ...}  │  ← 库里更新为新哈希
  │ 客户端持有: refresh_B                          │  ← 客户端保存新值
  │ refresh_A: 彻底失效，无法再使用                │
  └───────────────────────────────────────────────┘
```

##### 客户端刷新策略

```
策略一：被动刷新（推荐）
  ├── API 请求收到 401 + code:40101 (Token过期)
  ├── 拦截器自动用 Refresh Token 调用 /auth/refresh
  ├── 获取新双Token，重试原始请求
  └── 刷新失败（40102/40103）→ 清除本地Token → 跳转登录页

策略二：主动刷新（可选增强）
  ├── 客户端记录 Access Token 的 exp 时间
  ├── 在过期前 5 分钟主动发起刷新
  └── 避免用户感知到 Token 过期的短暂中断

并发刷新防护（重要）：
  场景：页面同时发出 5 个 API 请求，Access Token 刚好过期
       5 个请求都收到 401，如果都发起刷新请求 → 只有第一个成功（旋转后旧 Token 失效）

  解决方案（客户端 Axios 拦截器）：
  ┌─────────────────────────────────────────────┐
  │  let isRefreshing = false                   │
  │  let pendingRequests = []                   │
  │                                             │
  │  interceptor(error):                        │
  │    if error.code == 40101:                  │
  │      if isRefreshing:                       │
  │        // 排队等待                           │
  │        return new Promise(resolve =>         │
  │          pendingRequests.push(resolve)       │
  │        )                                    │
  │      else:                                  │
  │        isRefreshing = true                  │
  │        newTokens = await refresh()          │
  │        isRefreshing = false                 │
  │        // 通知所有排队的请求用新Token重试     │
  │        pendingRequests.forEach(cb => cb())   │
  │        pendingRequests = []                 │
  │        return retry(originalRequest)        │
  └─────────────────────────────────────────────┘
```

##### 刷新失败场景汇总

```
┌─────────────────────┬──────────┬───────────────────────────────┐
│ 失败原因             │ 错误码   │ 客户端处理                     │
├─────────────────────┼──────────┼───────────────────────────────┤
│ Refresh Token 不存在 │ 40102    │ 清除本地Token → 跳转登录页    │
│ Refresh Token 已过期 │ 40102    │ 清除本地Token → 跳转登录页    │
│ 泄露检测触发         │ 40103    │ 清除本地Token → 跳转登录页    │
│                     │          │ + 显示安全提示                 │
│ 用户已被封禁         │ 40105    │ 清除本地Token → 显示封禁提示  │
│ 服务器错误           │ 500      │ 保留Token → 稍后重试          │
└─────────────────────┴──────────┴───────────────────────────────┘
```

#### 2.4.6 踢人下线（详细设计）

##### 踢人场景分类

```
┌────────────────────────────────────────────────────────────────┐
│                       踢人下线三种场景                          │
├────────────┬───────────────────────────────────────────────────┤
│            │                                                   │
│ 场景一     │ 同平台登录互踢（自动触发）                          │
│ 自动互踢   │ 张三在 iPhone 登录了 APP                           │
│            │ 张三用 Android 登录 APP → iPhone 被踢              │
│            │ 触发时机：登录接口内部                              │
│            │                                                   │
├────────────┼───────────────────────────────────────────────────┤
│            │                                                   │
│ 场景二     │ 用户主动踢设备（用户操作）                          │
│ 主动踢     │ 张三在安全中心看到"iPhone 15 Pro"正在登录           │
│            │ 点击"下线该设备" → iPhone 被踢                     │
│            │ 触发时机：DELETE /auth/devices/{id}                │
│            │                                                   │
├────────────┼───────────────────────────────────────────────────┤
│            │                                                   │
│ 场景三     │ 管理员强制下线（后台操作）                          │
│ 强制下线   │ 管理员在后台封禁张三的账号                          │
│            │ 张三所有平台所有设备全部下线                        │
│            │ 触发时机：管理后台封禁操作                          │
│            │                                                   │
└────────────┴───────────────────────────────────────────────────┘
```

##### 场景一：同平台登录互踢

**时序图：**

```
┌──────────┐  ┌──────────┐  ┌──────────┐     ┌──────────┐  ┌───────┐  ┌───────┐
│ iPhone   │  │ Android  │  │LoginCtrl │     │AuthService│  │ MySQL │  │ Redis │
│ (旧设备) │  │ (新设备) │  └────┬─────┘     └────┬─────┘  └───┬───┘  └───┬───┘
└────┬─────┘  └────┬─────┘       │                │            │          │
     │             │             │                │            │          │
     │  (张三正在使用iPhone上的APP)                │            │          │
     │             │             │                │            │          │
     │             │ POST /auth/login/password    │            │          │
     │             │ X-Platform: app              │            │          │
     │             │────────────>│                │            │          │
     │             │             │                │            │          │
     │             │             │ login()        │            │          │
     │             │             │───────────────>│            │          │
     │             │             │                │            │          │
     │             │             │                │ 1. 验证密码通过        │
     │             │             │                │            │          │
     │             │             │                │ 2. 查同平台旧Token     │
     │             │             │                │ SELECT id, jti_claim   │
     │             │             │                │ FROM user_tokens       │
     │             │             │                │ JOIN (解析最近签发的JWT)│
     │             │             │                │ WHERE user_id=张三     │
     │             │             │                │   AND platform='app'   │
     │             │             │                │───────────>│          │
     │             │             │                │ [{id:1}]   │          │
     │             │             │                │<───────────│          │
     │             │             │                │            │          │
     │             │             │                │ 3. 即时踢人：旧JWT加黑名单
     │             │             │                │ SET jwt_blacklist:{jti_A} 1
     │             │             │                │ EXPIRE 7200            │
     │             │             │                │─────────────────────>  │
     │             │             │                │            │     OK   │
     │             │             │                │ <─────────────────────│
     │             │             │                │            │          │
     │             │             │                │ 4. 删除旧Refresh Token │
     │             │             │                │ DELETE FROM user_tokens │
     │             │             │                │ WHERE id=1 │          │
     │             │             │                │───────────>│          │
     │             │             │                │            │          │
     │             │             │                │ 5. 为Android签发新Token│
     │             │             │                │ (JWT + Refresh Token)  │
     │             │             │                │            │          │
     │             │             │                │ 6. 记录安全日志        │
     │             │             │                │ {event: "device_kicked",
     │             │             │                │  detail: {kicked_device:
     │             │             │                │  "iPhone", new_device:  │
     │             │             │                │  "Android"}}           │
     │             │             │                │───────────>│          │
     │             │             │                │            │          │
     │             │  200 OK {access_token, refresh_token}     │          │
     │             │<────────────│                │            │          │
     │             │             │                │            │          │
     │ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─  此时 iPhone 上发生了什么  ─ ─ ─ ─│─ ─ ─ ─ ─│
     │             │             │                │            │          │
     │ GET /api/v1/orders (还在使用旧 access_token_A)          │          │
     │──────────────────────────────────────────>               │          │
     │                           │                │            │          │
     │             JwtAuth: 验签通过，未过期       │            │          │
     │             JwtBlacklist: GET jwt_blacklist:jti_A ─────────────────>│
     │                           │                │            │    "1"   │
     │                           │  <─────────────────────────────────────│
     │                           │  在黑名单中！    │            │          │
     │  401 {code:40104, message:"您的账号已在另一台设备登录"}    │          │
     │<──────────────────────────│                │            │          │
     │             │             │                │            │          │
     │ iPhone 清除本地Token      │                │            │          │
     │ 跳转登录页，显示提示      │                │            │          │
```

##### 场景二：用户主动踢设备

**时序图：**

```
┌──────────┐  ┌──────────┐     ┌────────────┐  ┌─────────────┐  ┌───────┐  ┌───────┐
│ iPhone   │  │ PC浏览器 │     │DeviceCtrl  │  │DeviceService│  │ MySQL │  │ Redis │
│ (被踢)   │  │ (操作端) │     └─────┬──────┘  └──────┬──────┘  └───┬───┘  └───┬───┘
└────┬─────┘  └────┬─────┘           │                │             │          │
     │             │                 │                │             │          │
     │             │ 1. 查看在线设备列表              │             │          │
     │             │ GET /auth/devices               │             │          │
     │             │────────────────>│                │             │          │
     │             │                 │ getDevices()   │             │          │
     │             │                 │───────────────>│             │          │
     │             │                 │                │ SELECT *    │          │
     │             │                 │                │ FROM user_tokens       │
     │             │                 │                │ WHERE user_id=张三     │
     │             │                 │                │────────────>│          │
     │             │                 │                │  devices[]  │          │
     │             │                 │                │<────────────│          │
     │             │                 │                │             │          │
     │             │ 200 OK          │                │             │          │
     │             │ [{id:1, platform:"app",          │             │          │
     │             │   device_name:"iPhone 15 Pro",   │             │          │
     │             │   client_ip:"223.x.x.x",        │             │          │
     │             │   last_active:"5分钟前",          │             │          │
     │             │   current: false},               │             │          │
     │             │  {id:2, platform:"pc",           │             │          │
     │             │   device_name:"Chrome/Windows",  │             │          │
     │             │   last_active:"刚刚",             │             │          │
     │             │   current: true}]                │             │          │
     │             │<────────────────│                │             │          │
     │             │                 │                │             │          │
     │             │ 2. 用户点击"下线该设备"          │             │          │
     │             │ DELETE /auth/devices/1           │             │          │
     │             │────────────────>│                │             │          │
     │             │                 │ kickDevice(1)  │             │          │
     │             │                 │───────────────>│             │          │
     │             │                 │                │             │          │
     │             │                 │                │ 3. 查该Token的JWT jti │
     │             │                 │                │ (从最近签发记录推算)    │
     │             │                 │                │             │          │
     │             │                 │                │ 4. JWT加黑名单         │
     │             │                 │                │ SET jwt_blacklist:{jti}│
     │             │                 │                │──────────────────────> │
     │             │                 │                │             │          │
     │             │                 │                │ 5. 删除Refresh Token   │
     │             │                 │                │ DELETE FROM user_tokens │
     │             │                 │                │ WHERE id=1  │          │
     │             │                 │                │────────────>│          │
     │             │                 │                │             │          │
     │             │                 │                │ 6. 记录安全日志        │
     │             │                 │                │ {event: "device_kicked"}
     │             │                 │                │────────────>│          │
     │             │                 │                │             │          │
     │             │  200 OK {message: "设备已下线"}  │             │          │
     │             │<────────────────│                │             │          │
     │             │                 │                │             │          │
     │ (iPhone 下次请求被拦截，同场景一 T2)           │             │          │
```

##### 场景三：管理员封禁账号（全平台强制下线）

```
管理后台操作 → 封禁张三账号

处理步骤：
  1. UPDATE users SET status=0 WHERE id=张三

  2. 查出张三所有在线设备的 Token
     SELECT id FROM user_tokens WHERE user_id=张三
     → [{id:1, platform:app}, {id:2, platform:pc}, {id:3, platform:h5}]

  3. 所有 JWT 的 jti 加入黑名单
     SET jwt_blacklist:{jti_1} 1 EX 7200
     SET jwt_blacklist:{jti_2} 1 EX 7200
     SET jwt_blacklist:{jti_3} 1 EX 7200

  4. 删除所有 Refresh Token
     DELETE FROM user_tokens WHERE user_id=张三

  5. 记录安全日志
     {event: "account_banned", detail: {admin_id: xxx, reason: "xxx"}}

结果：
  APP:   下次请求 → JwtBlacklist 拦截 → 401 (40105)
  PC:    下次请求 → JwtBlacklist 拦截 → 401 (40105)
  H5:    下次请求 → JwtBlacklist 拦截 → 401 (40105)
  所有端同时被踢，无2h延迟
```

##### 踢人核心逻辑（DeviceService 伪代码）

```php
class DeviceService
{
    /**
     * 同平台互踢（登录时调用）
     */
    public function kickSamePlatformDevices(int $userId, string $platform): void
    {
        // 1. 查出同平台所有 token 记录
        $oldTokens = $this->userTokenRepo->getByUserAndPlatform($userId, $platform);

        if ($oldTokens->isEmpty()) {
            return; // 没有旧设备，无需踢人
        }

        // 2. 将旧设备的 JWT jti 加入黑名单（即时生效）
        foreach ($oldTokens as $token) {
            $this->jwtService->addToBlacklist($token->last_jwt_jti, ttl: 7200);
        }

        // 3. 删除旧 Refresh Token（刷新时生效）
        $this->userTokenRepo->deleteByUserAndPlatform($userId, $platform);

        // 4. 记录安全日志
        foreach ($oldTokens as $token) {
            $this->securityLogRepo->log($userId, SecurityEvent::DeviceKicked, [
                'kicked_device' => $token->device_name,
                'kicked_platform' => $token->platform,
            ]);
        }
    }

    /**
     * 用户主动踢指定设备
     */
    public function kickDevice(int $userId, int $tokenId): void
    {
        $token = $this->userTokenRepo->findByIdAndUser($tokenId, $userId);

        if (!$token) {
            throw new ModelNotFoundException('设备不存在');
        }

        // 不允许踢自己当前设备
        if ($token->id === request()->attributes->get('current_token_id')) {
            throw new BadRequestException('不能踢掉当前设备，请使用登出接口');
        }

        $this->jwtService->addToBlacklist($token->last_jwt_jti, ttl: 7200);
        $this->userTokenRepo->delete($token->id);
        $this->securityLogRepo->log($userId, SecurityEvent::DeviceKicked, [
            'kicked_device' => $token->device_name,
        ]);
    }

    /**
     * 管理员封禁（全部踢下线）
     */
    public function kickAllDevices(int $userId): void
    {
        $allTokens = $this->userTokenRepo->getByUser($userId);

        foreach ($allTokens as $token) {
            $this->jwtService->addToBlacklist($token->last_jwt_jti, ttl: 7200);
        }

        $this->userTokenRepo->deleteByUser($userId);
    }
}
```

##### JWT 黑名单存储细节

```
┌─────────────────────────────────────────────────────────────┐
│                    Redis JWT 黑名单                          │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  数据结构: String (最简单高效)                               │
│  Key:      jwt_blacklist:{jti}                              │
│  Value:    1                                                │
│  TTL:      7200 秒 (2h，与 Access Token 有效期一致)         │
│                                                             │
│  为什么 TTL = Access Token 有效期？                          │
│  ├── Access Token 最多存活 2h                               │
│  ├── 2h 后 Token 自然过期，黑名单条目也无存在必要            │
│  └── 自动清理，无需维护                                     │
│                                                             │
│  查询复杂度: O(1)                                           │
│  每次请求额外开销: ~0.1ms (Redis GET)                       │
│                                                             │
│  容量估算:                                                  │
│  ├── 假设每天 1000 次踢人操作                               │
│  ├── 每条记录 ~50 bytes (key + value + TTL metadata)        │
│  ├── 最多同时存在 1000 条（2h TTL 自动清理）                │
│  └── 占用 ~50KB，可忽略不计                                 │
│                                                             │
│  降级策略:                                                  │
│  ├── Redis 不可用时：黑名单检查跳过，记录告警日志           │
│  ├── 此时踢人有最多 2h 延迟（Access Token 到期才失效）      │
│  └── 安全性降级但服务不中断                                 │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

##### 跨平台互踢规则矩阵

```
操作\影响      │ APP Token  │ 小程序 Token │ H5 Token  │ PC Token
───────────────┼────────────┼─────────────┼───────────┼──────────
新APP登录      │ ✗ 旧的失效 │ ✓ 不影响     │ ✓ 不影响  │ ✓ 不影响
新小程序登录   │ ✓ 不影响   │ ✗ 旧的失效   │ ✓ 不影响  │ ✓ 不影响
新H5登录       │ ✓ 不影响   │ ✓ 不影响     │ ✗ 旧的失效│ ✓ 不影响
新PC登录       │ ✓ 不影响   │ ✓ 不影响     │ ✓ 不影响  │ ✗ 旧的失效
用户主动踢APP  │ ✗ 被踢     │ ✓ 不影响     │ ✓ 不影响  │ ✓ 不影响
管理员封禁     │ ✗ 全失效   │ ✗ 全失效     │ ✗ 全失效  │ ✗ 全失效
修改密码       │ ✗ 全失效   │ ✗ 全失效     │ ✗ 全失效  │ ✗ 全失效
```

#### 2.4.7 Token 防盗检测（详细设计）

##### 防盗检测的三道防线

```
┌─────────────────────────────────────────────────────────────────┐
│                    Token 防盗三道防线                            │
│                                                                 │
│  第一道: JWT 签名验证 ──── 防伪造                               │
│  │ 攻击者无法凭空构造有效Token                                  │
│  │ 只有持有 SECRET_KEY 的服务端才能签发                         │
│  │ 检查时机: 每次请求                                          │
│  │ 开销: 纯CPU计算 (~0.05ms)                                   │
│  │                                                              │
│  第二道: JWT 黑名单 ──── 防被踢后的Token继续使用                 │
│  │ 被踢设备的 JWT 立即加入 Redis 黑名单                        │
│  │ 检查时机: 每次请求                                          │
│  │ 开销: Redis GET (~0.1ms)                                    │
│  │                                                              │
│  第三道: IP + UA 环境检测 ──── 防Token被盗后使用                │
│  │ Refresh Token 绑定创建时的 IP 和 User-Agent                 │
│  │ 刷新时对比，不匹配则判定被盗                                 │
│  │ 检查时机: 每次 Token 刷新                                    │
│  │ 开销: 数据库比对 (~1ms)                                     │
│  │                                                              │
└─────────────────────────────────────────────────────────────────┘
```

##### 第三道防线详解：环境指纹检测

**检测策略（按平台差异化）：**

```
┌────────────────────────────────────────────────────────────────────┐
│  为什么不同平台策略不同？                                          │
│                                                                    │
│  PC 用户:                                                         │
│  ├── 通常使用固定宽带，IP 相对稳定                                 │
│  ├── 浏览器 UA 基本不变                                           │
│  ├── IP 或 UA 任一变化 → 高风险                                   │
│  └── 策略: IP变化 OR UA变化 → 触发检测                            │
│                                                                    │
│  移动用户 (APP/H5/小程序):                                        │
│  ├── WiFi ↔ 4G/5G 切换导致 IP 频繁变化（正常行为）               │
│  ├── 跨基站漫游也会导致 IP 变化                                   │
│  ├── 但 UA 不会变（同一设备同一APP版本）                          │
│  ├── 仅 IP 变化 → 正常，不告警                                   │
│  └── 策略: IP变化 AND UA变化 → 触发检测（两个都变才可疑）         │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘
```

**检测决策流程图：**

```
                    ┌───────────────────────┐
                    │  Refresh Token 刷新请求│
                    │  提取当前 IP 和 UA     │
                    └───────────┬───────────┘
                                │
                    ┌───────────▼───────────┐
                    │  从数据库取出该 Token   │
                    │  创建时的 IP 和 UA     │
                    └───────────┬───────────┘
                                │
                    ┌───────────▼───────────┐
                    │  该 Token 的平台是？    │
                    └───────────┬───────────┘
                           ┌────┴────┐
                           │         │
                      PC 平台    移动平台
                           │    (APP/H5/小程序)
                           │         │
                           ▼         ▼
                    ┌────────────┐  ┌────────────────┐
                    │ IP 匹配？  │  │ IP 和 UA       │
                    │ OR         │  │ 同时都不匹配？  │
                    │ UA 匹配？  │  └───────┬────────┘
                    └──────┬─────┘      ┌───┴───┐
                      ┌────┴────┐       │       │
                      │         │      NO      YES
                 任一不匹配   都匹配    │       │
                      │         │       ▼       ▼
                      ▼         ▼    ┌──────┐┌──────────┐
               ┌──────────┐ ┌──────┐│ 放行  ││ 判定泄露 │
               │ 判定泄露  │ │ 放行 │└──────┘└──────────┘
               └──────────┘ └──────┘
```

##### 泄露检测触发后的处理

**时序图 — Token 被盗场景：**

```
前提：
  张三在 PC (IP: 1.1.1.1, UA: Chrome/120) 登录
  攻击者窃取了张三的 Refresh Token

┌──────────┐        ┌──────────┐        ┌──────────┐      ┌───────┐
│ 攻击者   │        │ TokenCtrl│        │AuthService│      │ MySQL │
│IP:9.9.9.9│        └────┬─────┘        └────┬─────┘      └───┬───┘
│UA:Firefox│             │                   │                │
└────┬─────┘             │                   │                │
     │                   │                   │                │
     │ POST /auth/refresh                    │                │
     │ Body:{refresh_token:"stolen_token"}   │                │
     │ (来自不同IP和UA)  │                   │                │
     │──────────────────>│                   │                │
     │                   │                   │                │
     │                   │ refresh(token)    │                │
     │                   │──────────────────>│                │
     │                   │                   │                │
     │                   │                   │ 1. 查库验证Token有效
     │                   │                   │                │
     │                   │                   │ 2. 环境检测
     │                   │                   │ 记录IP: 1.1.1.1 (PC登录时)
     │                   │                   │ 当前IP: 9.9.9.9 (攻击者)
     │                   │                   │ 记录UA: Chrome/120
     │                   │                   │ 当前UA: Firefox/119
     │                   │                   │                │
     │                   │                   │ PC 平台策略:    │
     │                   │                   │ IP不匹配(1.1.1.1≠9.9.9.9)
     │                   │                   │ → 触发泄露检测!  │
     │                   │                   │                │
     │                   │                   │ 3. 泄露处置     │
     │                   │                   │                │
     │                   │                   │ 3a. 删除该Refresh Token
     │                   │                   │ DELETE FROM user_tokens
     │                   │                   │ WHERE id=?     │
     │                   │                   │───────────────>│
     │                   │                   │                │
     │                   │                   │ 3b. 将该Token对应的JWT加黑名单
     │                   │                   │ (防止攻击者用已签发的Access Token)
     │                   │                   │                │
     │                   │                   │ 3c. 记录安全日志
     │                   │                   │ INSERT INTO security_logs
     │                   │                   │ {event:"token_leak",
     │                   │                   │  detail: {
     │                   │                   │   expected_ip: "1.1.1.1",
     │                   │                   │   actual_ip: "9.9.9.9",
     │                   │                   │   expected_ua: "Chrome/120",
     │                   │                   │   actual_ua: "Firefox/119"
     │                   │                   │ }}
     │                   │                   │───────────────>│
     │                   │                   │                │
     │  401 {code: 40103, message: "Token安全验证失败"}       │
     │<──────────────────│                   │                │
     │                   │                   │                │
     │ 攻击者无法获得新Token                  │                │
     │ 攻击者持有的 Access Token 也被加入黑名单│                │
```

**张三（真正的用户）这边会发生什么：**

```
┌──────────┐
│ 张三 PC  │
│IP:1.1.1.1│
└────┬─────┘
     │
     │ Access Token 过期，尝试刷新
     │ POST /auth/refresh {refresh_token: "原始token"}
     │
     │ → Refresh Token 已被删除（在检测到攻击者使用时已删）
     │ → 401 {code: 40102, message: "登录已过期，请重新登录"}
     │
     │ 张三重新登录
     │ POST /auth/login/password {phone, password}
     │
     │ → 登录成功
     │ → 服务端可在响应中附加安全提示:
     │   {
     │     security_notice: "您的账号于 2026-03-25 14:30 在异常环境(IP:9.9.9.9)被使用，
     │                       已自动拦截。如非本人操作，建议修改密码。"
     │   }
```

##### Refresh Token 旋转防重放攻击

```
场景：攻击者窃取了 Refresh Token，但正常用户先使用了它

时间线：
  T0: 张三登录，获得 refresh_token_A

  T1: 攻击者复制了 refresh_token_A

  T2: 张三正常使用，Access Token 过期，用 refresh_token_A 刷新
      → 旋转后获得 refresh_token_B
      → refresh_token_A 已被替换为 refresh_token_B (数据库已更新)

  T3: 攻击者用 refresh_token_A（旧的）尝试刷新
      → SHA256(refresh_token_A) 在库中不存在（已被旋转为 B 的哈希）
      → 401 (40102) 拒绝
      → 攻击失败

  如果攻击者先于用户刷新呢？
  T2': 攻击者用 refresh_token_A 刷新
       → 环境检测：IP/UA 不匹配 → 401 (40103) 泄露告警
       → Token 被删除
       → 张三也需要重新登录，但攻击者也没有获得新 Token
```

##### 防盗能力总结

```
┌──────────────────┬────────────────────┬──────────────────────────┐
│ 攻击场景          │ 防御手段            │ 效果                     │
├──────────────────┼────────────────────┼──────────────────────────┤
│ 伪造 Token       │ JWT HS256 签名验证 │ 完全防御                  │
├──────────────────┼────────────────────┼──────────────────────────┤
│ 窃取 Access Token│ 2h 短有效期        │ 攻击窗口限制在 2h 内     │
│                  │ + JWT 黑名单       │ 踢人后立即失效            │
├──────────────────┼────────────────────┼──────────────────────────┤
│ 窃取Refresh Token│ IP+UA 环境检测     │ 不同环境使用立即检测      │
│                  │ + Token 旋转       │ 用过一次旧的就失效        │
│                  │ + SHA256 哈希存储   │ 数据库泄露也无法使用      │
├──────────────────┼────────────────────┼──────────────────────────┤
│ 数据库泄露       │ Refresh Token 哈希存储│ 攻击者拿到哈希无法反推 │
│                  │ + 密码 bcrypt 哈希 │ 密码也无法反推            │
├──────────────────┼────────────────────┼──────────────────────────┤
│ 中间人攻击       │ HTTPS 强制          │ 传输层加密               │
├──────────────────┼────────────────────┼──────────────────────────┤
│ XSS 窃取         │ Access Token 仅存内存│ 页面刷新即失效          │
│                  │ Refresh Token:     │                          │
│                  │ HttpOnly Cookie(Web)│ JS 无法读取             │
│                  │ Keychain(APP)      │ 系统级安全存储            │
└──────────────────┴────────────────────┴──────────────────────────┘
```

### 2.8 密码策略

**规则：**
- 最小长度 8 位
- 必须同时包含字母和数字
- 不能与最近 3 次使用过的密码相同

**实现：**
- `password_histories` 表记录用户历史密码（哈希存储，最多保留 3 条）
- `PasswordPolicyValidator` 服务统一校验密码规则
- 注册、修改密码、重置密码时均调用校验
- 密码规则可通过 `config/auth.php` 配置化

### 2.9 敏感操作二次验证

**需要二次验证的操作：**
- 修改密码
- 修改/绑定手机号
- 修改支付相关信息
- 删除账号

**验证方式：**
- 短信验证码（主要方式）
- 密码验证（备选，适用于有密码的用户）

**流程：**
```
1. 客户端请求敏感操作前，先调用二次验证接口
   POST /api/v1/auth/verify-identity
   Body: { method: "sms"|"password", code|password: "xxx" }

2. 验证通过后，服务端签发一个短时效的 verify_token
   → 存入缓存: verify_token:{token} → user_id, TTL=5分钟

3. 客户端携带 verify_token 请求敏感操作
   Header: X-Verify-Token: {token}

4. SensitiveOperation 中间件校验 verify_token
   → 有效：放行，用后即删
   → 无效/过期：返回 403 "请先完成身份验证"
```

### 2.10 用户信息脱敏与维护

#### 2.10.1 数据脱敏策略

脱敏只发生在 API 响应层（Resource），Service 层始终操作完整数据。内部逻辑（短信发送、订单地址快照存储等）不受脱敏影响。

```
Controller → Service → Repository（返回完整数据）
                                  ↓
                          API Resource（输出时脱敏）
```

**脱敏规则：**

| 字段 | 原始值 | 规则 | 脱敏结果 |
|------|--------|------|---------|
| 手机号 | `13812345678` | 中间 4 位替换为 `*` | `138****5678` |
| 邮箱 | `zhangsan@qq.com` | `@` 前保留首尾各 1 位，中间用 `*` | `z******n@qq.com` |
| 收货人姓名 | `张三` / `张三丰` | 2 字隐末位；3+ 字保留首尾，中间用 `*` | `张*` / `张*丰` |
| 收货详细地址 | `幸福路88号3单元201` | 保留前半段，后半段替换为 `***` | `幸福路88号***` |

**工具类：**

```php
// app/Support/DataMasker.php
class DataMasker
{
    public static function phone(string $phone): string;
    public static function email(string $email): string;
    public static function name(string $name): string;
    public static function address(string $address): string;
}
```

**场景区分 — 同一 Resource 支持脱敏/完整两种模式：**

| 场景 | 是否脱敏 | 说明 |
|------|---------|------|
| `GET /user/profile` | 脱敏 | 个人资料展示 |
| `GET /user/addresses` | 脱敏 | 列表展示 |
| `GET /user/addresses/{id}` | **不脱敏** | 编辑页面需要完整数据 |
| 订单详情中的地址快照 | 脱敏 | 快照存储完整数据，展示时脱敏 |

Resource 通过 `masked` 标志位控制输出模式：

```php
class UserAddressResource extends JsonResource
{
    private bool $masked = true;

    public static function unmasked($resource): self
    {
        $instance = new self($resource);
        $instance->masked = false;
        return $instance;
    }

    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->masked ? DataMasker::name($this->name) : $this->name,
            'phone'      => $this->masked ? DataMasker::phone($this->phone) : $this->phone,
            'province'   => $this->province,
            'city'       => $this->city,
            'district'   => $this->district,
            'address'    => $this->masked ? DataMasker::address($this->address) : $this->address,
            'is_default' => (bool) $this->is_default,
        ];
    }
}
```

#### 2.10.2 用户信息字段分类

| 分类 | 字段 | 修改条件 |
|------|------|---------|
| 自由修改 | nickname, avatar | 直接提交，仅校验格式 |
| 敏感修改 | phone | 需二次验证（verify_token）+ 新手机号短信验证码 |
| 敏感修改 | password | 有密码：需旧密码；无密码（第三方用户）：需 verify_token |
| 绑定操作 | email | 需发送验证邮件确认 |
| 只读 | id, created_at | 不可修改 |

#### 2.10.3 修改个人信息（自由字段）

`PUT /api/v1/user/profile` 只接受 nickname、avatar 两个自由字段。

```
客户端                               服务端
  │                                   │
  │  PUT /user/profile                │
  │  {nickname, avatar}               │
  │──────────────────────────────────→│
  │                                   │ 1. 校验 nickname 长度 2-50
  │                                   │ 2. 校验 avatar URL 格式
  │                                   │ 3. 更新 users 表
  │                                   │ 4. 清除用户缓存
  │           200 UserResource        │
  │←──────────────────────────────────│
```

#### 2.10.4 更换手机号

更换手机号需要验证旧身份 + 验证新手机号归属，完成后踢掉所有设备重新登录。

```
客户端                               服务端
  │                                   │
  │ 1. POST /auth/verify-identity     │
  │    {method:"sms", phone:"旧手机号"}│
  │──────────────────────────────────→│
  │    {verify_token: "xxx"}          │  签发 verify_token (5min TTL)
  │←──────────────────────────────────│
  │                                   │
  │ 2. POST /user/phone/change        │
  │    Header: X-Verify-Token: xxx    │
  │    {new_phone, sms_code}          │
  │──────────────────────────────────→│
  │                                   │ a. SensitiveOperation 中间件校验 verify_token
  │                                   │ b. 校验 sms_code 与 new_phone 匹配
  │                                   │ c. 检查 new_phone 未被其他账号占用
  │                                   │ d. 事务：UPDATE users SET phone=new_phone
  │                                   │ e. 踢掉该用户所有设备（手机号是核心身份标识）
  │                                   │ f. 签发新 Token 对（Access + Refresh）
  │                                   │ g. 记录 security_log (action=phone_changed)
  │    200 {access_token, refresh_token}
  │←──────────────────────────────────│
```

> 踢掉所有设备的原因：手机号是核心身份标识，变更后旧 Token 中的身份上下文已过时，需要全部重新认证。

#### 2.10.5 设置/修改密码

```
场景A：已有密码 → 修改密码（POST /auth/password/change）
  请求：{old_password, new_password, new_password_confirmation}
  校验：
    1. 旧密码正确
    2. 新密码符合策略（≥8位，字母+数字）
    3. 不与最近 3 次密码重复（password_histories 表）
  后续：记录 security_log (action=password_changed)

场景B：无密码（第三方登录用户）→ 首次设置密码（POST /auth/password/set）
  请求：Header X-Verify-Token + {password, password_confirmation}
  校验：
    1. verify_token 有效
    2. 当前用户 password 字段为 NULL
    3. 新密码符合策略
  后续：记录 security_log (action=password_set)
```

#### 2.10.6 敏感字段变更审计

所有敏感字段变更记入 `security_logs` 表：

| action | 触发条件 | 记录内容 |
|--------|---------|---------|
| `phone_changed` | 更换手机号 | old_phone → new_phone |
| `password_changed` | 修改密码 | 仅记录事件，不记录密码 |
| `password_set` | 首次设置密码 | 仅记录事件 |
| `email_bound` | 绑定邮箱 | email 地址 |

#### 2.10.7 UserResource 输出规范

```php
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'nickname'       => $this->nickname,
            'avatar'         => $this->avatar,
            'phone'          => DataMasker::phone($this->phone),
            'email'          => $this->email ? DataMasker::email($this->email) : null,
            'has_password'   => (bool) $this->password, // 不暴露密码，只告知是否已设置
            'phone_verified' => (bool) $this->phone_verified_at,
            'email_verified' => (bool) $this->email_verified_at,
            'created_at'     => $this->created_at?->toDateTimeString(),
        ];
    }
}
```

永远不在响应中返回 password、第三方 access_token/refresh_token 等敏感字段。`has_password` 布尔值用于前端判断是"修改密码"还是"设置密码"流程。

### 2.11 收货地址维护（详细设计）

#### 2.11.1 业务规则

| 规则 | 说明 |
|------|------|
| 地址上限 | 每个用户最多 **20** 条收货地址 |
| 默认地址 | 有且仅有 1 条默认地址 |
| 自动默认 | 用户的第一条地址自动设为默认 |
| 默认补位 | 删除默认地址后，自动将最近修改的地址设为默认 |
| 物理删除 | 地址删除为物理删除（不软删），历史订单通过 `address_snapshot` 保留 |
| 省市区数据 | 客户端本地维护行政区划字典（uniapp/flutter 内置地区选择器），服务端只校验非空 |
| 订单快照 | 下单时完整复制地址到 `orders.address_snapshot` JSON，地址后续修改/删除不影响历史订单 |

#### 2.11.2 字段校验规则

| 字段 | 规则 | 说明 |
|------|------|------|
| name | 必填，2-50 字符 | 收货人姓名 |
| phone | 必填，正则 `/^1[3-9]\d{9}$/` | 收货手机号 |
| province | 必填 | 由客户端地区选择器提供 |
| city | 必填 | 由客户端地区选择器提供 |
| district | 必填 | 由客户端地区选择器提供 |
| address | 必填，5-255 字符 | 详细地址（楼栋门牌号等） |
| is_default | 可选，布尔值，默认 false | 是否设为默认地址 |

#### 2.11.3 接口详细设计

**（1）获取地址列表 `GET /user/addresses`**

```
客户端                                   服务端
  │                                       │
  │  GET /user/addresses                  │
  │──────────────────────────────────────→│
  │                                       │ 查询当前用户所有地址
  │                                       │ ORDER BY is_default DESC, updated_at DESC
  │     200 [{脱敏地址}, ...]              │ 默认地址排第一
  │←──────────────────────────────────────│
```

返回脱敏的 `UserAddressResource` 集合（name/phone/address 脱敏）。

**（2）获取单条地址 `GET /user/addresses/{id}`（编辑用，不脱敏）**

```
客户端                                   服务端
  │                                       │
  │  GET /user/addresses/42               │
  │──────────────────────────────────────→│
  │                                       │ 1. 查询 WHERE id=42 AND user_id=当前用户
  │                                       │ 2. 不匹配返回 404（防越权）
  │     200 {完整地址}                     │ 使用 UserAddressResource::unmasked()
  │←──────────────────────────────────────│
```

**（3）创建地址 `POST /user/addresses`**

```
客户端                                   服务端
  │                                       │
  │  POST /user/addresses                 │
  │  {name, phone, province, city,        │
  │   district, address, is_default}      │
  │──────────────────────────────────────→│
  │                                       │ 1. 校验字段格式
  │                                       │ 2. 检查地址数量 ≤ 20，否则 422
  │                                       │ 3. 事务：
  │                                       │    a. 若 is_default=true 或无其他地址：
  │                                       │       清除旧默认
  │                                       │    b. INSERT 新地址
  │     201 {脱敏地址}                     │
  │←──────────────────────────────────────│
```

创建逻辑伪代码：

```php
// AddressService::create()
public function create(int $userId, array $data): UserAddress
{
    $count = $this->addressRepo->countByUser($userId);
    if ($count >= 20) {
        throw new AddressLimitExceededException();
    }

    // 第一条地址自动设为默认
    $isDefault = $data['is_default'] ?? ($count === 0);

    return DB::transaction(function () use ($userId, $data, $isDefault) {
        if ($isDefault) {
            $this->addressRepo->clearDefault($userId);
        }
        return $this->addressRepo->create([
            ...$data,
            'user_id'    => $userId,
            'is_default' => $isDefault,
        ]);
    });
}
```

**（4）修改地址 `PUT /user/addresses/{id}`**

```
客户端                                   服务端
  │                                       │
  │  PUT /user/addresses/42               │
  │  {name, phone, province, city,        │
  │   district, address, is_default}      │
  │──────────────────────────────────────→│
  │                                       │ 1. 校验字段 + 归属校验
  │                                       │ 2. 若 is_default: false → true：
  │                                       │    事务：清除旧默认 + 设置新默认
  │                                       │ 3. 若 is_default: true → false：
  │                                       │    拒绝（422 不允许取消唯一默认）
  │                                       │ 4. UPDATE 地址记录
  │     200 {脱敏地址}                     │
  │←──────────────────────────────────────│
```

默认地址保护规则：
- 设置新默认：允许，自动取消旧默认
- 取消当前唯一默认：拒绝，至少保持一个默认地址

**（5）删除地址 `DELETE /user/addresses/{id}`**

```
客户端                                   服务端
  │                                       │
  │  DELETE /user/addresses/42            │
  │──────────────────────────────────────→│
  │                                       │ 1. 归属校验
  │                                       │ 2. 物理删除地址
  │                                       │ 3. 若删除的是默认地址且还有其他地址：
  │                                       │    自动将最近更新的地址设为默认
  │     204 No Content                    │
  │←──────────────────────────────────────│
```

删除后自动补位伪代码：

```php
// AddressService::delete()
public function delete(int $userId, int $addressId): void
{
    $address = $this->addressRepo->findByUserOrFail($userId, $addressId);

    DB::transaction(function () use ($userId, $address) {
        $this->addressRepo->delete($address->id);

        if ($address->is_default) {
            $next = $this->addressRepo->latestByUser($userId);
            $next?->update(['is_default' => true]);
        }
    });
}
```

**（6）设为默认地址 `PATCH /user/addresses/{id}/default`**

列表页"设为默认"按钮的快捷接口，无需提交其他字段：

```
客户端                                   服务端
  │                                       │
  │  PATCH /user/addresses/42/default     │
  │──────────────────────────────────────→│
  │                                       │ 1. 归属校验
  │                                       │ 2. 事务：清除旧默认 + 设置新默认
  │     200 {脱敏地址}                     │
  │←──────────────────────────────────────│
```

#### 2.11.4 订单地址快照

下单时序列化完整地址存入 `orders.address_snapshot`：

```json
{
    "name": "张三丰",
    "phone": "13812345678",
    "province": "浙江省",
    "city": "杭州市",
    "district": "西湖区",
    "address": "幸福路88号3单元201",
    "full_address": "浙江省杭州市西湖区幸福路88号3单元201"
}
```

- **存储**：完整数据（不脱敏），保证售后退货、物流发货等内部流程可用
- **展示**：`OrderResource` 对快照中 name/phone/address 脱敏输出
- **隔离**：地址修改/删除不影响已有订单快照

#### 2.11.5 客户端省市区数据说明

APP 端使用 uniapp 或 flutter，省市区数据由客户端本地维护：

- **uniapp**：使用 `uni.chooseLocation()` 或 `picker` 组件 mode="multiSelector" 配合本地行政区划 JSON
- **flutter**：使用 `city_pickers` 等社区包或自定义省市区三级联动

服务端职责边界：
- **不提供**省市区字典接口（客户端本地数据足够，且变更频率极低）
- **只校验**省/市/区字段非空
- **只存储**文本值（不存储行政区划编码，避免编码标准变更导致的维护成本）

> 若后续需要按区域计算运费（运费模板匹配），在 shipping_templates 中同样使用省市区文本匹配即可。

### 3.1 模块划分

```
商城系统
├── 用户认证模块 (Auth)        → 注册/登录/Token/设备/安全
├── 商品模块 (Product)         → 分类/SPU/SKU/属性/搜索
├── 营销模块 (Promotion)       → 促销活动/优惠券/责任链优惠计算
├── 购物车模块 (Cart)          → 加购/合并/选中/失效检测/结算
├── 订单模块 (Order)           → 下单/支付/状态机/列表/详情/退款
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

### 3.4 支付系统详细设计（模板方法 + 策略模式）

支付是订单生命周期的一部分，归属于订单模块。核心挑战：2 种支付渠道（微信/支付宝） × 4 种客户端场景（APP/小程序/H5/PC）= 8 种组合，每种的参数构建、API 调用、回调验签、客户端响应格式都不同。

#### 3.4.1 支付渠道 × 场景矩阵

| 渠道＼场景 | APP | 小程序 | H5 | PC |
|-----------|-----|--------|-----|-----|
| **微信** | APP支付 | JSAPI小程序支付 | H5支付 | Native扫码支付 |
| **支付宝** | APP支付 | 小程序支付 | WAP支付 | 电脑网站支付 |

每种组合对应一个 driver 名称，由 `{gateway}_{scene}` 拼接：

| driver 名称 | 类名 | 客户端返回 |
|-------------|------|-----------|
| `wechat_app` | WechatAppPayment | APP SDK 调起参数（prepay_id + 签名） |
| `wechat_mini` | WechatMiniPayment | 小程序 wx.requestPayment 参数 |
| `wechat_h5` | WechatH5Payment | 微信 H5 跳转 URL |
| `wechat_pc` | WechatNativePayment | code_url（前端生成二维码） |
| `alipay_app` | AlipayAppPayment | APP SDK 签名串（orderStr） |
| `alipay_mini` | AlipayMiniPayment | tradeNO（小程序 tradePay 参数） |
| `alipay_h5` | AlipayWapPayment | WAP 跳转 URL |
| `alipay_pc` | AlipayPagePayment | 自提交表单 HTML 或跳转 URL |

#### 3.4.2 模板方法模式 — 支付流程骨架

`AbstractPaymentGateway` 抽象基类定义三个模板方法，每个方法内按固定步骤调用通用方法（final）和抽象方法：

```
AbstractPaymentGateway
│
├── pay($order): PayResult  ──── 模板方法 ─────────────
│   ├── validateOrder()              通用（final）：校验订单状态为 pending
│   ├── createPaymentRecord()        通用（final）：写入 payments 表，状态 pending
│   ├── buildPayParams()             抽象：各渠道+场景构建不同的请求参数
│   ├── callGatewayApi()             抽象：调用第三方统一下单/交易创建 API
│   └── formatClientResponse()       抽象：将 API 返回转为客户端需要的格式
│
├── handleCallback($request): string ──── 模板方法 ──
│   ├── verifySignature()            抽象：微信v3验签 / 支付宝RSA2验签
│   ├── parseCallbackData()          抽象：微信AES解密 / 支付宝表单解析
│   ├── processPaymentResult()       通用（final）：事务更新 payment+order 状态
│   └── buildCallbackResponse()      抽象：微信 JSON / 支付宝 "success"
│
└── refund($payment, $amount): RefundResult ──── 模板方法 ──
    ├── validateRefundable()         通用（final）：校验支付状态、退款金额
    ├── buildRefundParams()          抽象：各渠道退款参数格式
    ├── callRefundApi()              抽象：调用退款 API
    └── saveRefundRecord()           通用（final）：更新 payment 退款字段
```

#### 3.4.3 类继承结构

三层继承：**接口** → **抽象基类**（模板方法+通用逻辑） → **渠道通用层**（验签/解密/退款） → **场景实现**（参数差异）

```
PaymentGatewayInterface（接口契约）
│   pay(Order $order): PayResult
│   handleCallback(Request $request): string
│   refund(Payment $payment, float $amount): RefundResult
│
└── AbstractPaymentGateway（抽象基类 — 模板方法 + 通用逻辑）
    │   # final 通用方法
    │   validateOrder()
    │   createPaymentRecord()
    │   processPaymentResult()
    │   validateRefundable()
    │   saveRefundRecord()
    │
    ├── AbstractWechatPayment（微信渠道通用层）
    │   │   verifySignature()         微信支付 v3 AEAD_AES_256_GCM 验签
    │   │   parseCallbackData()       AES-256-GCM 解密回调报文
    │   │   buildCallbackResponse()   return json_encode(["code" => "SUCCESS"])
    │   │   buildRefundParams()       微信退款参数（out_refund_no, amount 等）
    │   │   callRefundApi()           POST /v3/refund/domestic/refunds
    │   │
    │   ├── WechatAppPayment          buildPayParams: appid+prepay_id+签名
    │   │                             callGatewayApi: POST /v3/pay/transactions/app
    │   │                             formatClientResponse: {prepay_id, sign, timestamp, ...}
    │   │
    │   ├── WechatMiniPayment         callGatewayApi: POST /v3/pay/transactions/jsapi
    │   │                             formatClientResponse: {timeStamp, nonceStr, package, ...}
    │   │
    │   ├── WechatH5Payment           callGatewayApi: POST /v3/pay/transactions/h5
    │   │                             formatClientResponse: {h5_url: "https://wx.tenpay.com/..."}
    │   │
    │   └── WechatNativePayment       callGatewayApi: POST /v3/pay/transactions/native
    │                                 formatClientResponse: {code_url: "weixin://wxpay/..."}
    │
    └── AbstractAlipayPayment（支付宝渠道通用层）
        │   verifySignature()         RSA2 (SHA256WithRSA) 公钥验签
        │   parseCallbackData()       解析 POST 表单参数
        │   buildCallbackResponse()   return "success"
        │   buildRefundParams()       支付宝退款参数（out_request_no, refund_amount 等）
        │   callRefundApi()           alipay.trade.refund
        │
        ├── AlipayAppPayment          callGatewayApi: alipay.trade.app.pay
        │                             formatClientResponse: {order_str: "签名串"}
        │
        ├── AlipayMiniPayment         callGatewayApi: alipay.trade.create
        │                             formatClientResponse: {trade_no: "支付宝交易号"}
        │
        ├── AlipayWapPayment          callGatewayApi: alipay.trade.wap.pay
        │                             formatClientResponse: {pay_url: "跳转URL"}
        │
        └── AlipayPagePayment         callGatewayApi: alipay.trade.page.pay
                                      formatClientResponse: {pay_url: "跳转URL"}
```

> 每个最底层场景类只需实现 3 个方法：`buildPayParams()` + `callGatewayApi()` + `formatClientResponse()`，渠道通用的验签/解密/退款由中间层处理。

#### 3.4.4 PaymentManager — 策略解析

类似 Laravel 的 Manager 模式，根据支付方式 + 客户端平台自动选择 driver：

```php
class PaymentManager
{
    /**
     * 根据支付渠道 + 客户端平台解析对应的支付策略实现
     */
    public function resolve(string $gateway, string $platform): PaymentGatewayInterface
    {
        $scene = $this->mapPlatformToScene($platform);
        $driver = $gateway . '_' . $scene;
        return $this->createDriver($driver);
    }

    private function mapPlatformToScene(string $platform): string
    {
        return match ($platform) {
            'app'          => 'app',
            'mini_program' => 'mini',
            'h5'           => 'h5',
            'pc'           => 'pc',
            default        => throw new UnsupportedPaymentException("不支持的平台: {$platform}"),
        };
    }

    private function createDriver(string $driver): PaymentGatewayInterface
    {
        return match ($driver) {
            'wechat_app'  => new WechatAppPayment($this->wechatConfig),
            'wechat_mini' => new WechatMiniPayment($this->wechatConfig),
            'wechat_h5'   => new WechatH5Payment($this->wechatConfig),
            'wechat_pc'   => new WechatNativePayment($this->wechatConfig),
            'alipay_app'  => new AlipayAppPayment($this->alipayConfig),
            'alipay_mini' => new AlipayMiniPayment($this->alipayConfig),
            'alipay_h5'   => new AlipayWapPayment($this->alipayConfig),
            'alipay_pc'   => new AlipayPagePayment($this->alipayConfig),
            default       => throw new UnsupportedPaymentException("不支持的支付方式: {$driver}"),
        };
    }
}
```

#### 3.4.5 支付发起时序图

```
客户端                   OrderService           PaymentManager        WechatAppPayment         微信API
  │                          │                      │                      │                      │
  │ POST /orders/{no}/pay   │                      │                      │                      │
  │ {gateway: "wechat"}     │                      │                      │                      │
  │────────────────────────→│                      │                      │                      │
  │                          │                      │                      │                      │
  │                          │ resolve("wechat",   │                      │                      │
  │                          │   request.platform) │                      │                      │
  │                          │─────────────────────→│                      │                      │
  │                          │  WechatAppPayment   │                      │                      │
  │                          │←─────────────────────│                      │                      │
  │                          │                      │                      │                      │
  │                          │ pay($order) ────────────────────────────────→│                      │
  │                          │                      │                      │                      │
  │                          │                      │     ┌────── 模板方法执行 ──────┐             │
  │                          │                      │     │ 1. validateOrder()       │             │
  │                          │                      │     │    校验 order.status=pending            │
  │                          │                      │     │ 2. createPaymentRecord() │             │
  │                          │                      │     │    INSERT payments 表     │             │
  │                          │                      │     │ 3. buildPayParams()      │             │
  │                          │                      │     │    构建APP支付参数        │             │
  │                          │                      │     │ 4. callGatewayApi()      │             │
  │                          │                      │     └────────────────────────────→ 统一下单   │
  │                          │                      │                      │        │ prepay_id    │
  │                          │                      │     ┌────────────────←────────│              │
  │                          │                      │     │ 5. formatClientResponse()│             │
  │                          │                      │     │    组装 SDK 调起参数      │             │
  │                          │                      │     └──────────────────────────┘             │
  │                          │                      │                      │                      │
  │  200 {appId, prepayId,  │←─────────────────────────────────────────────│                      │
  │   sign, timeStamp, ...} │                      │                      │                      │
  │←────────────────────────│                      │                      │                      │
  │                          │                      │                      │                      │
  │ 调起微信SDK支付           │                      │                      │                      │
```

#### 3.4.6 回调处理时序图

```
微信/支付宝          CallbackController        PaymentManager       AbstractWechatPayment      DB
      │                    │                       │                      │                      │
      │ POST /payments/    │                       │                      │                      │
      │  notify/wechat     │                       │                      │                      │
      │───────────────────→│                       │                      │                      │
      │                    │ resolveForCallback     │                      │                      │
      │                    │  ("wechat")            │                      │                      │
      │                    │──────────────────────→│                      │                      │
      │                    │  AbstractWechat...    │                      │                      │
      │                    │←──────────────────────│                      │                      │
      │                    │                       │                      │                      │
      │                    │ handleCallback($req) ────────────────────────→│                      │
      │                    │                       │                      │                      │
      │                    │                       │  ┌───── 模板方法执行 ─────┐                  │
      │                    │                       │  │ 1. verifySignature()   │                  │
      │                    │                       │  │    微信v3 AEAD验签     │                  │
      │                    │                       │  │ 2. parseCallbackData() │                  │
      │                    │                       │  │    AES-256-GCM 解密    │                  │
      │                    │                       │  │ 3. processPayResult()  │                  │
      │                    │                       │  │    事务：              ─────────────────────→│
      │                    │                       │  │    UPDATE payments     │    BEGIN TX       │
      │                    │                       │  │     SET status='paid'  │    UPDATE...      │
      │                    │                       │  │    UPDATE orders       │    COMMIT         │
      │                    │                       │  │     SET status='paid'  │←──────────────────│
      │                    │                       │  │ 4. buildCallbackResp() │                  │
      │                    │                       │  │    {"code":"SUCCESS"}  │                  │
      │                    │                       │  └──────────────────────────┘                │
      │                    │                       │                      │                      │
      │ {"code":"SUCCESS"} │←──────────────────────────────────────────────│                      │
      │←───────────────────│                       │                      │                      │
```

#### 3.4.7 退款流程

退款由售后模块触发，通过 PaymentManager 调用对应渠道的退款 API：

```
AfterSaleService            PaymentManager         AbstractWechatPayment        微信API
      │                          │                       │                        │
      │ 售后审核通过，发起退款     │                       │                        │
      │ refund($payment, $amount)│                       │                        │
      │─────────────────────────→│                       │                        │
      │                          │ resolve by             │                        │
      │                          │ payment.pay_scene      │                        │
      │                          │──────────────────────→│                        │
      │                          │                       │                        │
      │                          │ refund() ─────────────→│                        │
      │                          │                       │ ┌─── 模板方法 ────┐     │
      │                          │                       │ │ validateRefundable│    │
      │                          │                       │ │ buildRefundParams │    │
      │                          │                       │ │ callRefundApi  ────────→│
      │                          │                       │ │                │  成功  │
      │                          │                       │ │ saveRefundRecord←──────│
      │                          │                       │ └──────────────────┘     │
      │       RefundResult       │←──────────────────────│                        │
      │←─────────────────────────│                       │                        │
```

> 退款时通过 `payment.pay_scene` 字段（如 `wechat_app`）自动解析对应的渠道实现，无需关心原始支付场景差异——因为同一渠道（微信/支付宝）的退款 API 是统一的，由渠道通用层 `AbstractWechatPayment` / `AbstractAlipayPayment` 处理。

#### 3.4.8 回调幂等性与并发安全

```
回调请求到达
    │
    ├── 1. 验签（verifySignature）
    │      失败 → 返回验签失败响应
    │
    ├── 2. 解析交易号，查询 payment 记录
    │      找不到 → 返回失败
    │
    ├── 3. 检查 payment.status
    │      已是 paid → 直接返回成功（幂等）
    │
    └── 4. 数据库事务 + 悲观锁
           SELECT payments WHERE payment_no=? FOR UPDATE
           │
           ├── 再次检查 status（双重检查，防并发）
           │    已是 paid → COMMIT，返回成功
           │
           └── UPDATE payments SET status='paid', gateway_trade_no=?, paid_at=NOW()
               UPDATE orders SET status='paid', payment_method=?, paid_at=NOW()
               COMMIT
               │
               └── 触发 OrderPaid 事件（异步：发通知、更新统计等）
```

使用 `SELECT ... FOR UPDATE` 悲观锁而非乐观锁，因为支付回调可能被重复推送，悲观锁在高并发下更安全。

#### 3.4.9 配置管理

支付配置通过 `config/payment.php` 统一管理：

```php
return [
    'default' => env('PAYMENT_DEFAULT_GATEWAY', 'wechat'),

    'gateways' => [
        'wechat' => [
            'app_id'     => env('WECHAT_PAY_APP_ID'),
            'mch_id'     => env('WECHAT_PAY_MCH_ID'),
            'api_v3_key' => env('WECHAT_PAY_API_V3_KEY'),
            'private_key_path' => env('WECHAT_PAY_PRIVATE_KEY_PATH'),
            'cert_serial_no'   => env('WECHAT_PAY_CERT_SERIAL_NO'),
            'notify_url' => env('APP_URL') . '/api/v1/payments/notify/wechat',
            // 小程序 appid（与 APP appid 不同）
            'mini_app_id' => env('WECHAT_MINI_APP_ID'),
        ],
        'alipay' => [
            'app_id'          => env('ALIPAY_APP_ID'),
            'private_key'     => env('ALIPAY_PRIVATE_KEY'),
            'alipay_public_key' => env('ALIPAY_PUBLIC_KEY'),
            'notify_url'      => env('APP_URL') . '/api/v1/payments/notify/alipay',
            'return_url'      => env('ALIPAY_RETURN_URL'),  // PC/H5 同步跳转
        ],
    ],
];
```

### 3.5 订单支付与第三方对接（详细设计）

微信支付：v3 已经没有独立沙箱了，官方推荐用 1 分钱真实交易测试，测完手动退款
支付宝：有完整的沙箱环境（sandbox 账号 + 沙箱版 APP），可以模拟支付不花钱
对我们设计的影响：

3.4 定义了代码架构（模板方法+策略模式），本节描述系统与微信支付/支付宝的具体对接细节。

#### 3.5.1 整体对接架构

```
┌─────────────────────────────────────────────────────────────────────┐
│                           客户端（uniapp / flutter）                 │
│  APP    小程序    H5浏览器    PC浏览器                                │
│   │       │        │          │                                     │
│   │ SDK调起 │ wx.requestPayment  │ 页面跳转    │ 扫码/跳转            │
└───┼───────┼────────┼──────────┼─────────────────────────────────────┘
    │       │        │          │
    ▼       ▼        ▼          ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         Laravel API 服务端                           │
│                                                                     │
│  PaymentController ─→ OrderService ─→ PaymentManager ─→ Driver     │
│                                                                     │
│  PaymentCallbackController ─→ PaymentManager ─→ handleCallback()   │
│                                                                     │
│  ┌──────────────┐  ┌───────────────┐                               │
│  │ config/       │  │ storage/      │                               │
│  │ payment.php   │  │ certs/        │                               │
│  │ (环境变量)     │  │ wechat/       │  证书文件不入版本库             │
│  │               │  │ alipay/       │  .gitignore 排除               │
│  └──────────────┘  └───────────────┘                               │
└────────────┬──────────────────────────────┬─────────────────────────┘
             │ HTTPS                        │ HTTPS
             ▼                              ▼
┌────────────────────────┐    ┌────────────────────────────┐
│    微信支付 v3 API       │    │      支付宝开放平台 API      │
│  api.mch.weixin.qq.com │    │   openapi.alipay.com       │
│                        │    │                            │
│  ● 统一下单 (4种场景)   │    │  ● alipay.trade.app.pay   │
│  ● 查询订单             │    │  ● alipay.trade.create    │
│  ● 关闭订单             │    │  ● alipay.trade.wap.pay   │
│  ● 申请退款             │    │  ● alipay.trade.page.pay  │
│  ● 查询退款             │    │  ● alipay.trade.query     │
│  ● 回调通知 → 我方服务器 │    │  ● alipay.trade.close     │
└────────────────────────┘    │  ● alipay.trade.refund    │
                              │  ● 回调通知 → 我方服务器    │
                              └────────────────────────────┘
```

**PHP SDK 选择：**

| 渠道 | 推荐 SDK | 说明 |
|------|---------|------|
| 微信支付 | `wechatpay/wechatpay-php` | 微信官方 SDK，支持 v3 API，自动签名/验签/解密 |
| 支付宝 | `alipaysdk/openapi-sdk-php` | 支付宝官方 SDK，支持 RSA2 签名，通用接口调用 |

> SDK 封装了签名/验签/加密/HTTP 请求等底层细节，我们的 Gateway 类基于 SDK 构建业务逻辑，不直接处理密码学操作。

#### 3.5.2 微信支付 v3 对接

**证书与密钥：**

| 文件 | 用途 | 存储位置 |
|------|------|---------|
| 商户 API 私钥 `apiclient_key.pem` | 请求签名（SHA256-RSA2048） | `storage/certs/wechat/apiclient_key.pem` |
| 商户 API 证书 `apiclient_cert.pem` | 退款等需双向认证的接口 | `storage/certs/wechat/apiclient_cert.pem` |
| 微信支付平台证书 | 验证回调签名 | 通过 SDK 自动下载并缓存 |
| APIv3 密钥 | 解密回调报文（AES-256-GCM） | `.env` 环境变量 |

**请求签名流程（SDK 自动处理）：**

```
1. 构造签名串：HTTP方法\n + URL\n + 时间戳\n + 随机串\n + 请求体\n
2. 使用商户私钥 SHA256-RSA2048 签名
3. 在请求头中携带：
   Authorization: WECHATPAY2-SHA256-RSA2048 mchid="商户号",
     serial_no="证书序列号",nonce_str="随机串",
     timestamp="时间戳",signature="签名值"
```

**四种场景的具体 API 调用：**

**(a) APP 支付**

```
API: POST https://api.mch.weixin.qq.com/v3/pay/transactions/app

请求参数：
{
    "appid": "{APP的appid}",
    "mchid": "{商户号}",
    "description": "商城订单-{order_no}",
    "out_trade_no": "{payment_no}",
    "notify_url": "https://api.shop.com/api/v1/payments/notify/wechat",
    "amount": {
        "total": 9900,          // 单位：分
        "currency": "CNY"
    }
}

响应：{ "prepay_id": "wx261..." }

返回给客户端（APP SDK 调起参数）：
{
    "appid": "{appid}",
    "partnerid": "{商户号}",
    "prepayid": "wx261...",
    "package": "Sign=WXPay",
    "noncestr": "{随机串}",
    "timestamp": "{时间戳}",
    "sign": "{签名}"            // 对以上参数二次签名
}
```

客户端流程：uniapp `uni.requestPayment({provider:'wxpay', orderInfo})` 或 flutter `fluwx.payWithWeChat()`

**(b) 小程序 JSAPI 支付**

```
API: POST https://api.mch.weixin.qq.com/v3/pay/transactions/jsapi

请求参数（比 APP 多 payer）：
{
    "appid": "{小程序appid}",         // 注意：与APP的appid不同
    "mchid": "{商户号}",
    "description": "商城订单-{order_no}",
    "out_trade_no": "{payment_no}",
    "notify_url": "...",
    "amount": { "total": 9900 },
    "payer": {
        "openid": "{用户在小程序的openid}"   // 必须！从登录时获取
    }
}

响应：{ "prepay_id": "wx261..." }

返回给客户端（小程序 wx.requestPayment 参数）：
{
    "timeStamp": "{时间戳}",
    "nonceStr": "{随机串}",
    "package": "prepay_id=wx261...",
    "signType": "RSA",
    "paySign": "{签名}"
}
```

> 小程序支付需要用户 openid，在微信登录时已存入 `user_social_accounts.platform_id`，支付时通过 user_id 查询。

**(c) H5 支付**

```
API: POST https://api.mch.weixin.qq.com/v3/pay/transactions/h5

请求参数（多 scene_info）：
{
    "appid": "{公众号appid}",
    "mchid": "{商户号}",
    "description": "...",
    "out_trade_no": "{payment_no}",
    "notify_url": "...",
    "amount": { "total": 9900 },
    "scene_info": {
        "payer_client_ip": "{用户IP}",
        "h5_info": {
            "type": "Wap",
            "app_name": "商城",
            "app_url": "https://h5.shop.com"
        }
    }
}

响应：{ "h5_url": "https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?..." }

返回给客户端：
{
    "h5_url": "https://wx.tenpay.com/...&redirect_url=https://h5.shop.com/order/result"
}
```

客户端流程：`window.location.href = h5_url`，支付完成后微信跳回 redirect_url。

**(d) PC Native 扫码支付**

```
API: POST https://api.mch.weixin.qq.com/v3/pay/transactions/native

请求参数：与 APP 类似（不需要 openid/scene_info）

响应：{ "code_url": "weixin://wxpay/bizpayurl/up?pr=xxx" }

返回给客户端：
{
    "code_url": "weixin://wxpay/bizpayurl/up?pr=xxx"
}
```

客户端流程：前端使用 `qrcode.js` 等库将 code_url 生成二维码图片展示，用户手机扫码完成支付。

**微信回调处理：**

```
回调请求：
POST /api/v1/payments/notify/wechat
Headers:
  Wechatpay-Timestamp: {时间戳}
  Wechatpay-Nonce: {随机串}
  Wechatpay-Signature: {签名值}          // 使用微信平台证书公钥验签
  Wechatpay-Serial: {平台证书序列号}

Body（密文）：
{
    "resource": {
        "algorithm": "AEAD_AES_256_GCM",
        "ciphertext": "{密文}",
        "nonce": "{随机串}",
        "associated_data": "transaction"
    }
}

处理步骤（verifySignature + parseCallbackData）：
1. 用微信平台证书公钥验证 Headers 中的签名 → 确认是微信发的
2. 用 APIv3 密钥 AES-256-GCM 解密 ciphertext → 得到明文

解密后明文：
{
    "transaction_id": "42000...",          // 微信交易号
    "out_trade_no": "{我方payment_no}",
    "trade_state": "SUCCESS",
    "amount": { "total": 9900, "payer_total": 9900 }
}

成功响应：
HTTP 200
{"code": "SUCCESS", "message": "OK"}

失败响应：
HTTP 500
{"code": "FAIL", "message": "处理失败原因"}
```

> 微信会在支付成功后立即推送回调，若未收到成功响应，将按 15s/15s/30s/3m/10m/20m/30m/30m/... 间隔重试，持续 24 小时。

#### 3.5.3 支付宝对接

**密钥管理：**

| 项目 | 用途 | 存储位置 |
|------|------|---------|
| 应用私钥 | 请求签名（SHA256WithRSA） | `storage/certs/alipay/app_private_key.pem` |
| 支付宝公钥 | 验证回调签名 | `storage/certs/alipay/alipay_public_key.pem` |
| 应用公钥证书模式（可选） | 证书模式签名 | 根据支付宝后台配置选择 |

**请求签名流程（SDK 自动处理）：**

```
1. 将所有请求参数按 ASCII 排序，拼接为 key=value&key=value 格式
2. 使用应用私钥 SHA256WithRSA 签名
3. 将签名值放入请求参数 sign 字段
```

**四种场景的具体 API 调用：**

**(a) APP 支付**

```
接口：alipay.trade.app.pay

请求参数：
{
    "subject": "商城订单-{order_no}",
    "out_trade_no": "{payment_no}",
    "total_amount": "99.00",            // 单位：元，字符串
    "product_code": "QUICK_MSECURITY_PAY",
    "notify_url": "https://api.shop.com/api/v1/payments/notify/alipay"
}

SDK 输出：签名后的完整参数字符串（orderStr）

返回给客户端：
{
    "order_str": "app_id=2021...&biz_content=...&sign=..."
}
```

客户端流程：uniapp `uni.requestPayment({provider:'alipay', orderInfo: order_str})` 或 flutter `tobias.aliPay(order_str)`

**(b) 小程序支付**

```
接口：alipay.trade.create

请求参数：
{
    "subject": "商城订单-{order_no}",
    "out_trade_no": "{payment_no}",
    "total_amount": "99.00",
    "product_code": "JSAPI_PAY",
    "buyer_id": "{用户的支付宝user_id}",   // 从登录授权获取
    "notify_url": "..."
}

响应：{ "trade_no": "2024..." }

返回给客户端：
{
    "trade_no": "2024..."
}
```

客户端流程：小程序调用 `my.tradePay({ tradeNO: trade_no })`

**(c) H5 WAP 支付**

```
接口：alipay.trade.wap.pay

请求参数：
{
    "subject": "商城订单-{order_no}",
    "out_trade_no": "{payment_no}",
    "total_amount": "99.00",
    "product_code": "QUICK_WAP_WAY",
    "quit_url": "https://h5.shop.com/order/{order_no}",   // 用户中途退出跳转
    "return_url": "https://h5.shop.com/order/{order_no}/result",
    "notify_url": "..."
}

SDK 输出：完整的跳转 URL 或自提交表单 HTML

返回给客户端：
{
    "pay_url": "https://openapi.alipay.com/gateway.do?..."
}
```

客户端流程：`window.location.href = pay_url`，支付完成后支付宝跳回 return_url。

**(d) PC 网页支付**

```
接口：alipay.trade.page.pay

请求参数：
{
    "subject": "商城订单-{order_no}",
    "out_trade_no": "{payment_no}",
    "total_amount": "99.00",
    "product_code": "FAST_INSTANT_TRADE_PAY",
    "return_url": "https://www.shop.com/order/{order_no}/result",
    "notify_url": "..."
}

SDK 输出：自提交表单 HTML 或跳转 URL

返回给客户端：
{
    "pay_url": "https://openapi.alipay.com/gateway.do?..."
}
```

客户端流程：新窗口打开 `pay_url`，支付宝收银台页面完成支付后跳回 return_url。

**支付宝回调处理：**

```
回调请求：
POST /api/v1/payments/notify/alipay
Content-Type: application/x-www-form-urlencoded

Body（表单参数，明文）：
  trade_status=TRADE_SUCCESS
  &out_trade_no={我方payment_no}
  &trade_no=2024...                    // 支付宝交易号
  &total_amount=99.00
  &buyer_id=2088...
  &sign={签名}
  &sign_type=RSA2
  &...

处理步骤（verifySignature + parseCallbackData）：
1. 用支付宝公钥 RSA2 验签 → 确认是支付宝发的
2. 直接读取表单参数（明文，无需解密）
3. 校验 trade_status 为 TRADE_SUCCESS 或 TRADE_FINISHED
4. 校验 total_amount 与我方记录一致（防篡改）
5. 校验 app_id 与我方配置一致

成功响应：
HTTP 200
Body: success（纯文本，7个字符）

失败响应：
HTTP 200
Body: fail
```

> 支付宝回调重试策略：25小时内最多 8 次，间隔 4m/10m/10m/1h/2h/6h/15h。

#### 3.5.4 订单支付端到端流程（四种场景）

**APP 支付（微信/支付宝通用流程）：**

```
用户                   APP(uniapp/flutter)          服务端API              微信/支付宝
 │                          │                          │                      │
 │ 1. 点击"立即支付"         │                          │                      │
 │   选择微信/支付宝         │                          │                      │
 │─────────────────────────→│                          │                      │
 │                          │                          │                      │
 │                          │ 2. POST /orders/{no}/pay │                      │
 │                          │    {gateway: "wechat"}   │                      │
 │                          │    Header: X-Platform: app│                      │
 │                          │─────────────────────────→│                      │
 │                          │                          │                      │
 │                          │                          │ 3. PaymentManager     │
 │                          │                          │    resolve → WechatAppPayment
 │                          │                          │ 4. pay() 模板方法     │
 │                          │                          │    → 创建 payment 记录 │
 │                          │                          │    → 调用统一下单 ─────→│
 │                          │                          │    ← prepay_id ────────│
 │                          │                          │    → 组装SDK参数        │
 │                          │                          │                      │
 │                          │ 5. 200 {pay_params}      │                      │
 │                          │←─────────────────────────│                      │
 │                          │                          │                      │
 │                          │ 6. 调起原生支付SDK         │                      │
 │ 7. 支付密码/指纹/面容     │    uni.requestPayment()  │                      │
 │   ◄──────────────────────│    或 fluwx.payWithWeChat │                      │
 │──────────────────────────►                          │                      │
 │                          │                          │                      │
 │                          │ 8. SDK返回支付结果         │                      │
 │                          │   (仅做UI提示，不作为      │                      │
 │                          │    支付成功依据)           │                      │
 │                          │                          │                      │
 │                          │                          │ 9. 微信/支付宝服务器   │
 │                          │                          │    推送回调通知 ────────│
 │                          │                          │←──────────────────────│
 │                          │                          │ 10. 验签+解密+更新状态  │
 │                          │                          │    触发 OrderPaid 事件 │
 │                          │                          │    响应 SUCCESS ───────→│
 │                          │                          │                      │
 │                          │ 11. 轮询订单状态           │                      │
 │                          │  GET /orders/{no}         │                      │
 │                          │─────────────────────────→│                      │
 │                          │  {status: "paid"}        │                      │
 │                          │←─────────────────────────│                      │
 │  12. 展示支付成功页       │                          │                      │
 │←─────────────────────────│                          │                      │
```

> **重要：APP 端 SDK 返回的支付结果仅用于 UI 提示，不作为支付成功的依据。** 以服务端回调为准。APP 在 SDK 返回后应轮询订单状态接口确认最终结果。

**小程序支付（额外需要 openid）：**

与 APP 流程一致，区别：
- 需要 `openid`（微信）或 `buyer_id`（支付宝），从 `user_social_accounts` 表中查询
- 调起方式：微信 `wx.requestPayment()`，支付宝 `my.tradePay()`
- 若用户未绑定对应社交账号，返回 422 错误提示先绑定

**H5 支付（页面跳转模式）：**

```
用户                   H5浏览器                    服务端API             微信/支付宝
 │                       │                          │                      │
 │ 1. 点击"立即支付"      │                          │                      │
 │─────────────────────→│                          │                      │
 │                       │ 2. POST /orders/{no}/pay │                      │
 │                       │─────────────────────────→│                      │
 │                       │ 3. {h5_url / pay_url}    │                      │
 │                       │←─────────────────────────│                      │
 │                       │                          │                      │
 │                       │ 4. window.location.href   │                      │
 │                       │    = h5_url               │                      │
 │                       │─────────────────────────────────────────────────→│
 │ 5. 在微信/支付宝       │                          │                      │
 │    环境内完成支付       │                          │                      │
 │                       │ 6. 支付完成，跳回          │                      │
 │                       │    return_url/redirect_url│                      │
 │                       │←─────────────────────────────────────────────────│
 │                       │                          │                      │
 │                       │ 7. 到达结果页后轮询状态    │  8. 回调通知 ──────→  │
 │                       │  GET /orders/{no}         │←─────────────────────│
 │                       │─────────────────────────→│                      │
 │ 9. 展示支付结果        │  {status: "paid"}        │                      │
 │←─────────────────────│←─────────────────────────│                      │
```

> H5 支付流程中，用户离开我方页面进入微信/支付宝环境，支付完成后才跳回。因此结果页需要轮询订单状态。

**PC 扫码支付（微信 Native / 支付宝当 PC 网页支付时也可走扫码）：**

```
用户                   PC浏览器                    服务端API             微信/支付宝
 │                       │                          │                      │
 │ 1. 选择微信支付        │                          │                      │
 │─────────────────────→│                          │                      │
 │                       │ 2. POST /orders/{no}/pay │                      │
 │                       │─────────────────────────→│                      │
 │                       │ 3. {code_url}            │                      │
 │                       │←─────────────────────────│                      │
 │                       │                          │                      │
 │                       │ 4. qrcode.js 生成二维码    │                      │
 │ 5. 展示二维码          │    并展示在页面上          │                      │
 │←─────────────────────│                          │                      │
 │                       │                          │                      │
 │ 6. 用手机扫码支付      │                          │                      │
 │   (在手机微信完成)     │                          │                      │
 │                       │                          │                      │
 │                       │ 7. 前端定时轮询订单状态    │  8. 回调到达         │
 │                       │    setInterval 3s         │←─────────────────────│
 │                       │  GET /orders/{no}         │                      │
 │                       │─────────────────────────→│                      │
 │                       │  {status: "paid"}        │                      │
 │ 9. 跳转订单详情页      │←─────────────────────────│                      │
 │←─────────────────────│                          │                      │
```

> PC 扫码支付前端需要轮询（建议每 3 秒），检测到 `status=paid` 后停止轮询并跳转。同时设置轮询超时（5分钟），超时后提示用户刷新。

#### 3.5.5 支付状态主动查询（兜底机制）

回调通知可能因网络问题延迟或丢失，系统需要主动查询兜底：

**场景一：客户端轮询**

客户端在支付 SDK 返回后、H5 跳回后、PC 展示二维码后，均通过 `GET /orders/{no}` 轮询订单状态。此接口只查数据库，不调用第三方。

**场景二：定时任务主动查询**

```
┌─────────────────────────────────────────────────────────────────┐
│  定时任务：QueryPendingPayments（每分钟执行）                      │
│                                                                 │
│  1. 查询 payments 表：                                          │
│     status='pending' AND created_at < NOW() - INTERVAL 5 MINUTE │
│     AND created_at > NOW() - INTERVAL 30 MINUTE                 │
│     （创建超过5分钟但不超过30分钟的待支付记录）                      │
│                                                                 │
│  2. 对每条记录，调用第三方查询接口：                                │
│     微信：GET /v3/pay/transactions/out-trade-no/{payment_no}     │
│     支付宝：alipay.trade.query                                   │
│                                                                 │
│  3. 根据查询结果：                                               │
│     ├── 已支付 → 执行 processPaymentResult()（同回调处理逻辑）     │
│     ├── 未支付 → 不处理，等待下次查询                              │
│     └── 已关闭 → 更新 payment.status = 'failed'                  │
└─────────────────────────────────────────────────────────────────┘
```

**查询 API 详情：**

```
微信查询：
GET https://api.mch.weixin.qq.com/v3/pay/transactions/out-trade-no/{payment_no}?mchid={商户号}

响应中 trade_state：
  SUCCESS   → 已支付
  NOTPAY    → 未支付
  CLOSED    → 已关闭
  USERPAYING → 支付中（用户正在输入密码）

支付宝查询：
alipay.trade.query { out_trade_no: "{payment_no}" }

响应中 trade_status：
  TRADE_SUCCESS  → 已支付
  WAIT_BUYER_PAY → 等待付款
  TRADE_CLOSED   → 已关闭
  TRADE_FINISHED → 交易完成（不可退款）
```

#### 3.5.6 关闭未支付订单

订单超时 30 分钟未支付，定时任务 `CloseExpiredOrders` 处理：

```
1. 更新 orders.status = 'cancelled', cancel_reason = '超时未支付'
2. 更新 payments.status = 'failed'
3. 恢复库存（UPDATE product_skus SET stock = stock + qty）
4. 通知第三方关闭交易（防止用户超时后仍扫码支付）：
   微信：POST /v3/pay/transactions/out-trade-no/{payment_no}/close
   支付宝：alipay.trade.close { out_trade_no: "{payment_no}" }
5. 触发 OrderCancelled 事件
```

> 必须先通知第三方关闭交易，否则用户在超时后扫码仍能支付成功，导致回调到达时订单已取消的矛盾。

#### 3.5.7 退款对接

**微信退款：**

```
POST https://api.mch.weixin.qq.com/v3/refund/domestic/refunds

请求参数：
{
    "out_trade_no": "{原支付payment_no}",
    "out_refund_no": "{退款单号}",
    "reason": "用户申请退款",
    "notify_url": "https://api.shop.com/api/v1/payments/notify/wechat/refund",
    "amount": {
        "refund": 5000,           // 退款金额（分）
        "total": 9900,            // 原支付金额（分）
        "currency": "CNY"
    }
}

响应：
{
    "refund_id": "50000...",
    "status": "PROCESSING"        // 退款处理中
}
```

微信退款结果通过异步回调通知，回调格式与支付回调一致（加密），需要单独的回调处理端点。

**支付宝退款：**

```
接口：alipay.trade.refund

请求参数：
{
    "out_trade_no": "{原支付payment_no}",
    "out_request_no": "{退款单号}",
    "refund_amount": "50.00",
    "refund_reason": "用户申请退款"
}

响应：
{
    "fund_change": "Y",           // 资金变化
    "refund_fee": "50.00"
}
```

> 支付宝退款是同步接口，`fund_change=Y` 即表示退款成功，无需等待异步通知。若需确认，可调用 `alipay.trade.fastpay.refund.query` 查询。

**退款回调路由（微信独有）：**

```
POST   /api/v1/payments/notify/wechat/refund    微信退款结果回调
```

处理逻辑与支付回调类似：验签 → 解密 → 更新 payment.status → 更新 after_sale.status。

#### 3.5.8 金额处理

**微信支付：单位为分（整数），支付宝：单位为元（字符串，最多两位小数）。**

```php
// AbstractWechatPayment 中
protected function toWechatAmount(float $amount): int
{
    return (int) bcmul((string) $amount, '100');  // 元 → 分，使用 bcmath 避免浮点误差
}

// AbstractAlipayPayment 中
protected function toAlipayAmount(float $amount): string
{
    return bcadd((string) $amount, '0', 2);  // 保留两位小数字符串
}
```

**回调金额校验（在 processPaymentResult 中）：**

```php
// 校验回调金额与我方记录一致，防止金额篡改攻击
if (bccomp((string) $callbackAmount, (string) $payment->amount, 2) !== 0) {
    Log::critical('支付金额不一致', [
        'payment_no' => $payment->payment_no,
        'expected'   => $payment->amount,
        'received'   => $callbackAmount,
    ]);
    throw new PaymentAmountMismatchException();
}
```

#### 3.5.9 异常处理与容错

| 异常场景 | 处理策略 |
|---------|---------|
| 统一下单 API 调用超时 | 返回 500 给客户端，用户可重新发起支付 |
| 统一下单 API 返回业务错误 | 记录日志，返回具体错误给客户端 |
| 回调验签失败 | 返回失败响应，记录安全日志，**不更新**任何状态 |
| 回调金额不一致 | 返回失败响应，记录 CRITICAL 日志，人工介入 |
| 回调 payment_no 找不到 | 返回失败响应（可能是测试或伪造请求） |
| 退款 API 调用失败 | 不更新退款状态，售后停留在当前环节，支持重试 |
| 用户重复点击"支付" | 检查是否已有 pending 的 payment 记录，有则复用（不重复创建） |
| 订单已取消但回调到达 | 因已调用关闭交易 API，回调中 trade_state 应为 CLOSED，直接忽略 |

**重复支付防护（同一订单不重复创建 payment）：**

```php
// AbstractPaymentGateway::createPaymentRecord()
protected final function createPaymentRecord(Order $order, string $payScene): Payment
{
    // 检查是否已有同一订单的 pending payment
    $existing = Payment::where('order_id', $order->id)
        ->where('status', 'pending')
        ->first();

    if ($existing) {
        // 复用已有记录（可能是用户上次未完成支付）
        // 但若 pay_scene 不同（换了支付方式），则关闭旧的，创建新的
        if ($existing->pay_scene === $payScene) {
            return $existing;
        }
        $existing->update(['status' => 'failed']);
    }

    return Payment::create([
        'payment_no' => $this->generatePaymentNo(),
        'order_id'   => $order->id,
        'user_id'    => $order->user_id,
        'gateway'    => $this->gateway(),
        'pay_scene'  => $payScene,
        'amount'     => $order->pay_amount,
        'status'     => 'pending',
    ]);
}
```

#### 3.5.10 安全要求

| 要求 | 措施 |
|------|------|
| 传输安全 | 所有第三方 API 调用和回调接收均使用 HTTPS |
| 证书存储 | 私钥文件存放于 `storage/certs/`，权限 600，.gitignore 排除 |
| 回调来源 | GatewaySignatureVerify 中间件验签确认请求来源真实性 |
| 金额校验 | 回调处理时必须比对金额，不一致则拒绝并告警 |
| 日志脱敏 | 日志中不记录完整的私钥、签名串、回调原文，只记录关键业务字段 |
| 环境隔离 | 支付宝使用沙箱环境联调（sandbox 账号+沙箱 APP，不扣真钱）；微信支付 v3 无独立沙箱，使用 1 分钱真实交易联调，测完退款。通过 .env 切换 API 地址和密钥 |
| 密钥轮换 | 支持多证书共存（微信 serial_no 区分），轮换时新旧证书并行 |

### 3.6 优惠体系详细设计（责任链模式）

#### 3.6.1 优惠类型

| 类型 | 层级 | 触发方式 | 示例 |
|------|------|---------|------|
| 满减活动 | 商品/全场 | 自动生效（平台设置） | 满 200 减 30（可阶梯） |
| 折扣活动 | 商品/全场 | 自动生效 | 全场 8 折、指定分类 85 折 |
| 满减券 | 订单 | 用户主动选择 | 满 100 减 15 |
| 折扣券 | 订单 | 用户主动选择 | 9 折券，最多减 50 |
| 无门槛券 | 订单 | 用户主动选择 | 立减 5 元 |
| 免邮券 | 运费 | 自动匹配或用户选择 | 免运费 |

#### 3.6.2 互斥与叠加规则

| 规则 | 说明 |
|------|------|
| 同类活动不叠加 | 同一商品匹配多个满减活动时，取优先级最高的一个 |
| 优惠券只选一张 | 一笔订单只使用一张优惠券（不含免邮券） |
| 免邮券独立 | 免邮券与商品优惠券不互斥，可同时使用 |
| 活动 + 券可叠加 | 促销活动和优惠券可叠加（券基于活动后金额匹配门槛） |

#### 3.6.3 责任链架构

每种优惠是链上的一个 Handler，按固定顺序处理。每个 Handler 判断自身是否适用，计算优惠金额写入上下文，然后传给下一个：

```
OrderContext（携带商品列表、价格、已选优惠券）
    │
    ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ PromotionHandler│───→│  CouponHandler  │───→│ ShippingHandler │───→│  SummaryHandler │
│ (满减/折扣活动)  │    │ (用户优惠券)     │    │ (运费 + 免邮券) │    │ (汇总计算实付)   │
└─────────────────┘    └─────────────────┘    └─────────────────┘    └─────────────────┘
   逐商品匹配活动          基于活动后金额         计算运费             total - discount
   计算优惠写入明细         匹配券+计算抵扣       抵扣免邮券           + shipping = pay
```

**链的顺序固定，体现业务约束：**
1. **活动优惠先算** — 商品自身的促销，改变商品实际售价
2. **优惠券后算** — 基于活动后的金额匹配门槛（满 100 减 15 的"100"是活动后金额）
3. **运费最后算** — 基于实际支付金额和地址计算运费，免邮券抵扣运费
4. **汇总** — 计算最终实付金额

#### 3.6.4 接口与基类

```php
interface DiscountHandlerInterface
{
    public function setNext(DiscountHandlerInterface $handler): DiscountHandlerInterface;
    public function handle(OrderContext $context): OrderContext;
}

abstract class AbstractDiscountHandler implements DiscountHandlerInterface
{
    private ?DiscountHandlerInterface $next = null;

    public function setNext(DiscountHandlerInterface $handler): DiscountHandlerInterface
    {
        $this->next = $handler;
        return $handler; // 支持链式调用
    }

    public function handle(OrderContext $context): OrderContext
    {
        $context = $this->process($context);

        return $this->next ? $this->next->handle($context) : $context;
    }

    abstract protected function process(OrderContext $context): OrderContext;
}
```

#### 3.6.5 OrderContext 上下文对象

```php
class OrderContext
{
    // ── 输入（下单时传入） ──
    public array $items;           // [{sku_id, product_id, qty, unit_price}, ...]
    public int $userId;
    public ?int $couponId;         // 用户选择的优惠券 ID（null=不使用）
    public ?int $addressId;        // 收货地址 ID（用于运费计算）

    // ── 各 Handler 写入 ──
    public array $promotionDetails = [];   // 活动优惠明细
    // [{promotion_id, name, type, discount_amount, affected_sku_ids}, ...]

    public ?array $couponDetail = null;    // 优惠券明细
    // {coupon_id, user_coupon_id, name, type, discount_amount}

    public float $shippingFee = 0;         // 运费
    public float $shippingDiscount = 0;    // 免邮券减免金额

    // ── SummaryHandler 汇总 ──
    public float $totalAmount = 0;         // 商品原价总额
    public float $promotionAmount = 0;     // 活动优惠合计
    public float $couponAmount = 0;        // 优惠券优惠
    public float $discountAmount = 0;      // 总优惠 = promotionAmount + couponAmount
    public float $payAmount = 0;           // 实付 = totalAmount - discountAmount + shippingFee - shippingDiscount
}
```

#### 3.6.6 各 Handler 详细逻辑

**（1）PromotionHandler — 促销活动**

```php
class PromotionHandler extends AbstractDiscountHandler
{
    protected function process(OrderContext $context): OrderContext
    {
        // 1. 查询当前时间有效的促销活动（status=1, start_at <= now <= end_at）
        $activePromotions = $this->promotionRepo->getActive();

        // 2. 对每个商品，找出适用的活动（按 scope 匹配）
        foreach ($context->items as &$item) {
            $matched = $this->matchPromotion($item, $activePromotions);
            if (!$matched) continue;

            // 3. 按活动类型计算优惠
            $discount = match ($matched->type) {
                'full_reduction' => $this->calcFullReduction($item, $matched->rules),
                'percentage'     => $this->calcPercentage($item, $matched->rules),
            };

            $item['promotion_discount'] = $discount;
            $item['pay_price'] = bcsub((string)($item['unit_price'] * $item['qty']), (string)$discount, 2);

            $context->promotionDetails[] = [
                'promotion_id'    => $matched->id,
                'name'            => $matched->name,
                'type'            => $matched->type,
                'discount_amount' => $discount,
                'affected_sku_ids' => [$item['sku_id']],
            ];
        }
        return $context;
    }

    // 阶梯满减计算
    private function calcFullReduction(array $item, array $rules): float
    {
        $amount = bcmul((string)$item['unit_price'], (string)$item['qty'], 2);
        $tiers = collect($rules['tiers'])->sortByDesc('min');

        foreach ($tiers as $tier) {
            if (bccomp($amount, (string)$tier['min'], 2) >= 0) {
                return (float)$tier['discount'];
            }
        }
        return 0;
    }
}
```

**（2）CouponHandler — 优惠券**

```php
class CouponHandler extends AbstractDiscountHandler
{
    protected function process(OrderContext $context): OrderContext
    {
        if (!$context->couponId) {
            return $context; // 未选优惠券，跳过
        }

        // 1. 查询用户优惠券，校验归属/状态/有效期
        $userCoupon = $this->userCouponRepo->findUnusedByUser(
            $context->userId, $context->couponId
        );
        if (!$userCoupon) {
            throw new CouponNotAvailableException();
        }

        $coupon = $userCoupon->coupon;

        // 2. 计算活动后的订单金额（优惠券基于此金额匹配门槛）
        $afterPromotionTotal = $this->calcAfterPromotionTotal($context);

        // 3. 校验使用门槛
        if (bccomp((string)$afterPromotionTotal, (string)$coupon->min_amount, 2) < 0) {
            throw new CouponMinAmountNotMetException($coupon->min_amount);
        }

        // 4. 校验适用范围（scope=all 全部适用，scope=product/category 检查商品）
        if (!$this->checkScope($coupon, $context->items)) {
            throw new CouponScopeNotMatchException();
        }

        // 5. 计算优惠金额
        $discount = match ($coupon->type) {
            'fixed_amount'  => (float) $coupon->value,
            'no_threshold'  => (float) $coupon->value,
            'percentage'    => $this->calcPercentageDiscount($afterPromotionTotal, $coupon),
            'free_shipping' => 0, // 免邮券在 ShippingHandler 处理
        };

        $context->couponDetail = [
            'coupon_id'       => $coupon->id,
            'user_coupon_id'  => $userCoupon->id,
            'name'            => $coupon->name,
            'type'            => $coupon->type,
            'discount_amount' => $discount,
        ];

        return $context;
    }

    private function calcPercentageDiscount(float $amount, Coupon $coupon): float
    {
        // 折扣金额 = 原金额 × (1 - 折扣率)
        $discount = bcmul((string)$amount, bcsub('1', (string)$coupon->value, 2), 2);
        // 不超过最高抵扣上限
        if ($coupon->max_discount !== null) {
            $discount = min($discount, $coupon->max_discount);
        }
        return (float)$discount;
    }
}
```

**（3）ShippingDiscountHandler — 运费与免邮**

```php
class ShippingDiscountHandler extends AbstractDiscountHandler
{
    protected function process(OrderContext $context): OrderContext
    {
        // 1. 根据收货地址 + 商品重量 + 运费模板计算运费
        $context->shippingFee = $this->shippingService->calculate(
            $context->addressId, $context->items
        );

        // 2. 检查是否有免邮券（用户选择的优惠券是免邮券，或自动匹配最优免邮券）
        $freeShippingCoupon = $this->findFreeShippingCoupon($context);
        if ($freeShippingCoupon) {
            $context->shippingDiscount = $context->shippingFee; // 全额免邮
            // 记录免邮券使用
            if (!$context->couponDetail || $context->couponDetail['type'] !== 'free_shipping') {
                $context->freeShippingCouponId = $freeShippingCoupon->id;
            }
        }

        return $context;
    }
}
```

**（4）SummaryHandler — 汇总**

```php
class SummaryHandler extends AbstractDiscountHandler
{
    protected function process(OrderContext $context): OrderContext
    {
        // 商品原价总额
        $context->totalAmount = (float) collect($context->items)
            ->sum(fn($item) => bcmul((string)$item['unit_price'], (string)$item['qty'], 2));

        // 活动优惠合计
        $context->promotionAmount = (float) collect($context->promotionDetails)
            ->sum('discount_amount');

        // 优惠券优惠
        $context->couponAmount = $context->couponDetail['discount_amount'] ?? 0;

        // 总优惠
        $context->discountAmount = bcadd(
            (string)$context->promotionAmount,
            (string)$context->couponAmount,
            2
        );

        // 实付 = 原价 - 优惠 + 运费 - 运费减免
        $afterDiscount = bcsub((string)$context->totalAmount, (string)$context->discountAmount, 2);
        $afterShipping = bcadd($afterDiscount, (string)$context->shippingFee, 2);
        $payAmount = bcsub($afterShipping, (string)$context->shippingDiscount, 2);

        // 实付不低于 0.01
        $context->payAmount = max(0.01, (float) $payAmount);

        return $context;
    }
}
```

#### 3.6.7 链的组装与调用

```php
// DiscountPipeline — 在 OrderService 中组装并执行
class DiscountPipeline
{
    public function __construct(
        private PromotionHandler $promotionHandler,
        private CouponHandler $couponHandler,
        private ShippingDiscountHandler $shippingHandler,
        private SummaryHandler $summaryHandler,
    ) {}

    public function calculate(OrderContext $context): OrderContext
    {
        // 组装责任链（顺序固定）
        $this->promotionHandler
            ->setNext($this->couponHandler)
            ->setNext($this->shippingHandler)
            ->setNext($this->summaryHandler);

        return $this->promotionHandler->handle($context);
    }
}

// OrderService 下单时调用
class OrderService
{
    public function createOrder(int $userId, array $cartItemIds, ?int $couponId, int $addressId): Order
    {
        $items = $this->buildItemsFromCart($cartItemIds);

        // 通过责任链计算所有优惠
        $context = new OrderContext(
            items: $items,
            userId: $userId,
            couponId: $couponId,
            addressId: $addressId,
        );
        $context = $this->discountPipeline->calculate($context);

        // 使用计算结果创建订单
        return DB::transaction(function () use ($context, $userId, $couponId, $addressId) {
            // ... 创建订单、扣库存、核销优惠券、清购物车
        });
    }
}
```

> 同样的 `DiscountPipeline` 在**下单预览**（`POST /orders/preview`）和**实际下单**（`POST /orders`）时都调用，保证预览与实际一致。

#### 3.6.8 下单预览接口

用户在确认订单页面看到优惠明细，需要先预览计算结果：

```
POST /api/v1/orders/preview
Body: {cart_item_ids: [1,2,3], coupon_id: 5, address_id: 10}

响应：
{
    "items": [
        {"sku_id": 101, "name": "...", "unit_price": 99.00, "qty": 2, "promotion_discount": 10},
        ...
    ],
    "promotion_details": [
        {"name": "新春满200减30", "discount_amount": 30.00}
    ],
    "coupon_detail": {
        "name": "满100减15券", "discount_amount": 15.00
    },
    "total_amount": 298.00,
    "promotion_amount": 30.00,
    "coupon_amount": 15.00,
    "discount_amount": 45.00,
    "shipping_fee": 10.00,
    "shipping_discount": 0,
    "pay_amount": 263.00,
    "available_coupons": [
        {"id": 5, "name": "满100减15", "usable": true},
        {"id": 8, "name": "满500减80", "usable": false, "reason": "未满500元"}
    ]
}
```

#### 3.6.9 优惠券生命周期

```
创建（管理后台）→ 发放（用户领取）→ 使用（下单核销）→ 归还（取消订单退回）
                        │                                     ↑
                        └──── 过期（定时任务标记）               │
                                                    订单取消时退回
```

**领取优惠券 `POST /coupons/{id}/claim`：**

```php
// CouponService::claim()
public function claim(int $userId, int $couponId): UserCoupon
{
    $coupon = $this->couponRepo->findOrFail($couponId);

    // 1. 校验：启用、在领取时间内、未领完
    // 2. 原子操作：UPDATE coupons SET claimed_count = claimed_count + 1
    //    WHERE id=? AND claimed_count < total_count（防超领）
    // 3. 创建 user_coupons 记录（status=unused, expires_at=计算过期时间）

    return $userCoupon;
}
```

**下单核销（在 OrderService 事务中）：**

```php
// 更新 user_coupons：status=used, used_at=now, order_id=订单ID
```

**取消订单退回：**

```php
// OrderCancelledListener 中：
// 若订单使用了优惠券，UPDATE user_coupons SET status=unused, used_at=NULL, order_id=NULL
// 仅在优惠券未过期时退回
```

#### 3.6.10 促销活动规则（rules JSON 格式）

**阶梯满减：**
```json
{
    "tiers": [
        {"min": 100, "discount": 10},
        {"min": 200, "discount": 30},
        {"min": 300, "discount": 60}
    ]
}
```
匹配规则：从高到低匹配第一个满足的阶梯。满 250 → 命中"满200减30"。

**折扣：**
```json
{
    "discount_rate": 0.8,
    "max_discount": 100
}
```
8 折优惠，最多优惠 100 元。

### 3.7 购物车详细设计

#### 3.7.1 业务规则

| 规则 | 说明 |
|------|------|
| 容量上限 | 每个用户最多 **50** 条购物车记录 |
| SKU 维度 | 以 SKU 为最小单位，同一 SKU 加购多次自动合并数量 |
| 数量限制 | 单条最少 1，最多 99（不超过 SKU 实际库存） |
| 选中状态 | 每条记录独立选中/取消，支持全选/全不选 |
| 失效标记 | SKU 下架、库存为 0、商品删除时，购物车项标记为失效（前端灰显） |
| 跨平台同步 | 购物车存储在数据库，多平台共享同一份数据 |
| 价格实时性 | 购物车列表返回**当前**SKU价格，非加购时的价格 |

#### 3.7.2 购物车列表数据结构

购物车列表按商品维度聚合展示，每个 SKU 实时关联当前商品信息：

```
GET /api/v1/cart

响应：
{
    "items": [
        {
            "id": 1,                           // cart.id
            "quantity": 2,
            "is_checked": true,
            "product": {                        // 实时商品信息
                "id": 100,
                "title": "iPhone 15 Pro",
                "image": "https://...",
                "is_on_sale": true              // 是否在售
            },
            "sku": {                            // 实时 SKU 信息
                "id": 201,
                "title": "256GB 黑色钛金属",
                "price": 8999.00,               // 当前价格（非加购时价格）
                "stock": 50,                     // 当前库存
                "image": "https://..."
            },
            "is_valid": true                     // 是否有效（综合判断）
        },
        {
            "id": 2,
            "quantity": 1,
            "is_checked": true,
            "product": { ... , "is_on_sale": false },
            "sku": { ... , "stock": 0 },
            "is_valid": false,                   // 已下架，标记失效
            "invalid_reason": "商品已下架"
        }
    ],
    "summary": {
        "total_count": 3,                        // 总件数（仅选中+有效）
        "total_amount": "17998.00",              // 总金额（仅选中+有效）
        "checked_count": 2,                      // 选中数
        "all_checked": false                     // 是否全选
    }
}
```

**失效判断规则（CartService 中实时计算）：**

| 条件 | is_valid | invalid_reason |
|------|---------|---------------|
| 商品已删除（软删除） | false | 商品已失效 |
| 商品已下架 `is_on_sale=false` | false | 商品已下架 |
| SKU 库存为 0 | false | 暂时缺货 |
| 购物车数量 > 当前库存 | true | 但 quantity 自动调整为库存值 |

#### 3.7.3 接口详细设计

**（1）加入购物车 `POST /cart`**

```
客户端                               服务端
  │                                   │
  │  POST /cart                       │
  │  {sku_id: 201, quantity: 1}       │
  │──────────────────────────────────→│
  │                                   │ 1. 校验 SKU 存在且商品在售
  │                                   │ 2. 校验库存 ≥ quantity
  │                                   │ 3. 检查购物车总条数 ≤ 50
  │                                   │ 4. UPSERT：
  │                                   │    存在同 SKU → quantity += 新增数量
  │                                   │    不存在 → INSERT 新记录
  │                                   │ 5. 合并后数量不超过 99 且不超过库存
  │     201 / 200                     │
  │←──────────────────────────────────│
```

核心伪代码：

```php
// CartService::add()
public function add(int $userId, int $skuId, int $quantity): CartItem
{
    $sku = $this->skuRepo->findOrFail($skuId);

    // 校验商品在售
    if (!$sku->product->is_on_sale) {
        throw new ProductOffSaleException();
    }

    // 校验库存
    if ($sku->stock < $quantity) {
        throw new InsufficientStockException();
    }

    // UPSERT：同一 SKU 合并数量
    $cartItem = $this->cartRepo->findByUserAndSku($userId, $skuId);

    if ($cartItem) {
        $newQty = min($cartItem->quantity + $quantity, 99, $sku->stock);
        $cartItem->update(['quantity' => $newQty]);
        return $cartItem;
    }

    // 新增：检查购物车总条数
    if ($this->cartRepo->countByUser($userId) >= 50) {
        throw new CartLimitExceededException();
    }

    return $this->cartRepo->create([
        'user_id'        => $userId,
        'product_sku_id' => $skuId,
        'quantity'        => $quantity,
        'is_checked'      => true,
    ]);
}
```

> 利用 `UNIQUE(user_id, product_sku_id)` 约束保证不会出现同一用户同一 SKU 的重复记录。

**（2）修改数量/选中状态 `PUT /cart/{id}`**

```
客户端                               服务端
  │                                   │
  │  PUT /cart/1                      │
  │  {quantity: 3}                    │ 修改数量
  │  或 {is_checked: false}           │ 取消选中
  │  或 {quantity: 3, is_checked: true}│ 同时修改
  │──────────────────────────────────→│
  │                                   │ 1. 归属校验（cart.user_id = 当前用户）
  │                                   │ 2. quantity：校验 1-99 且 ≤ 库存
  │                                   │ 3. UPDATE
  │     200                           │
  │←──────────────────────────────────│
```

**（3）删除购物车项 `DELETE /cart/{id}`**

```
客户端                               服务端
  │                                   │
  │  DELETE /cart/1                    │
  │──────────────────────────────────→│
  │                                   │ 归属校验 + 物理删除
  │     204                           │
  │←──────────────────────────────────│
```

**（4）批量删除 `DELETE /cart/batch`**

```
客户端                               服务端
  │                                   │
  │  DELETE /cart/batch                │
  │  {ids: [1, 2, 3]}                │
  │──────────────────────────────────→│
  │                                   │ 归属校验 + 批量物理删除
  │     204                           │
  │←──────────────────────────────────│
```

**（5）全选/全不选 `PUT /cart/check-all`**

```
客户端                               服务端
  │                                   │
  │  PUT /cart/check-all              │
  │  {is_checked: true}               │
  │──────────────────────────────────→│
  │                                   │ UPDATE carts SET is_checked=?
  │                                   │ WHERE user_id=当前用户
  │                                   │ (仅更新有效商品的选中状态)
  │     200                           │
  │←──────────────────────────────────│
```

**（6）清空失效商品 `DELETE /cart/invalid`**

一键清除所有已下架/无库存的购物车项：

```
客户端                               服务端
  │                                   │
  │  DELETE /cart/invalid              │
  │──────────────────────────────────→│
  │                                   │ 查询该用户购物车中关联的
  │                                   │ 已下架/删除/零库存的 SKU
  │                                   │ 批量删除这些购物车记录
  │     204                           │
  │←──────────────────────────────────│
```

#### 3.7.4 购物车与下单的衔接

```
购物车页                确认订单页                         服务端
  │                       │                                │
  │ 1. 勾选商品，点击       │                                │
  │    "去结算"             │                                │
  │──────────────────────→│                                │
  │                       │ 2. POST /orders/preview         │
  │                       │    {cart_item_ids: [1,2,3],     │
  │                       │     address_id: 10}             │
  │                       │───────────────────────────────→│
  │                       │                                │ 3. 校验购物车项有效性
  │                       │                                │ 4. 查询可用优惠券列表
  │                       │                                │ 5. 执行 DiscountPipeline
  │                       │                                │    （不选券的默认计算）
  │                       │ 6. 返回预览结果 + 可用券列表     │
  │                       │←───────────────────────────────│
  │                       │                                │
  │                       │ 7. 用户选择优惠券/修改地址       │
  │                       │    重新 POST /orders/preview    │
  │                       │    {cart_item_ids, coupon_id,   │
  │                       │     address_id}                 │
  │                       │───────────────────────────────→│
  │                       │ 8. 返回更新后的预览结果          │
  │                       │←───────────────────────────────│
  │                       │                                │
  │                       │ 9. 确认下单                     │
  │                       │    POST /orders                 │
  │                       │    {cart_item_ids, coupon_id,   │
  │                       │     address_id, remark}         │
  │                       │───────────────────────────────→│
  │                       │                                │ 10. 再次执行 DiscountPipeline
  │                       │                                │ 11. 事务：扣库存+创建订单
  │                       │                                │     +核销优惠券+清购物车
  │                       │ 12. {order_no}                  │
  │                       │←───────────────────────────────│
```

> **下单时再次计算优惠**：不信任客户端传来的金额，服务端重新执行 DiscountPipeline 确保金额正确。预览和下单使用同一套计算逻辑。

### 4.1 用户认证相关（5 张表）

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
| event | VARCHAR(50) | login/logout/token_leak/device_kicked/password_changed/sensitive_verify |
| platform | VARCHAR(20) NULL | 平台 |
| client_ip | VARCHAR(45) | IP |
| user_agent | VARCHAR(500) NULL | UA |
| detail | JSON NULL | 额外信息 |
| created_at | TIMESTAMP | |

索引：INDEX(user_id, event)

#### password_histories 密码历史表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| password | VARCHAR(255) | 历史密码（bcrypt 哈希） |
| created_at | TIMESTAMP | |

索引：INDEX(user_id)

> 每个用户最多保留 3 条记录，新密码设置时自动清理最旧的。此表不使用 `updated_at`（`const UPDATED_AT = null`）。

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

### 4.3 交易相关（12 张表）

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

#### promotions 促销活动表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| name | VARCHAR(100) | 活动名称 |
| type | VARCHAR(20) | full_reduction / percentage |
| rules | JSON | 活动规则（阶梯满减/折扣率+上限） |
| scope | VARCHAR(20) | all / product / category |
| scope_ids | JSON NULL | 适用商品/分类 ID 列表（scope=all 时为空） |
| priority | INT DEFAULT 0 | 优先级（同类活动取最高） |
| start_at | TIMESTAMP | 生效时间 |
| end_at | TIMESTAMP | 结束时间 |
| status | TINYINT DEFAULT 1 | 1启用 0停用 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

索引：INDEX(status, start_at, end_at)

#### coupons 优惠券模板表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| name | VARCHAR(100) | 券名称 |
| type | VARCHAR(20) | fixed_amount / percentage / no_threshold / free_shipping |
| value | DECIMAL(10,2) | 面值：固定金额(元) / 折扣率(如 0.85=85折) |
| min_amount | DECIMAL(10,2) DEFAULT 0 | 使用门槛（满X可用，0=无门槛） |
| max_discount | DECIMAL(10,2) NULL | 最高抵扣（折扣券用，NULL=不限） |
| scope | VARCHAR(20) | all / product / category |
| scope_ids | JSON NULL | 适用范围 ID 列表 |
| total_count | INT | 发行总量 |
| claimed_count | INT DEFAULT 0 | 已领取数量 |
| start_at | TIMESTAMP | 可用开始时间 |
| end_at | TIMESTAMP | 可用结束时间 |
| status | TINYINT DEFAULT 1 | 1启用 0停用 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

#### user_coupons 用户已领优惠券表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| coupon_id | BIGINT UNSIGNED FK | 优惠券模板ID |
| status | VARCHAR(20) DEFAULT 'unused' | unused / used / expired |
| used_at | TIMESTAMP NULL | 使用时间 |
| order_id | BIGINT UNSIGNED NULL FK | 使用的订单ID |
| expires_at | TIMESTAMP | 过期时间 |
| created_at | TIMESTAMP | 领取时间 |

索引：INDEX(user_id, status)

#### orders 订单表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| order_no | VARCHAR(32) UNIQUE | 订单号 |
| user_id | BIGINT UNSIGNED FK | 用户ID |
| address_snapshot | JSON | 收货地址快照 |
| total_amount | DECIMAL(10,2) | 商品总额 |
| discount_amount | DECIMAL(10,2) DEFAULT 0 | 总优惠金额（活动+优惠券） |
| shipping_fee | DECIMAL(10,2) DEFAULT 0 | 运费 |
| pay_amount | DECIMAL(10,2) | 实付 = total - discount + shipping - shipping_discount |
| coupon_id | BIGINT UNSIGNED NULL FK | 使用的优惠券模板ID |
| discount_detail | JSON NULL | 优惠明细快照（活动+券明细，审计/展示用） |
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
| gateway | VARCHAR(20) | 支付渠道：wechat/alipay |
| pay_scene | VARCHAR(20) | 支付场景：wechat_app/wechat_mini/alipay_h5 等 |
| amount | DECIMAL(10,2) | 支付金额 |
| status | VARCHAR(20) DEFAULT 'pending' | pending/paid/refunding/refunded/failed |
| gateway_trade_no | VARCHAR(64) NULL | 第三方交易号 |
| gateway_response | JSON NULL | 第三方原始回调数据 |
| paid_at | TIMESTAMP NULL | 支付时间 |
| refund_no | VARCHAR(32) NULL | 退款单号 |
| refund_amount | DECIMAL(10,2) NULL | 退款金额 |
| refunded_at | TIMESTAMP NULL | 退款完成时间 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

索引：INDEX(order_id)

> `pay_scene` 记录具体支付场景（如 `wechat_app`），退款时用于自动解析对应的渠道实现。`status` 支持五种状态：pending（待支付）→ paid（已支付）→ refunding（退款中）→ refunded（已退款），任一阶段可进入 failed（失败）。

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
       ├─ password_histories (1:N)
       ├─ user_addresses (1:N)
       ├─ carts (1:N)
       ├─ user_coupons ─── coupons (N:1)
       ├─ orders ──┬─ order_items (1:N)
       │           ├─ payments (1:N)
       │           ├─ shipments (1:N)
       │           └─ after_sales (1:N)
       └─ after_sales (1:N)

categories ─── products ──┬─ product_skus (1:N)
                          └─ product_attributes (1:N)

promotions（独立，通过 scope+scope_ids 关联商品/分类）
coupons ─── user_coupons (1:N)
```

共 **21 张表**。

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
| 42206 | 收货地址数量超限（最多 20 条） |
| 42207 | 不允许取消唯一默认地址 |
| 50001 | 支付网关调用失败 |
| 50002 | 支付回调验签失败 |
| 50003 | 退款失败 |
| 42208 | 不支持的支付方式（渠道+平台组合无效） |
| 42209 | 优惠券不可用（不存在/已使用/已过期） |
| 42210 | 未满足优惠券使用门槛 |
| 42211 | 优惠券不适用于当前商品 |
| 42212 | 优惠券已领完 |
| 42213 | 购物车数量超限（最多 50 条） |
| 42214 | 商品已下架 |

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
POST   /api/v1/payments/notify/wechat/refund  微信退款结果回调
```

#### 认证接口（需 JWT Token）

```
POST   /api/v1/auth/logout                 登出
GET    /api/v1/auth/devices                在线设备列表
DELETE /api/v1/auth/devices/{id}           踢掉指定设备
POST   /api/v1/auth/password/change        修改密码（需旧密码，需二次验证）
POST   /api/v1/auth/password/set           首次设置密码（无密码用户，需二次验证）

POST   /api/v1/auth/verify-identity         二次身份验证（签发 verify_token）

GET    /api/v1/user/profile                获取个人信息（脱敏输出）
PUT    /api/v1/user/profile                修改个人信息（仅 nickname, avatar）
POST   /api/v1/user/phone/change           更换手机号（需二次验证 + 新手机号验证码）
POST   /api/v1/user/phone/bind             绑定手机号（第三方登录用户，需二次验证）
GET    /api/v1/user/addresses              收货地址列表（脱敏输出）
GET    /api/v1/user/addresses/{id}         单条收货地址（编辑用，不脱敏）
POST   /api/v1/user/addresses              创建收货地址
PUT    /api/v1/user/addresses/{id}         修改收货地址
DELETE /api/v1/user/addresses/{id}         删除收货地址
PATCH  /api/v1/user/addresses/{id}/default 设为默认地址

GET    /api/v1/cart                         购物车列表（含实时价格+失效标记）
POST   /api/v1/cart                         加入购物车（同SKU自动合并）
PUT    /api/v1/cart/{id}                    修改数量/选中状态
DELETE /api/v1/cart/{id}                    删除购物车项
DELETE /api/v1/cart/batch                   批量删除 {ids: [...]}
PUT    /api/v1/cart/check-all              全选/全不选 {is_checked: bool}
DELETE /api/v1/cart/invalid                 清空失效商品

GET    /api/v1/coupons                      可领取优惠券列表
POST   /api/v1/coupons/{id}/claim           领取优惠券
GET    /api/v1/user/coupons                 我的优惠券列表（支持 status 筛选）

POST   /api/v1/orders/preview               下单预览（计算优惠+可用券列表）
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
敏感操作:     认证接口栈 + SensitiveOperation（校验 X-Verify-Token）→ Controller
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
│   │   ├── CouponController            优惠券领取/列表
│   │   ├── Order/                   订单控制器（含支付）
│   │   │   ├── OrderController
│   │   │   ├── PaymentController    发起支付
│   │   │   └── PaymentCallbackController  支付回调（独立控制器）
│   │   ├── ShipmentController
│   │   └── AfterSaleController
│   ├── Middleware/                   中间件
│   │   ├── ForceJsonResponse
│   │   ├── JwtAuthenticate
│   │   ├── JwtBlacklist
│   │   ├── TokenLeakDetection
│   │   ├── PlatformIdentify
│   │   ├── SensitiveOperation       敏感操作二次验证
│   │   └── GatewaySignatureVerify
│   ├── Requests/Api/V1/             表单验证
│   └── Resources/Api/V1/            API 资源转换（含脱敏输出）
│       ├── UserResource              用户信息（手机号/邮箱脱敏）
│       ├── UserAddressResource       收货地址（支持脱敏/完整两种模式）
│       └── ...
│
├── Support/                         辅助工具
│   └── DataMasker                   数据脱敏工具类（phone/email/name/address）
│
├── Models/                          18 个 Eloquent 模型
├── Repositories/                    数据访问层（Repository Pattern）
│   ├── Contracts/                   Repository 接口定义
│   │   ├── UserRepositoryInterface
│   │   ├── AddressRepositoryInterface
│   │   ├── ProductRepositoryInterface
│   │   ├── OrderRepositoryInterface
│   │   ├── CartRepositoryInterface
│   │   ├── CouponRepositoryInterface
│   │   ├── PromotionRepositoryInterface
│   │   ├── PaymentRepositoryInterface
│   │   └── AfterSaleRepositoryInterface
│   ├── Eloquent/                    MySQL 实现（Eloquent ORM）
│   │   ├── BaseRepository           基础 Repository（通用 CRUD）
│   │   ├── UserRepository
│   │   ├── UserTokenRepository
│   │   ├── UserSocialAccountRepository
│   │   ├── AddressRepository          收货地址（含默认地址管理）
│   │   ├── ProductRepository
│   │   ├── ProductSkuRepository
│   │   ├── CategoryRepository
│   │   ├── CartRepository
│   │   ├── CouponRepository
│   │   ├── UserCouponRepository
│   │   ├── PromotionRepository
│   │   ├── OrderRepository
│   │   ├── PaymentRepository
│   │   ├── ShipmentRepository
│   │   └── AfterSaleRepository
│   ├── Cache/                       Redis 缓存装饰器
│   │   ├── CachingProductRepository     商品缓存（高频读取）
│   │   ├── CachingCategoryRepository    分类缓存（几乎不变）
│   │   ├── CachingProductSkuRepository  SKU 缓存（含库存）
│   │   └── CachingUserRepository        用户基础信息缓存
│   └── RepositoryServiceProvider    接口→实现绑定（装饰器组装）
│
├── Services/                        业务逻辑层（不直接操作 Model）
│   ├── Auth/
│   │   ├── AuthService              认证核心
│   │   ├── JwtService               JWT 管理
│   │   ├── DeviceService            设备/互踢
│   │   ├── SmsService               短信验证码
│   │   ├── PasswordPolicyValidator  密码策略校验
│   │   ├── VerifyIdentityService    二次身份验证
│   │   └── SocialAuth/              第三方登录（工厂模式）
│   ├── User/
│   │   ├── UserProfileService       用户信息维护（资料修改、换手机号）
│   │   └── AddressService           收货地址 CRUD + 默认地址管理
│   ├── Product/ProductService
│   ├── Cart/CartService
│   ├── Promotion/                   营销模块
│   │   ├── CouponService            优惠券领取/核销/退回
│   │   ├── PromotionService         促销活动查询/匹配
│   │   └── Discount/                责任链优惠计算
│   │       ├── DiscountHandlerInterface
│   │       ├── AbstractDiscountHandler
│   │       ├── DiscountPipeline         链的组装与调用入口
│   │       ├── PromotionHandler         促销活动（满减/折扣）
│   │       ├── CouponHandler            优惠券
│   │       ├── ShippingDiscountHandler  运费+免邮
│   │       ├── SummaryHandler           汇总计算实付
│   │       └── OrderContext             上下文对象
│   ├── Order/
│   │   ├── OrderService
│   │   ├── OrderNoGenerator
│   │   └── Payment/                 支付子模块（模板方法+策略模式）
│   │       ├── PaymentManager           策略解析（gateway+platform → driver）
│   │       ├── PaymentGatewayInterface  支付网关接口契约
│   │       ├── AbstractPaymentGateway   模板方法基类（通用流程骨架）
│   │       ├── Wechat/                  微信支付
│   │       │   ├── AbstractWechatPayment    微信通用层（验签/解密/退款）
│   │       │   ├── WechatAppPayment         APP支付
│   │       │   ├── WechatMiniPayment        小程序JSAPI支付
│   │       │   ├── WechatH5Payment          H5支付
│   │       │   └── WechatNativePayment      PC扫码支付
│   │       └── Alipay/                  支付宝
│   │           ├── AbstractAlipayPayment    支付宝通用层（验签/退款）
│   │           ├── AlipayAppPayment         APP支付
│   │           ├── AlipayMiniPayment        小程序支付
│   │           ├── AlipayWapPayment         H5 WAP支付
│   │           └── AlipayPagePayment        PC网页支付
│   ├── Shipping/ShippingService
│   └── AfterSale/AfterSaleService
│
├── Enums/                           PHP 8.2+ 枚举
│   ├── OrderStatus
│   ├── PaymentStatus               pending/paid/refunding/refunded/failed
│   ├── PayScene                    wechat_app/wechat_mini/.../alipay_pc
│   ├── CouponType                  fixed_amount/percentage/no_threshold/free_shipping
│   ├── CouponStatus                unused/used/expired
│   ├── PromotionType               full_reduction/percentage
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

### 6.2 核心设计原则

- **分层架构**：Controller → Service → Repository → Model 的清晰四层分离
- **职责分离**：每个目录都有明确的职责边界
- **可扩展性**：支持插件化开发和模块化扩展
- **标准化**：遵循 PSR 规范和最佳实践

#### 各层职责

```
Controller（控制器层）
  ├── 接收请求，委托 FormRequest 做参数校验
  ├── 调用 Service 处理业务
  ├── 通过 Resource 格式化响应
  └── 不包含任何业务逻辑或数据查询

Service（业务逻辑层）
  ├── 编排业务流程（如下单 = 校验库存 + 扣库存 + 创建订单 + 清购物车）
  ├── 管理事务边界
  ├── 触发事件
  ├── 通过 Repository 接口访问数据，不直接操作 Model
  └── Service 之间可以互相调用

Repository（数据访问层）
  ├── 封装所有数据访问逻辑，支持 MySQL（Eloquent）和 Redis 双数据源
  ├── 通过接口（Contract）定义，实现依赖倒置
  ├── Eloquent/ — MySQL 实现，处理所有数据库读写
  ├── Cache/ — Redis 缓存装饰器，包装 Eloquent 实现
  │   ├── 读操作：先查 Redis，命中则返回；未命中查 MySQL 后回填 Redis
  │   ├── 写操作：先写 MySQL，成功后删除/更新 Redis 缓存
  │   └── 仅对高频读取的 Repository 加缓存，非所有 Repository 都需要
  ├── 提供通用 CRUD（BaseRepository）+ 业务专用查询方法
  └── 复杂查询（如商品搜索/筛选）集中在 Repository 中

Model（数据模型层）
  ├── 定义表结构、关联关系、属性转换
  ├── 定义 scope（查询作用域）
  ├── 不包含业务逻辑
  └── 仅被 Repository 层引用
```

#### Repository 接口示例

```php
// app/Repositories/Contracts/ProductRepositoryInterface.php
interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
    public function getListByCategory(?int $categoryId, int $perPage): LengthAwarePaginator;
    public function search(string $keyword, array $filters, int $perPage): LengthAwarePaginator;
}
```

#### 缓存装饰器示例

```php
// app/Repositories/Cache/CachingProductRepository.php
class CachingProductRepository implements ProductRepositoryInterface
{
    public function __construct(
        private ProductRepository $eloquent,  // 被装饰的 Eloquent 实现
        private CacheManager $cache,
    ) {}

    public function findById(int $id): ?Product
    {
        return $this->cache->remember(
            "product:{$id}",
            ttl: 3600, // 1小时
            callback: fn () => $this->eloquent->findById($id)
        );
    }

    // 列表/搜索类查询不走缓存，直接透传到 Eloquent
    public function getListByCategory(?int $categoryId, int $perPage): LengthAwarePaginator
    {
        return $this->eloquent->getListByCategory($categoryId, $perPage);
    }
}
```

#### 缓存策略

| Repository | 是否缓存 | 缓存策略 | TTL |
|-----------|---------|---------|-----|
| ProductRepository | 是 | 商品详情按 ID 缓存，列表不缓存 | 1h |
| CategoryRepository | 是 | 分类树整体缓存 | 24h |
| ProductSkuRepository | 是 | SKU 详情缓存（不含库存），库存单独缓存 | 30min |
| UserRepository | 是 | 用户基础信息缓存 | 1h |
| OrderRepository | 否 | 订单数据实时性要求高 | - |
| CartRepository | 否 | 购物车频繁变动，缓存收益低 | - |
| PaymentRepository | 否 | 支付数据必须实时 | - |
| AfterSaleRepository | 否 | 售后数据实时性要求高 | - |

**缓存失效策略：** 写操作后主动删除对应缓存 key，下次读取时自动回填。

#### 依赖注入绑定（装饰器组装）

```php
// app/Repositories/RepositoryServiceProvider.php

// 需要缓存的 Repository：接口 → 缓存装饰器（内部包装 Eloquent 实现）
$this->app->bind(ProductRepositoryInterface::class, function ($app) {
    return new CachingProductRepository(
        eloquent: $app->make(ProductRepository::class),
        cache: $app->make(CacheManager::class),
    );
});

// 不需要缓存的 Repository：接口 → 直接绑定 Eloquent 实现
$this->app->bind(OrderRepositoryInterface::class, OrderRepository::class);
$this->app->bind(CartRepositoryInterface::class, CartRepository::class);
$this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
```

### 6.3 架构模式

| 模式 | 应用场景 |
|------|----------|
| 四层架构 | Controller → Service → Repository → Model |
| Repository Pattern | 数据访问抽象，接口与实现分离 |
| 装饰器模式 | Redis 缓存层包装 Eloquent 实现，对 Service 透明 |
| 工厂模式 | 第三方登录 SocialAuthManager |
| 策略模式 | 支付网关 PaymentManager（渠道×场景自动解析） |
| 模板方法模式 | 支付流程骨架 AbstractPaymentGateway（pay/handleCallback/refund） |
| 责任链模式 | 优惠计算 DiscountPipeline（活动→优惠券→运费→汇总） |
| 事件驱动 | 登录日志、订单状态变更通知、库存扣减 |
| 枚举 | 状态管理（订单/支付/售后/平台） |
| 依赖倒置 | Service 依赖 Repository 接口，不依赖具体实现 |

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

由 `AbstractPaymentGateway.processPaymentResult()` 通用方法统一处理：

- payment_no 唯一约束
- 回调处理前先查 payment.status，已是 paid 则直接返回成功（幂等快路径）
- 使用 `SELECT ... FOR UPDATE` 悲观锁防并发重复回调
- 双重检查：加锁后再次校验 status，防止并发窗口期重复处理
- 更新 payments + orders 在同一事务中
- 事务提交后触发 OrderPaid 事件（异步通知等）

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
2. 执行 DiscountPipeline 责任链计算优惠（活动→优惠券→运费→汇总）
3. 乐观锁扣减库存（失败则回滚）
4. 创建 Order 记录（含 discount_amount, coupon_id, discount_detail）
5. 创建 OrderItem 记录（快照商品信息）
6. 核销优惠券（user_coupons.status → used）
7. 清除已下单的购物车条目
8. 事务提交
9. 触发 OrderCreated 事件

### 7.7 定时任务

| 频率 | 任务 | 说明 |
|------|------|------|
| 每分钟 | CloseExpiredOrders | 关闭超时未支付订单（30min），通知第三方关闭交易+恢复库存 |
| 每分钟 | QueryPendingPayments | 主动查询5-30分钟内的待支付记录（回调丢失兜底） |
| 每天 | CleanExpiredTokens | 清理过期 Refresh Token |
| 每天 | AutoConfirmOrder | 自动确认收货（15天） |
| 每天 | ExpireUserCoupons | 标记过期优惠券 status → expired |
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

### 7.9 数据脱敏

- **脱敏层**：统一在 API Resource 层处理，Service/Repository 层始终操作完整数据
- **工具类**：`DataMasker` 提供 `phone()`、`email()`、`name()`、`address()` 四个静态方法
- **Resource 双模式**：`UserAddressResource` 支持 `masked`（默认）和 `unmasked` 两种输出模式，编辑场景使用 `UserAddressResource::unmasked($address)` 返回完整数据
- **订单地址快照**：`orders.address_snapshot` JSON 字段存储完整地址数据，展示时通过 `OrderResource` 内联脱敏
- **安全原则**：永远不在 API 响应中返回 password、第三方 access_token/refresh_token 等凭证字段

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
4. **营销模块** — 促销活动/优惠券/责任链优惠计算管道
5. **购物车** — 加购/合并/选中/失效检测
6. **订单与支付** — 下单预览/下单（含优惠计算）/支付网关/回调/状态机
7. **物流** — 依赖订单
8. **售后** — 依赖订单（退款+优惠券退回）
