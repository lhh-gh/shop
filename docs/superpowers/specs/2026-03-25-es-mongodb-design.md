# MongoDB 与 Elasticsearch 集成设计文档

> 本文档是商城系统设计文档的补充，聚焦 Elasticsearch（商品搜索引擎）和 MongoDB（灵活文档存储）的集成设计。

## 1. 概述

### 1.1 为什么引入

| 技术 | 解决什么问题 | 替代方案的不足 |
|------|------------|---------------|
| **Elasticsearch** | 商品全文搜索、中文分词、多维度筛选、搜索建议 | MySQL LIKE/FULLTEXT 不支持中文分词，无法按相关度排序，组合筛选性能差 |
| **MongoDB** | 用户浏览记录（高频写入）、搜索日志（灵活 schema）、操作审计（海量追加） | MySQL 行式存储对高频写入和灵活 schema 不友好，单表数据量大后读写性能下降 |

### 1.2 更新后的技术栈

```
框架:     Laravel 12 (PHP 8.2+)
认证:     JWT Access Token + Database Refresh Token
关系库:   MySQL（核心业务数据：用户、订单、支付、商品主数据）
搜索引擎: Elasticsearch 8.x（商品搜索、搜索建议、搜索排名）
文档库:   MongoDB 7.x（浏览记录、搜索日志、操作审计）
缓存:     Redis（JWT 黑名单、验证码、频率限制、Repository 缓存层）
队列:     Database driver（可切换 Redis）
支付:     wechatpay/wechatpay-php, alipaysdk/openapi-sdk-php
```

### 1.3 各存储引擎职责边界

```
┌────────────────────────────────────────────────────────────────────┐
│                        数据存储职责划分                               │
├──────────────┬─────────────────────────────────────────────────────┤
│              │                                                     │
│  MySQL       │  所有核心业务数据（事务保证 ACID）                     │
│  (主库)      │  ├── 用户、认证、Token、安全日志                      │
│              │  ├── 商品主数据（SPU/SKU/分类/属性）                  │
│              │  ├── 订单、支付、物流、售后                            │
│              │  ├── 购物车                                          │
│              │  └── 优惠券、促销活动                                 │
│              │                                                     │
│  原则: 所有需要事务、强一致性、关联查询的数据                         │
│                                                                    │
├──────────────┼─────────────────────────────────────────────────────┤
│              │                                                     │
│  Elastic-    │  商品搜索副本（从 MySQL 同步）                        │
│  search      │  ├── 全文检索（中文分词 + 同义词）                    │
│  (搜索引擎)  │  ├── 多维度聚合筛选（价格/分类/属性/销量）            │
│              │  ├── 搜索建议 / 自动补全                             │
│              │  └── 搜索结果相关度排序                               │
│              │                                                     │
│  原则: 只存搜索需要的字段，MySQL 是数据权威源                        │
│                                                                    │
├──────────────┼─────────────────────────────────────────────────────┤
│              │                                                     │
│  MongoDB     │  高频写入 / 灵活 schema / 分析聚合                   │
│  (文档库)    │  ├── 用户商品浏览记录（高频写入，TTL 自动清理）        │
│              │  ├── 搜索关键词日志（热搜词分析，聚合统计）            │
│              │  └── 操作审计日志（灵活 detail，海量追加写入）         │
│              │                                                     │
│  原则: 不需要事务保证、写多读少、schema 灵活的辅助数据               │
│                                                                    │
├──────────────┼─────────────────────────────────────────────────────┤
│              │                                                     │
│  Redis       │  高速缓存 + 临时数据                                  │
│  (缓存)      │  ├── Repository 缓存层（商品/分类/用户）              │
│              │  ├── JWT 黑名单（TTL=2h）                            │
│              │  ├── 短信验证码 / 二次验证 Token                     │
│              │  └── 频率限制计数器                                   │
│              │                                                     │
│  原则: 临时性数据，丢失不影响业务正确性                               │
│                                                                    │
└──────────────┴─────────────────────────────────────────────────────┘
```

### 1.4 PHP 包选择

| 技术 | 包名 | 说明 |
|------|------|------|
| Elasticsearch | `elasticsearch/elasticsearch` | Elastic 官方 PHP 客户端，支持 ES 8.x |
| MongoDB | `mongodb/laravel-mongodb` | Laravel 官方推荐，支持 Eloquent 风格操作 MongoDB |

```bash
composer require elasticsearch/elasticsearch
composer require mongodb/laravel-mongodb
```

---

## 2. Elasticsearch — 商品搜索引擎

### 2.1 解决的问题

| 场景 | MySQL 方案 | 问题 | ES 方案 |
|------|-----------|------|---------|
| 搜索"苹果手机" | `WHERE title LIKE '%苹果手机%'` | 不支持分词，搜不到"Apple iPhone" | IK 分词 + 同义词，自动匹配 |
| 价格区间+分类+属性筛选 | 多 JOIN + WHERE | 组合多了性能急剧下降 | 倒排索引 + filter，毫秒级 |
| 按相关度排序 | 无法实现 | MySQL 只能按字段排序 | BM25 算法自动按匹配度排序 |
| 搜索建议 | 额外建表维护热词 | 复杂且实时性差 | completion suggester，原生支持 |
| 分类商品数量统计 | COUNT GROUP BY | 数据量大时慢 | aggs 聚合，毫秒级 |

### 2.2 索引设计

**索引名**: `shop_products`

**字段映射 (Mapping):**

