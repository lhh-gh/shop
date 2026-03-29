<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'category_id',
        'shipping_template_id',
        'title',
        'subtitle',
        'main_image',
        'images',
        'description',
        'base_price',
        'sales_count',
        'review_count',
        'status',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'images' => 'array',
        'base_price' => 'decimal:2',
        'sales_count' => 'integer',
        'review_count' => 'integer',
        'status' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * 所属分类
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * 拥有的 SKU
     */
    public function skus(): HasMany
    {
        return $this->hasMany(ProductSku::class, 'product_id');
    }

    /**
     * 拥有的商品属性
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class, 'product_id');
    }
}
