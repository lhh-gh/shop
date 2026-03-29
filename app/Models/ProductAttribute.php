<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    use HasFactory;

    public $timestamps = false; // 规范中指明此表不使用时间戳

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'name',
        'values',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'values' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * 所属商品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