```json
{
    "settings": {
        "number_of_shards": 1,
        "number_of_replicas": 0,
        "analysis": {
            "analyzer": {
                "ik_smart_synonym": {
                    "type": "custom",
                    "tokenizer": "ik_smart",
                    "filter": ["synonym_filter", "lowercase"]
                },
                "ik_max_synonym": {
                    "type": "custom",
                    "tokenizer": "ik_max_word",
                    "filter": ["synonym_filter", "lowercase"]
                }
            },
            "filter": {
                "synonym_filter": {
                    "type": "synonym",
                    "synonyms": [
                        "苹果,apple,iphone",
                        "华为,huawei",
                        "手机,手机电话,移动电话",
                        "电脑,笔记本,laptop,notebook"
                    ]
                }
            }
        }
    },
    "mappings": {
        "properties": {
            "id":             { "type": "long" },
            "title":          { "type": "text", "analyzer": "ik_max_synonym", "search_analyzer": "ik_smart_synonym" },
            "subtitle":       { "type": "text", "analyzer": "ik_smart_synonym" },
            "category_id":    { "type": "integer" },
            "category_name":  { "type": "keyword" },
            "category_path":  { "type": "keyword" },
            "main_image":     { "type": "keyword", "index": false },
            "base_price":     { "type": "scaled_float", "scaling_factor": 100 },
            "price_range": {
                "type": "float_range"
            },
            "sales_count":    { "type": "integer" },
            "review_count":   { "type": "integer" },
            "status":         { "type": "keyword" },
            "skus": {
                "type": "nested",
                "properties": {
                    "id":         { "type": "long" },
                    "title":      { "type": "text", "analyzer": "ik_smart_synonym" },
                    "price":      { "type": "scaled_float", "scaling_factor": 100 },
                    "stock":      { "type": "integer" },
                    "attributes": { "type": "flattened" }
                }
            },
            "attributes": {
                "type": "nested",
                "properties": {
                    "name":  { "type": "keyword" },
                    "value": { "type": "keyword" }
                }
            },
            "suggest": {
                "type": "completion",
                "analyzer": "ik_smart_synonym"
            },
            "created_at":  { "type": "date" },
            "updated_at":  { "type": "date" }
        }
    }
}
```

**字段说明：**

| ES 字段 | 来源 | 说明 |
|---------|------|------|
| `title` | `products.title` | 主搜索字段，IK 最细粒度分词（索引时），智能分词（搜索时） |
| `subtitle` | `products.subtitle` | 辅助搜索字段 |
| `category_name` | `categories.name` | keyword 精确匹配/聚合用 |
| `category_path` | 拼接的分类路径 | 如 "数码/手机"，用于分类层级筛选 |
| `base_price` | `products.base_price` | scaled_float 精确表示价格（避免浮点误差） |
| `price_range` | SKU 最低价~最高价 | 价格区间筛选用 |
| `skus` | `product_skus` | nested 类型，支持 SKU 级别的价格/属性筛选 |
| `attributes` | `product_attributes` + SKU attributes | nested 类型，支持按属性值筛选（颜色:红色） |
| `suggest` | `products.title` + 分类名 | completion 类型，搜索建议/自动补全 |

### 2.3 数据同步策略（MySQL → ES）

**同步方式：事件驱动增量同步 + 定时全量兜底**

```
┌──────────────────────────────────────────────────────────────────┐
│                    MySQL → Elasticsearch 同步策略                  │
│                                                                  │
│  策略一：事件驱动增量同步（主要）                                    │
│  ┌─────────────┐     ┌──────────────┐     ┌─────────────────┐   │
│  │ MySQL 写入   │────→│ Laravel Event │────→│ 队列任务         │   │
│  │ product/sku  │     │ ProductSaved  │     │ SyncProductToEs │   │
│  │ 变更         │     │ ProductDeleted│     │                 │   │
│  └─────────────┘     └──────────────┘     └────────┬────────┘   │
│                                                     │            │
│                                                     ▼            │
│                                              ┌─────────────┐    │
│                                              │ ES Index API │    │
│                                              │ index/update │    │
│                                              │ /delete      │    │
│                                              └─────────────┘    │
│                                                                  │
│  策略二：定时全量同步（兜底，每日凌晨）                               │
│  ┌───────────────────┐     ┌────────────────────────┐           │
│  │ Schedule Command   │────→│ 全量遍历 products 表    │           │
│  │ es:sync-products   │     │ bulk API 批量写入 ES    │           │
│  └───────────────────┘     └────────────────────────┘           │
│                                                                  │
│  原则：增量同步保证实时性，全量同步保证一致性                         │
└──────────────────────────────────────────────────────────────────┘
```

**增量同步实现：**

```php
// 事件触发（在 Product Model 中）
protected $dispatchesEvents = [
    'saved'   => ProductSaved::class,
    'deleted' => ProductDeleted::class,
];

// 监听器 → 分发队列任务
class SyncProductToEsListener
{
    public function handle(ProductSaved $event): void
    {
        SyncProductToEs::dispatch($event->product->id)->onQueue('es_sync');
    }
}

// 队列任务（异步，不阻塞业务写入）
class SyncProductToEs implements ShouldQueue
{
    public function handle(ElasticProductIndexer $indexer): void
    {
        $indexer->indexProduct($this->productId);
    }
}
```

**全量同步命令：**

```php
// php artisan es:sync-products
class SyncProductsToEsCommand extends Command
{
    protected $signature = 'es:sync-products {--fresh : 删除旧索引重建}';

    public function handle(ElasticProductIndexer $indexer): void
    {
        if ($this->option('fresh')) {
            $indexer->deleteIndex();
            $indexer->createIndex();
        }

        Product::with(['skus', 'attributes', 'category'])
            ->where('status', 1)
            ->chunkById(500, function ($products) use ($indexer) {
                $indexer->bulkIndex($products);
                $this->output->write('.');
            });
    }
}
```

### 2.4 搜索功能详细设计

#### 2.4.1 全文搜索

用户输入关键词，在 title、subtitle、SKU title 中搜索，按相关度排序：

```json
{
    "query": {
        "bool": {
            "must": [
                {
                    "multi_match": {
                        "query": "苹果手机",
                        "fields": ["title^3", "subtitle^1.5", "skus.title"],
                        "type": "best_fields",
                        "minimum_should_match": "75%"
                    }
                }
            ],
            "filter": [
                { "term": { "status": "1" } }
            ]
        }
    }
}
```

- `title^3`：标题权重最高（命中标题比命中副标题更相关）
- `minimum_should_match: 75%`：搜索词分词后至少匹配 75%（"苹果 手机"两个词至少匹配一个以上）
- `filter` 中的条件不参与评分，只做过滤（性能更好）

#### 2.4.2 多维度筛选

用户在搜索结果页进行筛选（价格区间、分类、属性）：

```json
{
    "query": {
        "bool": {
            "must": [
                { "multi_match": { "query": "手机", "fields": ["title^3", "subtitle"] } }
            ],
            "filter": [
                { "term": { "status": "1" } },
                { "term": { "category_id": 5 } },
                { "range": { "base_price": { "gte": 3000, "lte": 8000 } } },
                {
                    "nested": {
                        "path": "attributes",
                        "query": {
                            "bool": {
                                "must": [
                                    { "term": { "attributes.name": "颜色" } },
                                    { "term": { "attributes.value": "黑色" } }
                                ]
                            }
                        }
                    }
                }
            ]
        }
    },
    "sort": [
        { "_score": "desc" },
        { "sales_count": "desc" }
    ]
}
```

