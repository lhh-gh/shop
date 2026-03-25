# 商城系统整体设计文档

## 1. 项目概述

基于 Laravel 12 构建的完整 C 端商城系统，提供商品浏览、购物车、订单、支付、物流、售后等完整电商功能，以及一套支持多平台登录、Token 泄露检测、同平台多设备互踢的用户认证体系。

### 技术栈

- **框架**: Laravel 12 (PHP 8.2+)
- **认证**: JWT Access Token + Database Refresh Token (php-open-source-saver/jwt-auth)
- **数据库**: MySQL
- **缓存**: Redis（Repository 缓存层 + JWT 黑名单 + 短信验证码 + 频率限制）
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

#### 2.4.5 Token 刷新

**时序图 — Token 刷新（含泄露检测）：**

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
    │                  │                   │ 2. 泄露检测                  │
    │                  │                   │ ┌────────────────────────┐   │
    │                  │                   │ │ PC平台:                │   │
    │                  │                   │ │  IP不匹配 OR UA不匹配   │   │
    │                  │                   │ │  → 判定泄露             │   │
    │                  │                   │ │                        │   │
    │                  │                   │ │ 移动平台(APP/H5/小程序):│   │
    │                  │                   │ │  IP不匹配 AND UA不匹配  │   │
    │                  │                   │ │  → 判定泄露             │   │
    │                  │                   │ │  仅IP变化 → 正常(移动网络)│  │
    │                  │                   │ └────────────────────────┘   │
    │                  │                   │                │             │
    │                  │                   │ ┌─ 泄露检测触发 ──────────┐  │
    │                  │                   │ │ DELETE FROM user_tokens  │  │
    │                  │                   │ │ WHERE id = ?             │  │
    │                  │                   │ │                          │  │
    │                  │                   │ │ INSERT INTO security_logs│  │
    │                  │                   │ │ {event: "token_leak"}    │  │
    │                  │                   │ │                          │  │
    │                  │                   │ │ 返回 401 (40103)        │  │
    │                  │                   │ └──────────────────────────┘  │
    │                  │                   │                │             │
    │                  │                   │ 3. 泄露检测通过，签发新Token │
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
    │ 客户端更新存储的双Token               │                │             │
```

**客户端 Token 刷新策略：**

```
┌─────────────────────────────────────────────────────────────────┐
│                  客户端 Token 刷新策略                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  策略一：被动刷新（推荐）                                        │
│  ├── API 请求收到 401 + code:40101 (Token过期)                  │
│  ├── 自动用 Refresh Token 调用 /auth/refresh                    │
│  ├── 获取新双Token，重试原始请求                                 │
│  └── 刷新失败 → 跳转登录页                                      │
│                                                                 │
│  策略二：主动刷新（可选增强）                                     │
│  ├── 客户端记录 Access Token 过期时间                            │
│  ├── 在过期前 5 分钟主动刷新                                     │
│  └── 避免用户感知到 Token 过期的瞬间                             │
│                                                                 │
│  并发刷新防护：                                                  │
│  ├── 多个并发请求同时发现 Token 过期                             │
│  ├── 客户端用 Promise 锁保证只发一次刷新请求                     │
│  └── 其他请求等待刷新完成后用新 Token 重试                       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### 2.4.6 Token 吊销（设备互踢）

**时序图 — 新设备登录踢旧设备：**

