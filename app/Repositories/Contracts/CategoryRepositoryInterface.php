<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface CategoryRepositoryInterface
{
    /**
     * 获取分类树（所有顶层及其子分类）
     */
    public function getCategoryTree(): Collection;
}