#### 2.4.3 聚合统计（Aggregations）

搜索结果页侧边栏展示可选筛选项及数量：

```json
{
    "query": { ... },
    "aggs": {
        "categories": {
            "terms": { "field": "category_name", "size": 20 }
        },
        "price_ranges": {
            "range": {
                "field": "base_price",
                "ranges": [
                    { "to": 100 },
                    { "from": 100, "to": 500 },
                    { "from": 500, "to": 1000 },
                    { "from": 1000, "to": 3000 },
                    { "from": 3000, "to": 5000 },
                    { "from": 5000 }
                ]
            }
        },
        "attributes": {
            "nested": { "path": "attributes" },
            "aggs": {
                "attr_names": {
                    "terms": { "field": "attributes.name", "size": 20 },
                    "aggs": {
                        "attr_values": {
                            "terms": { "field": "attributes.value", "size": 50 }
                        }
                    }
                }
            }
        }
    },
    "size": 0
}
```

响应示例：

```json
{
    "aggregations": {
        "categories": {
            "buckets": [
                { "key": "手机", "doc_count": 120 },
                { "key": "手机壳", "doc_count": 45 }
            ]
        },
        "price_ranges": {
            "buckets": [
                { "key": "100.0-500.0", "doc_count": 30 },
                { "key": "500.0-1000.0", "doc_count": 55 }
            ]
        },
        "attributes": {
            "attr_names": {
                "buckets": [
                    {
                        "key": "颜色",
                        "attr_values": {
                            "buckets": [
                                { "key": "黑色", "doc_count": 80 },
                                { "key": "白色", "doc_count": 60 }
                            ]
                        }
                    }
                ]
            }
        }
    }
}
```

前端用这些聚合数据渲染筛选栏：

```
分类：手机(120) | 手机壳(45) | ...
价格：100-500(30) | 500-1000(55) | ...
颜色：黑色(80) | 白色(60) | ...
```

#### 2.4.4 搜索建议 / 自动补全

用户在搜索框输入时实时展示建议词：

```json
{
    "suggest": {
        "product_suggest": {
            "prefix": "苹",
            "completion": {
                "field": "suggest",
                "size": 5,
                "fuzzy": {
                    "fuzziness": "AUTO"
                }
            }
        }
    }
}
```

响应：

```json
{
    "suggest": {
        "product_suggest": [
            { "text": "苹果 iPhone 15 Pro", "score": 10 },
            { "text": "苹果 MacBook Pro", "score": 8 },
            { "text": "苹果数据线", "score": 5 }
        ]
    }
}
```

suggest 字段在索引时写入：

```php
// ElasticProductIndexer::buildDocument()
'suggest' => [
    'input' => [
        $product->title,
        $product->category->name,
        ...array_map(fn($s) => $s->title, $product->skus->all()),
    ],
    'weight' => $product->sales_count, // 销量越高，建议权重越高
],
```

### 2.5 搜索 API 设计

#### 2.5.1 商品搜索接口

```
GET /api/v1/products/search?keyword=苹果手机&category_id=5&price_min=3000&price_max=8000&attrs[颜色]=黑色&sort=sales_desc&page=1&per_page=20
```

| 参数 | 类型 | 说明 |
|------|------|------|
| `keyword` | string | 搜索关键词（IK 分词） |
| `category_id` | int | 分类筛选 |
| `price_min` / `price_max` | float | 价格区间 |
| `attrs[属性名]` | string | 属性筛选，支持多个 |
| `sort` | string | 排序：`relevance`(默认) / `price_asc` / `price_desc` / `sales_desc` / `newest` |
| `page` / `per_page` | int | 分页 |

响应：

```json
{
    "code": 0,
    "data": {
        "items": [
            {
                "id": 100,
                "title": "Apple iPhone 15 Pro",
                "subtitle": "钛金属设计，A17 Pro 芯片",
                "main_image": "https://...",
                "base_price": 7999.00,
                "sales_count": 5000,
                "category_name": "手机"
            }
        ],
        "pagination": { "total": 120, "per_page": 20, "current_page": 1 },
        "filters": {
            "categories": [
                { "name": "手机", "count": 120 },
                { "name": "手机壳", "count": 45 }
            ],
            "price_ranges": [
                { "label": "3000-5000", "count": 40 },
                { "label": "5000以上", "count": 80 }
            ],
            "attributes": [
                {
                    "name": "颜色",
                    "values": [
                        { "value": "黑色", "count": 80 },
                        { "value": "白色", "count": 60 }
                    ]
                }
            ]
        }
    }
}
```

#### 2.5.2 搜索建议接口

```
GET /api/v1/products/suggest?keyword=苹
```

响应：

```json
{
    "code": 0,
    "data": [
        "苹果 iPhone 15 Pro",
        "苹果 MacBook Pro",
        "苹果数据线"
    ]
}
```

#### 2.5.3 热搜词接口

```
GET /api/v1/products/hot-searches
```

响应来自 MongoDB 聚合（见 3.3.2）。

### 2.6 搜索时序图

**全文搜索完整流程：**

```
客户端              ProductController       ProductSearchService       Elasticsearch        MongoDB
  │                      │                       │                       │                   │
  │ GET /products/search │                       │                       │                   │
  │ ?keyword=苹果手机     │                       │                       │                   │
  │─────────────────────→│                       │                       │                   │
  │                      │                       │                       │                   │
  │                      │ search(keyword,       │                       │                   │
  │                      │   filters, sort)      │                       │                   │
  │                      │──────────────────────→│                       │                   │
  │                      │                       │                       │                   │
  │                      │                       │ 1. 构建 ES Query DSL   │                   │
  │                      │                       │    multi_match + filter│                   │
  │                      │                       │    + aggs + sort       │                   │
  │                      │                       │                       │                   │
  │                      │                       │ 2. ES search API      │                   │
  │                      │                       │──────────────────────→│                   │
  │                      │                       │  hits + aggregations  │                   │
  │                      │                       │←──────────────────────│                   │
  │                      │                       │                       │                   │
  │                      │                       │ 3. 异步记录搜索日志    │                   │
  │                      │                       │  LogSearchKeyword::dispatch ─────────────→│
  │                      │                       │                       │  insert search_logs│
  │                      │                       │                       │                   │
  │                      │ {items, pagination,   │                       │                   │
  │                      │  filters}             │                       │                   │
  │                      │←──────────────────────│                       │                   │
  │                      │                       │                       │                   │
  │  200 {items, pagination, filters}            │                       │                   │
  │←─────────────────────│                       │                       │                   │
```