```
时间线 ──────────────────────────────────────────────────────────────>

T0: 张三用 iPhone 登录 APP
    └─ user_tokens: {id:1, user_id:张三, platform:app, token:hash_A}
    └─ iPhone 持有: access_token_A (jti: jti_A) + refresh_token_A

T1: 张三用 Android 登录 APP （触发同平台互踢）
    │
    ├─ Step1: 查出张三 platform=app 的旧 token → 找到 id:1
    │
    ├─ Step2: 将 jti_A 写入 Redis 黑名单
    │   └─ SET jwt_blacklist:jti_A 1 EX 7200
    │
    ├─ Step3: 删除旧 Refresh Token
    │   └─ DELETE FROM user_tokens WHERE id=1
    │
    ├─ Step4: 为 Android 签发新 Token
    │   └─ user_tokens: {id:2, user_id:张三, platform:app, token:hash_B}
    │   └─ Android 持有: access_token_B (jti: jti_B) + refresh_token_B
    │
    └─ 此时张三的 Token 状态:
        APP(Android): access_token_B ✓ + refresh_token_B ✓  ← 正常
        APP(iPhone):  access_token_A ✗ + refresh_token_A ✗  ← 已失效
        小程序:       access_token_C ✓ + refresh_token_C ✓  ← 不受影响
        PC:          access_token_D ✓ + refresh_token_D ✓  ← 不受影响

T2: iPhone 发起 API 请求 (携带 access_token_A)
    │
    ├─ JwtAuth 中间件: JWT 验签通过，未过期
    ├─ JwtBlacklist 中间件: 查 Redis → jwt_blacklist:jti_A 存在!
    └─ 返回 401 {code: 40104, message: "您的账号已在另一台设备登录"}

T3: iPhone 尝试刷新 Token (携带 refresh_token_A)
    │
    ├─ 计算 SHA256(refresh_token_A) 查库
    ├─ 未找到记录（已在T1被删除）
    └─ 返回 401 {code: 40102, message: "登录已过期，请重新登录"}

T4: iPhone 跳转登录页
```

#### 2.4.7 Token 完整生命周期

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Token 完整生命周期                                │
│                                                                     │
│  ┌──────────┐                                                       │
│  │  签发     │  用户登录成功                                         │
│  │ (Birth)  │  → JWT Access Token (2h) + Refresh Token (30天)      │
│  └────┬─────┘                                                       │
│       │                                                             │
│       ▼                                                             │
│  ┌──────────┐                                                       │
│  │  使用     │  每次 API 请求携带 Access Token                       │
│  │ (Active) │  中间件验签 → 黑名单检查 → [可选]查库 → 放行           │
│  └────┬─────┘                                                       │
│       │                                                             │
│       ▼  Access Token 过期 (2h)                                     │
│  ┌──────────┐                                                       │
│  │  刷新     │  用 Refresh Token 获取新的双 Token                    │
│  │(Refresh) │  泄露检测 → 旋转 Refresh Token → 签发新 Access Token  │
│  └────┬─────┘                                                       │
│       │   ↑                                                         │
│       │   └──── 循环：每2h刷新一次，Refresh Token 30天内有效         │
│       │                                                             │
│       ▼  最终失效                                                    │
│  ┌──────────┐                                                       │
│  │  死亡     │  以下任一情况触发:                                     │
│  │ (Death)  │  ├── Refresh Token 过期 (30天到期)                    │
│  └──────────┘  ├── 用户主动登出 (DELETE Refresh Token)              │
│                ├── 同平台新设备登录 (互踢)                           │
│                ├── Token 泄露检测触发                                │
│                ├── 用户主动踢设备                                    │
│                ├── 管理员封禁账号                                    │
│                └── 用户修改密码 (可选：所有Token失效)                 │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
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
       ├─ password_histories (1:N)
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

共 **18 张表**。

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
POST   /api/v1/auth/password/change        修改密码（需二次验证）

POST   /api/v1/auth/verify-identity         二次身份验证（签发 verify_token）

GET    /api/v1/user/profile                获取个人信息
PUT    /api/v1/user/profile                修改个人信息
POST   /api/v1/user/phone/bind             绑定手机号（第三方登录用户，需二次验证）
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
│   │   ├── SensitiveOperation       敏感操作二次验证
│   │   └── GatewaySignatureVerify
│   ├── Requests/Api/V1/             表单验证
│   └── Resources/Api/V1/            API 资源转换
│
├── Models/                          18 个 Eloquent 模型
├── Repositories/                    数据访问层（Repository Pattern）
│   ├── Contracts/                   Repository 接口定义
│   │   ├── UserRepositoryInterface
│   │   ├── ProductRepositoryInterface
│   │   ├── OrderRepositoryInterface
│   │   ├── CartRepositoryInterface
│   │   ├── PaymentRepositoryInterface
│   │   └── AfterSaleRepositoryInterface
│   ├── Eloquent/                    MySQL 实现（Eloquent ORM）
│   │   ├── BaseRepository           基础 Repository（通用 CRUD）
│   │   ├── UserRepository
│   │   ├── UserTokenRepository
│   │   ├── UserSocialAccountRepository
│   │   ├── ProductRepository
│   │   ├── ProductSkuRepository
│   │   ├── CategoryRepository
│   │   ├── CartRepository
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
| 策略模式 | 支付网关 PaymentGatewayInterface |
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
