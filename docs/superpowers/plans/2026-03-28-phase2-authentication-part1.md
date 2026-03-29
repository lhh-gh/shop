# Phase 2: User Authentication & Authorization System - Implementation Plan

**Created:** 2026-03-28
**Phase:** 2 - User Authentication & Authorization
**Estimated Duration:** 8-12 hours
**Prerequisites:** Phase 1 (Infrastructure) completed

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture Summary](#architecture-summary)
3. [File Structure](#file-structure)
4. [Implementation Tasks](#implementation-tasks)

---

## Overview

### Goals

Build a complete user authentication infrastructure with:
- JWT Access Token (2h expiry) + Refresh Token (30d expiry) dual-token mechanism
- Multi-platform login support (app, mini_program, h5, pc)
- Same-platform device kicking (new login kicks old device)
- Token leak detection (IP + User-Agent fingerprint)
- Multiple login methods (SMS, Password, WeChat, Alipay)
- Security features (JWT blacklist, rate limiting, security logging)
- Device management

### Technical Stack

- **Framework:** Laravel 12, PHP 8.2+
- **JWT Library:** php-open-source-saver/jwt-auth
- **Cache/Queue:** Redis
- **Database:** MySQL
- **Testing:** PHPUnit 11

### Key Design Principles

1. **TDD Approach:** Write test first → see it fail → implement → see it pass
2. **Security First:** Multiple layers of protection
3. **Platform Awareness:** Different strategies for PC vs mobile
4. **Graceful Degradation:** Service continues even if Redis is down
5. **Audit Trail:** All security events logged

---

## Architecture Summary

### Token Flow

```
┌─────────────────────────────────────────────────────────────┐
│                     Token Lifecycle                          │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  1. Login → Issue Access Token (JWT, 2h) + Refresh Token   │
│                                                             │
│  2. API Request → Verify JWT signature → Check blacklist   │
│                                                             │
│  3. Token Expires → Client calls /auth/refresh             │
│                                                             │
│  4. Refresh → Leak detection → Rotate tokens               │
│                                                             │
│  5. Device Kick → Add JWT to blacklist + Delete refresh    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Database Schema

**users** table (extend existing):
- id, phone, email, password, nickname, avatar
- phone_verified_at, email_verified_at
- status (1=active, 0=disabled)
- created_at, updated_at

**user_tokens** table:
- id, user_id, platform, token (SHA256 hashed)
- device_name, client_ip, user_agent
- last_jwt_jti, last_active_at
- expires_at, created_at, updated_at

**user_social_accounts** table:
- id, user_id, platform (wechat_app, wechat_mini, alipay)
- platform_id (openid), union_id
- nickname, avatar, access_token, refresh_token
- expires_at, created_at, updated_at

**security_logs** table:
- id, user_id, event, detail (JSON)
- ip, user_agent, created_at

### Middleware Stack

```
Request
  ↓
JwtAuthenticate (parse & verify JWT)
  ↓
JwtBlacklist (check if kicked)
  ↓
OptionalDatabaseCheck (verify user status, graceful degradation)
  ↓
TokenLeakDetection (on refresh only)
  ↓
PlatformIdentify (extract X-Platform header)
  ↓
Controller
```

---

## File Structure

```
app/
├── Models/
│   ├── User.php (extend)
│   ├── UserToken.php
│   ├── UserSocialAccount.php
│   └── SecurityLog.php
│
├── Services/
│   ├── Auth/
│   │   ├── JwtService.php
│   │   ├── AuthService.php
│   │   ├── DeviceService.php
│   │   ├── SmsService.php
│   │   ├── SocialAuthManager.php
│   │   ├── WeChatAuthProvider.php
│   │   ├── AlipayAuthProvider.php
│   │   └── LogsSecurityEvent.php (trait, exists)
│   │
│   └── Repositories/
│       ├── UserRepository.php
│       ├── UserTokenRepository.php
│       └── SecurityLogRepository.php
│
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php
│   │   ├── TokenController.php
│   │   └── DeviceController.php
│   │
│   ├── Middleware/
│   │   ├── JwtAuthenticate.php
│   │   ├── JwtBlacklist.php
│   │   ├── OptionalDatabaseCheck.php
│   │   ├── TokenLeakDetection.php
│   │   └── PlatformIdentify.php
│   │
│   ├── Requests/Auth/
│   │   ├── LoginBySmsRequest.php
│   │   ├── LoginByPasswordRequest.php
│   │   ├── LoginByWeChatRequest.php
│   │   ├── RefreshTokenRequest.php
│   │   └── SendSmsCodeRequest.php
│   │
│   └── Resources/
│       ├── UserResource.php
│       └── DeviceResource.php
│
└── Support/
    └── DataMasker.php

database/migrations/
├── 2026_03_28_100000_extend_users_table.php
├── 2026_03_28_100001_create_user_tokens_table.php
├── 2026_03_28_100002_create_user_social_accounts_table.php
└── 2026_03_28_100003_create_security_logs_table.php

tests/
├── Unit/Services/
│   ├── JwtServiceTest.php
│   ├── AuthServiceTest.php
│   └── DataMaskerTest.php
│
└── Feature/Auth/
    ├── LoginBySmsTest.php
    ├── LoginByPasswordTest.php
    ├── RefreshTokenTest.php
    └── DeviceKickTest.php
```

---

## Implementation Tasks

Due to file size limits, this plan is split into multiple parts.
See the following files for complete implementation details:

- Part 1 (this file): Overview, Architecture, File Structure
- Part 2: Detailed implementation tasks with complete code
- Part 3: Testing strategy, configuration, API endpoints

Continue to Part 2 for detailed implementation steps.