**数据同步流程（商品保存 → ES 更新）：**

```
管理后台           ProductService          MySQL            Event/Queue         ES Indexer          ES
  │                    │                    │                   │                   │               │
  │ 保存商品           │                    │                   │                   │               │
  │───────────────────→│                    │                   │                   │               │
  │                    │                    │                   │                   │               │
  │                    │ UPDATE products... │                   │                   │               │
  │                    │───────────────────→│                   │                   │               │
  │                    │             done   │                   │                   │               │
  │                    │←───────────────────│                   │                   │               │
  │                    │                    │                   │                   │               │
  │                    │                    │  ProductSaved     │                   │               │
  │                    │                    │  事件自动触发 ────→│                   │               │
  │                    │                    │                   │                   │               │
  │  200 OK            │                    │ SyncProductToEs   │                   │               │
  │←───────────────────│                    │ 队列任务异步执行 ─→│                   │               │
  │                    │                    │                   │ indexProduct()    │               │
  │                    │                    │                   │──────────────────→│               │
  │                    │                    │                   │                   │ PUT /shop_    │
  │                    │                    │                   │                   │ products/_doc │
  │                    │                    │                   │                   │ /{product_id} │
  │                    │                    │                   │                   │──────────────→│
  │                    │                    │                   │                   │        OK     │
  │                    │                    │                   │                   │←──────────────│
```

> 同步是异步的（队列任务），不影响管理后台保存商品的响应速度。ES 数据会有短暂延迟（通常 < 1s）。

### 2.7 代码架构集成

#### 2.7.1 目录结构

```
app/
├── Services/
│   ├── Product/
│   │   ├── ProductService              商品 CRUD（MySQL）
│   │   └── ProductSearchService        商品搜索（ES）
│   │
│   └── Search/
│       ├── ElasticProductIndexer       ES 索引管理（创建/更新/删除/批量）
│       └── ElasticQueryBuilder         ES 查询构建器（封装 DSL 构建逻辑）
│
├── Repositories/
│   ├── Contracts/
│   │   └── ProductSearchRepositoryInterface   搜索 Repository 接口
│   ├── Elastic/
│   │   └── ElasticProductSearchRepository     ES 搜索实现
│   └── RepositoryServiceProvider              新增 ES 绑定
│
├── Jobs/
│   ├── SyncProductToEs                 单商品同步到 ES
│   └── LogSearchKeyword                记录搜索关键词到 MongoDB
│
├── Console/Commands/
│   ├── EsSyncProductsCommand           全量同步命令 (es:sync-products)
│   └── EsCreateIndexCommand            创建索引命令 (es:create-index)
│
└── Listeners/
    └── SyncProductToEsListener         监听商品变更事件
```

#### 2.7.2 搜索 Repository 接口

```php
// app/Repositories/Contracts/ProductSearchRepositoryInterface.php
interface ProductSearchRepositoryInterface
{
    /**
     * 全文搜索商品（含筛选+聚合+分页）
     */
    public function search(
        ?string $keyword,
        array $filters,   // ['category_id' => 5, 'price_min' => 100, 'attrs' => ['颜色' => '黑色']]
        string $sort,      // relevance / price_asc / price_desc / sales_desc / newest
        int $page,
        int $perPage,
    ): ProductSearchResult;

    /**
     * 搜索建议 / 自动补全
     */
    public function suggest(string $prefix, int $size = 5): array;
}
```

`ProductSearchRepositoryInterface` 与原有的 `ProductRepositoryInterface` 独立，原接口继续负责 MySQL CRUD，搜索接口专职 ES 查询。

#### 2.7.3 ProductSearchResult DTO

```php
class ProductSearchResult
{
    public function __construct(
        public readonly array $items,        // 商品列表
        public readonly int $total,          // 总数
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly array $filters,      // 聚合筛选项
    ) {}
}
```

#### 2.7.4 ElasticProductIndexer 核心方法

```php
class ElasticProductIndexer
{
    public function __construct(
        private Client $elastic,
        private string $index = 'shop_products',
    ) {}

    /**
     * 索引单个商品（创建或更新）
     */
    public function indexProduct(int $productId): void
    {
        $product = Product::with(['skus', 'attributes', 'category'])->find($productId);

        if (!$product || $product->status !== 1) {
            $this->deleteProduct($productId);
            return;
        }

        $this->elastic->index([
            'index' => $this->index,
            'id'    => $product->id,
            'body'  => $this->buildDocument($product),
        ]);
    }

    /**
     * 批量索引（全量同步用）
     */
    public function bulkIndex(Collection $products): void
    {
        $params = ['body' => []];
        foreach ($products as $product) {
            $params['body'][] = [
                'index' => ['_index' => $this->index, '_id' => $product->id],
            ];
            $params['body'][] = $this->buildDocument($product);
        }
        $this->elastic->bulk($params);
    }

    /**
     * 构建 ES 文档
     */
    private function buildDocument(Product $product): array
    {
        $skuPrices = $product->skus->pluck('price');

        return [
            'id'            => $product->id,
            'title'         => $product->title,
            'subtitle'      => $product->subtitle,
            'category_id'   => $product->category_id,
            'category_name' => $product->category?->name,
            'category_path' => $this->buildCategoryPath($product->category),
            'main_image'    => $product->main_image,
            'base_price'    => $product->base_price,
            'price_range'   => [
                'gte' => $skuPrices->min(),
                'lte' => $skuPrices->max(),
            ],
            'sales_count'   => $product->sales_count,
            'review_count'  => $product->review_count,
            'status'        => (string) $product->status,
            'skus' => $product->skus->map(fn ($sku) => [
                'id'         => $sku->id,
                'title'      => $sku->title,
                'price'      => $sku->price,
                'stock'      => $sku->stock,
                'attributes' => $sku->attributes,
            ])->all(),
            'attributes' => $this->flattenAttributes($product),
            'suggest' => [
                'input'  => array_filter([
                    $product->title,
                    $product->category?->name,
                    ...$product->skus->pluck('title')->all(),
                ]),
                'weight' => min($product->sales_count, 10000),
            ],
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * 删除商品索引
     */
    public function deleteProduct(int $productId): void
    {
        $this->elastic->delete([
            'index'  => $this->index,
            'id'     => $productId,
            'client' => ['ignore' => [404]],
        ]);
    }

    /**
     * 创建索引（含 mapping 和 settings）
     */
    public function createIndex(): void
    {
        $this->elastic->indices()->create([
            'index' => $this->index,
            'body'  => config('elasticsearch.indices.products'),
        ]);
    }

    /**
     * 删除索引
     */
    public function deleteIndex(): void
    {
        $this->elastic->indices()->delete([
            'index'  => $this->index,
            'client' => ['ignore' => [404]],
        ]);
    }
}
```

### 2.8 配置管理

```php
// config/elasticsearch.php
return [
    'hosts' => [env('ELASTICSEARCH_HOST', 'http://127.0.0.1:9200')],

    'api_key' => env('ELASTICSEARCH_API_KEY'),

    'indices' => [
        'products' => [
            'settings' => [ /* 2.2 节中的 settings */ ],
            'mappings' => [ /* 2.2 节中的 mappings */ ],
        ],
    ],

    // 同义词文件路径（ES 服务器上的路径，也可以内联）
    'synonym_path' => env('ES_SYNONYM_PATH'),
];
```

`.env` 新增：

```
ELASTICSEARCH_HOST=http://127.0.0.1:9200
ELASTICSEARCH_API_KEY=
```

### 2.9 降级策略

当 ES 不可用时，自动降级到 MySQL 搜索：

```php
class ProductSearchService
{
    public function search(...$params): ProductSearchResult
    {
        try {
            return $this->esSearchRepo->search(...$params);
        } catch (ElasticsearchException $e) {
            Log::warning('ES 搜索失败，降级到 MySQL', ['error' => $e->getMessage()]);
            return $this->fallbackToMysql(...$params);
        }
    }

    private function fallbackToMysql(...$params): ProductSearchResult
    {
        // 使用 MySQL ProductRepository 的 search 方法
        // 功能降级：无分词、无聚合、无建议，但搜索不中断
        $paginator = $this->productRepo->search($params['keyword'], $params['filters'], $params['perPage']);

        return new ProductSearchResult(
            items: $paginator->items(),
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            filters: [], // 降级时无聚合数据
        );
    }
}
```

---

## 3. MongoDB — 灵活文档存储

### 3.1 解决的问题

| 场景 | 为什么用 MongoDB | MySQL 方案的问题 |
|------|----------------|----------------|
| 用户浏览记录 | 每次浏览商品就写一条，写入频率极高 | 单表增长极快，影响主库性能 |
| 搜索关键词日志 | 需要聚合统计热搜词 | MySQL 大表 COUNT + GROUP BY 慢 |
| 操作审计日志 | detail 字段 schema 不固定 | MySQL JSON 列查询不便 |
| 浏览推荐 | 需要按用户聚合最近浏览 | 需要额外的推荐表 |
| 自动过期 | 浏览记录 90 天自动清理 | 需要定时任务手动删除 |

### 3.2 Collections 设计

```
MongoDB Database: shop

Collections:
├── product_views          用户商品浏览记录
├── search_logs            搜索关键词日志
└── operation_logs         操作审计日志
```

### 3.3 各 Collection 详细设计

#### 3.3.1 product_views — 用户浏览记录

**文档结构：**

```json
{
    "_id": ObjectId("..."),
    "user_id": 10001,
    "product_id": 100,
    "product_title": "iPhone 15 Pro",
    "product_image": "https://...",
    "category_id": 5,
    "category_name": "手机",
    "platform": "app",
    "client_ip": "223.x.x.x",
    "viewed_at": ISODate("2026-03-25T10:30:00Z"),
    "created_at": ISODate("2026-03-25T10:30:00Z")
}
```

**索引：**

```javascript
// 按用户查最近浏览（倒序取前N）
db.product_views.createIndex({ "user_id": 1, "viewed_at": -1 });

// 按商品统计浏览量
db.product_views.createIndex({ "product_id": 1, "viewed_at": -1 });

// TTL 索引：90 天自动删除
db.product_views.createIndex({ "created_at": 1 }, { expireAfterSeconds: 7776000 });

// 用户+商品去重查询（最近浏览不重复展示）
db.product_views.createIndex({ "user_id": 1, "product_id": 1, "viewed_at": -1 });
```

**写入逻辑：**

```php
// ProductViewService::record()
public function record(int $userId, int $productId, string $platform): void
{
    // 异步写入，不阻塞商品详情接口
    RecordProductView::dispatch($userId, $productId, $platform)->onQueue('mongo_writes');
}

// RecordProductView 队列任务
public function handle(): void
{
    $product = Product::select('id', 'title', 'main_image', 'category_id')
        ->with('category:id,name')
        ->find($this->productId);

    if (!$product) return;

    DB::connection('mongodb')->collection('product_views')->insert([
        'user_id'       => $this->userId,
        'product_id'    => $product->id,
        'product_title' => $product->title,
        'product_image' => $product->main_image,
        'category_id'   => $product->category_id,
        'category_name' => $product->category?->name,
        'platform'      => $this->platform,
        'client_ip'     => request()->ip(),
        'viewed_at'     => now(),
        'created_at'    => now(),
    ]);
}
```

**查询 — 用户最近浏览（去重，取最新）：**

```javascript
db.product_views.aggregate([
    { "$match": { "user_id": 10001 } },
    { "$sort": { "viewed_at": -1 } },
    { "$group": {
        "_id": "$product_id",
        "product_title": { "$first": "$product_title" },
        "product_image": { "$first": "$product_image" },
        "category_name": { "$first": "$category_name" },
        "last_viewed_at": { "$first": "$viewed_at" }
    }},
    { "$sort": { "last_viewed_at": -1 } },
    { "$limit": 20 }
]);
```

**API 接口：**

```
GET /api/v1/user/browse-history?page=1&per_page=20

响应：
{
    "code": 0,
    "data": {
        "items": [
            {
                "product_id": 100,
                "product_title": "iPhone 15 Pro",
                "product_image": "https://...",
                "category_name": "手机",
                "last_viewed_at": "2026-03-25 10:30:00"
            }
        ],
        "has_more": true
    }
}
```

#### 3.3.2 search_logs — 搜索关键词日志

**文档结构：**

```json
{
    "_id": ObjectId("..."),
    "keyword": "苹果手机",
    "user_id": 10001,
    "result_count": 120,
    "platform": "app",
    "client_ip": "223.x.x.x",
    "filters": {
        "category_id": 5,
        "price_min": 3000
    },
    "searched_at": ISODate("2026-03-25T10:30:00Z"),
    "created_at": ISODate("2026-03-25T10:30:00Z")
}
```

**索引：**

```javascript
// 热搜词聚合（按关键词 + 时间范围）
db.search_logs.createIndex({ "keyword": 1, "searched_at": -1 });

// 用户搜索历史
db.search_logs.createIndex({ "user_id": 1, "searched_at": -1 });

// TTL 索引：180 天自动删除
db.search_logs.createIndex({ "created_at": 1 }, { expireAfterSeconds: 15552000 });
```

**聚合 — 热搜词排行（最近 24 小时）：**

```javascript
db.search_logs.aggregate([
    {
        "$match": {
            "searched_at": { "$gte": ISODate("2026-03-24T10:30:00Z") }
        }
    },
    {
        "$group": {
            "_id": "$keyword",
            "count": { "$sum": 1 },
            "unique_users": { "$addToSet": "$user_id" }
        }
    },
    {
        "$project": {
            "keyword": "$_id",
            "search_count": "$count",
            "user_count": { "$size": "$unique_users" }
        }
    },
    { "$sort": { "search_count": -1 } },
    { "$limit": 10 }
]);
```

**API 接口：**

```
GET /api/v1/products/hot-searches

响应：
{
    "code": 0,
    "data": [
        { "keyword": "苹果手机", "count": 1500 },
        { "keyword": "耳机", "count": 1200 },
        { "keyword": "充电宝", "count": 980 }
    ]
}
```

**用户搜索历史：**

```
GET /api/v1/user/search-history?limit=10

响应：
{
    "code": 0,
    "data": ["苹果手机", "耳机", "充电宝"]
}
```

#### 3.3.3 operation_logs — 操作审计日志

**文档结构：**

```json
{
    "_id": ObjectId("..."),
    "user_id": 10001,
    "action": "order.create",
    "resource_type": "order",
    "resource_id": "202603251030001001",
    "detail": {
        "order_no": "202603251030001001",
        "total_amount": 8999.00,
        "items_count": 2,
        "coupon_used": true,
        "coupon_id": 5,
        "address_id": 10
    },
    "before": null,
    "after": {
        "status": "pending",
        "pay_amount": 8499.00
    },
    "platform": "app",
    "client_ip": "223.x.x.x",
    "user_agent": "Mozilla/5.0...",
    "performed_at": ISODate("2026-03-25T10:30:00Z"),
    "created_at": ISODate("2026-03-25T10:30:00Z")
}
```

**detail 字段是灵活的** — 不同 action 记录不同结构的细节：

| action | detail 内容 |
|--------|-----------|
| `order.create` | order_no, total_amount, items_count, coupon_used |
| `order.pay` | order_no, gateway, pay_scene, amount |
| `order.cancel` | order_no, cancel_reason |
| `user.phone_changed` | old_phone (脱敏), new_phone (脱敏) |
| `user.password_changed` | 仅记录事件，detail 为空 |
| `cart.checkout` | cart_item_ids, sku_count |
| `after_sale.create` | after_sale_no, type, refund_amount |

**索引：**

```javascript
// 按用户查操作记录
db.operation_logs.createIndex({ "user_id": 1, "performed_at": -1 });

// 按操作类型查
db.operation_logs.createIndex({ "action": 1, "performed_at": -1 });

// 按资源查（查某个订单的所有操作）
db.operation_logs.createIndex({ "resource_type": 1, "resource_id": 1, "performed_at": -1 });

// TTL 索引：365 天自动删除
db.operation_logs.createIndex({ "created_at": 1 }, { expireAfterSeconds: 31536000 });
```

**写入方式 — 事件驱动：**

```php
// 通用的操作日志记录服务
class OperationLogService
{
    public function log(
        int $userId,
        string $action,
        string $resourceType,
        string $resourceId,
        array $detail = [],
        ?array $before = null,
        ?array $after = null,
    ): void {
        // 异步写入，不影响业务性能
        RecordOperationLog::dispatch(
            $userId, $action, $resourceType, $resourceId,
            $detail, $before, $after,
            request()->header('X-Platform', 'unknown'),
            request()->ip(),
            request()->userAgent(),
        )->onQueue('mongo_writes');
    }
}

// 在 Service 中调用
class OrderService
{
    public function createOrder(...): Order
    {
        $order = DB::transaction(function () { /* ... */ });

        // 记录操作日志到 MongoDB
        $this->operationLog->log(
            userId: $order->user_id,
            action: 'order.create',
            resourceType: 'order',
            resourceId: $order->order_no,
            detail: [
                'total_amount' => $order->total_amount,
                'items_count'  => $order->items->count(),
                'coupon_used'  => $order->coupon_id !== null,
            ],
            after: ['status' => $order->status->value, 'pay_amount' => $order->pay_amount],
        );

        return $order;
    }
}
```

### 3.4 代码架构集成

#### 3.4.1 目录结构

```
app/
├── Repositories/
│   ├── Contracts/
│   │   ├── ProductViewRepositoryInterface
│   │   ├── SearchLogRepositoryInterface
│   │   └── OperationLogRepositoryInterface
│   ├── Mongo/                              MongoDB 实现
│   │   ├── MongoProductViewRepository
│   │   ├── MongoSearchLogRepository
│   │   └── MongoOperationLogRepository
│   └── RepositoryServiceProvider           新增 MongoDB 绑定
│
├── Services/
│   ├── Product/
│   │   └── ProductViewService              浏览记录服务
│   ├── Search/
│   │   └── SearchLogService                搜索日志服务
│   └── Audit/
│       └── OperationLogService             操作审计服务
│
├── Jobs/
│   ├── RecordProductView                   异步写入浏览记录
│   ├── LogSearchKeyword                    异步写入搜索日志
│   └── RecordOperationLog                  异步写入审计日志
│
├── Models/Mongo/                           MongoDB 模型（可选）
│   ├── ProductView
│   ├── SearchLog
│   └── OperationLog
│
└── Console/Commands/
    └── MongoCreateIndexesCommand           创建 MongoDB 索引命令
```

#### 3.4.2 MongoDB 连接配置

```php
// config/database.php connections 新增
'mongodb' => [
    'driver'   => 'mongodb',
    'host'     => env('MONGO_HOST', '127.0.0.1'),
    'port'     => env('MONGO_PORT', 27017),
    'database' => env('MONGO_DATABASE', 'shop'),
    'username' => env('MONGO_USERNAME', ''),
    'password' => env('MONGO_PASSWORD', ''),
    'options'  => [
        'authSource' => 'admin',
    ],
],
```

`.env` 新增：

```
MONGO_HOST=127.0.0.1
MONGO_PORT=27017
MONGO_DATABASE=shop
MONGO_USERNAME=
MONGO_PASSWORD=
```

#### 3.4.3 MongoDB Model 示例

```php
// app/Models/Mongo/ProductView.php
use MongoDB\Laravel\Eloquent\Model;

class ProductView extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'product_views';

    protected $fillable = [
        'user_id', 'product_id', 'product_title', 'product_image',
        'category_id', 'category_name', 'platform', 'client_ip',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at'  => 'datetime',
        'created_at' => 'datetime',
    ];
}
```

#### 3.4.4 Repository 示例

```php
// app/Repositories/Mongo/MongoProductViewRepository.php
class MongoProductViewRepository implements ProductViewRepositoryInterface
{
    public function record(array $data): void
    {
        ProductView::create($data);
    }

    /**
     * 用户最近浏览（去重，按最后浏览时间倒序）
     */
    public function getRecentByUser(int $userId, int $limit = 20): array
    {
        return ProductView::raw(function ($collection) use ($userId, $limit) {
            return $collection->aggregate([
                ['$match' => ['user_id' => $userId]],
                ['$sort' => ['viewed_at' => -1]],
                ['$group' => [
                    '_id'            => '$product_id',
                    'product_title'  => ['$first' => '$product_title'],
                    'product_image'  => ['$first' => '$product_image'],
                    'category_name'  => ['$first' => '$category_name'],
                    'last_viewed_at' => ['$first' => '$viewed_at'],
                ]],
                ['$sort' => ['last_viewed_at' => -1]],
                ['$limit' => $limit],
            ]);
        })->toArray();
    }

    /**
     * 商品浏览量统计（指定时间范围）
     */
    public function getViewCountByProduct(int $productId, ?Carbon $since = null): int
    {
        $query = ProductView::where('product_id', $productId);
        if ($since) {
            $query->where('viewed_at', '>=', $since);
        }
        return $query->count();
    }

    /**
     * 清空用户浏览记录
     */
    public function clearByUser(int $userId): void
    {
        ProductView::where('user_id', $userId)->delete();
    }
}
```

### 3.5 MongoDB 与 MySQL 的关系

MongoDB 中存储的数据是 **辅助性** 的，不影响核心业务。

```
┌─────────────────────────────────────────────────────────────┐
│                    数据关系示意                                │
│                                                             │
│  MySQL (权威数据源)                                          │
│  ├── products 表 ─────→ 商品详情页、购物车、订单              │
│  ├── security_logs 表 ─→ 认证相关安全日志（保留原设计）       │
│  └── ...                                                    │
│                    ↕ 数据流                                  │
│  MongoDB (辅助存储)                                          │
│  ├── product_views ───→ "最近浏览" 功能                      │
│  │     数据丢失影响：浏览记录清空，不影响任何业务逻辑          │
│  │                                                          │
│  ├── search_logs ─────→ "热搜词" + "搜索历史" 功能           │
│  │     数据丢失影响：热搜榜重新积累，搜索历史清空              │
│  │                                                          │
│  └── operation_logs ──→ 管理后台审计查询                      │
│        数据丢失影响：审计记录丢失（但 MySQL 核心数据不受影响）  │
│                                                             │
│  原则：MongoDB 数据全部丢失，系统核心功能不受任何影响          │
└─────────────────────────────────────────────────────────────┘
```

> `security_logs` 表保留在 MySQL 中（认证相关的安全日志需要与用户 Token 在同一事务中操作）。`operation_logs` 是更广泛的业务操作审计，写入 MongoDB。

---

## 4. 完整架构整合

### 4.1 更新后的 Repository 层全景

```
Repositories/
├── Contracts/                      接口定义
│   ├── UserRepositoryInterface
│   ├── ProductRepositoryInterface
│   ├── ProductSearchRepositoryInterface   ← 新增（ES 搜索）
│   ├── ProductViewRepositoryInterface     ← 新增（MongoDB 浏览记录）
│   ├── SearchLogRepositoryInterface       ← 新增（MongoDB 搜索日志）
│   ├── OperationLogRepositoryInterface    ← 新增（MongoDB 审计日志）
│   ├── OrderRepositoryInterface
│   ├── CartRepositoryInterface
│   ├── CouponRepositoryInterface
│   └── ...
│
├── Eloquent/                       MySQL 实现
│   ├── BaseRepository
│   ├── UserRepository
│   ├── ProductRepository           商品 CRUD（MySQL）
│   ├── OrderRepository
│   └── ...
│
├── Cache/                          Redis 缓存装饰器
│   ├── CachingProductRepository
│   ├── CachingCategoryRepository
│   ├── CachingProductSkuRepository
│   └── CachingUserRepository
│
├── Elastic/                        Elasticsearch 实现 ← 新增
│   └── ElasticProductSearchRepository   商品搜索（ES）
│
├── Mongo/                          MongoDB 实现 ← 新增
│   ├── MongoProductViewRepository       浏览记录
│   ├── MongoSearchLogRepository         搜索日志
│   └── MongoOperationLogRepository      审计日志
│
└── RepositoryServiceProvider       统一 DI 绑定
```

### 4.2 DI 绑定（RepositoryServiceProvider）

```php
// 新增绑定
public function register(): void
{
    // === MySQL + Redis 缓存（原有） ===
    $this->app->bind(ProductRepositoryInterface::class, function ($app) {
        return new CachingProductRepository(
            eloquent: $app->make(ProductRepository::class),
            cache: $app->make(CacheManager::class),
        );
    });

    // === Elasticsearch ===
    $this->app->bind(ProductSearchRepositoryInterface::class, ElasticProductSearchRepository::class);

    // === MongoDB ===
    $this->app->bind(ProductViewRepositoryInterface::class, MongoProductViewRepository::class);
    $this->app->bind(SearchLogRepositoryInterface::class, MongoSearchLogRepository::class);
    $this->app->bind(OperationLogRepositoryInterface::class, MongoOperationLogRepository::class);

    // === 不需要缓存的 MySQL Repository（原有） ===
    $this->app->bind(OrderRepositoryInterface::class, OrderRepository::class);
    $this->app->bind(CartRepositoryInterface::class, CartRepository::class);
    // ...
}
```

### 4.3 数据流向全景图

```
┌───────────────────────────────────────────────────────────────────────┐
│                          客户端（uniapp / flutter）                     │
└──────────┬──────────┬──────────┬──────────┬──────────┬────────────────┘
           │          │          │          │          │
      商品搜索    商品详情    下单/支付    浏览商品    搜索输入
           │          │          │          │          │
           ▼          ▼          ▼          ▼          ▼
┌───────────────────────────────────────────────────────────────────────┐
│                      Laravel API (Controller → Service)                │
│                                                                       │
│  ProductSearchService ──→ ES 查询                                     │
│  ProductService ────────→ MySQL CRUD (通过 Repository)                │
│  OrderService ──────────→ MySQL 事务 + 操作日志(MongoDB)              │
│  ProductViewService ────→ MongoDB 写入浏览记录                         │
│  SearchLogService ──────→ MongoDB 写入搜索日志                         │
│                                                                       │
│  RepositoryServiceProvider 统一管理所有数据源绑定                       │
└─────────┬──────────┬──────────┬──────────┬──────────┬─────────────────┘
          │          │          │          │          │
          ▼          ▼          ▼          ▼          ▼
┌────────────┐ ┌──────────┐ ┌──────────┐ ┌────────────┐
│   MySQL    │ │  Redis   │ │   ES     │ │  MongoDB   │
│            │ │          │ │          │ │            │
│ users      │ │ 缓存层   │ │ 商品搜索 │ │ 浏览记录   │
│ products   │ │ JWT黑名单│ │ 搜索建议 │ │ 搜索日志   │
│ orders     │ │ 验证码   │ │ 聚合筛选 │ │ 操作审计   │
│ payments   │ │ 频率限制 │ │          │ │            │
│ ...        │ │          │ │          │ │            │
└────────────┘ └──────────┘ └──────────┘ └────────────┘
    权威数据       临时缓存     搜索副本      辅助存储
    ACID事务       高速读取     全文检索      灵活schema
```

### 4.4 更新后的 API 路由

在原有路由基础上新增：

```
#### 搜索相关（公开，无需认证）
GET    /api/v1/products/search          商品搜索（ES 全文检索 + 筛选 + 聚合）
GET    /api/v1/products/suggest         搜索建议 / 自动补全
GET    /api/v1/products/hot-searches    热搜词排行（MongoDB 聚合）

#### 用户相关（需 JWT 认证）
GET    /api/v1/user/browse-history      我的浏览记录（MongoDB 聚合去重）
DELETE /api/v1/user/browse-history      清空浏览记录
GET    /api/v1/user/search-history      我的搜索历史（MongoDB 查询）
DELETE /api/v1/user/search-history      清空搜索历史
```

### 4.5 更新后的错误码

| 错误码 | 说明 |
|--------|------|
| 50004 | Elasticsearch 服务不可用（降级到 MySQL 搜索） |
| 50005 | MongoDB 服务不可用（浏览/搜索记录功能暂不可用） |

### 4.6 更新后的定时任务

| 频率 | 任务 | 说明 |
|------|------|------|
| 每日凌晨 3 点 | `es:sync-products` | 全量同步 MySQL → ES（兜底，确保一致性） |
| 每小时 | `mongo:hot-searches-cache` | 缓存热搜词到 Redis（避免高频聚合） |

> 热搜词不需要实时，每小时聚合一次缓存到 Redis，`GET /products/hot-searches` 从 Redis 读取。

### 4.7 更新后的实现顺序

原有实现顺序保持不变，新增阶段：

```
1. 基础设施 — 统一响应格式、异常处理、中间件
2. 用户认证 — 注册/登录/JWT/设备管理/泄露检测
3. 商品模块 — 分类/SPU/SKU/属性
4. Elasticsearch 集成 — 索引创建/数据同步/商品搜索  ← 依赖商品模块完成
5. 营销模块 — 促销活动/优惠券/责任链优惠计算
6. 购物车 — 加购/合并/选中/失效检测
7. 订单与支付 — 下单/支付网关/回调/状态机
8. MongoDB 集成 — 浏览记录/搜索日志/操作审计       ← 可与 5-7 并行
9. 物流 — 依赖订单
10. 售后 — 依赖订单和支付
```

ES 在商品模块之后（需要商品数据），MongoDB 可以和营销/购物车/订单并行（独立功能）。

---

## 5. 学习要点总结

### 5.1 通过本项目学到的技术点

| 技术 | 学习要点 |
|------|---------|
| **ES Mapping** | text vs keyword，nested 类型，completion 类型 |
| **ES 分析器** | IK 中文分词（smart vs max_word），同义词过滤器 |
| **ES 查询** | bool query，multi_match，nested query，filter vs must |
| **ES 聚合** | terms agg，range agg，nested agg，用于侧边栏筛选 |
| **ES 建议** | completion suggester，搜索自动补全 |
| **ES 数据同步** | 事件驱动增量 + 定时全量兜底 |
| **ES 降级** | 搜索引擎不可用时降级到 MySQL |
| **MongoDB 文档** | 灵活 schema，适合日志/记录类数据 |
| **MongoDB 索引** | 复合索引，TTL 索引自动过期删除 |
| **MongoDB 聚合** | Aggregation Pipeline ($match, $group, $sort, $limit) |
| **MongoDB + Laravel** | laravel-mongodb 包，Eloquent 风格操作 MongoDB |
| **多数据源架构** | MySQL + Redis + ES + MongoDB 各司其职 |
