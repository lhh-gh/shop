<?php

namespace App\Repositories\Eloquent;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    public function getCategoryTree(): Collection
    {
        return $this->model->newQuery()
            ->where('parent_id', 0)
            ->where('is_enabled', 1)
            ->with(['children' => function ($query) {
                $query->where('is_enabled', 1)->orderBy('sort_order', 'asc');
            }])
            ->orderBy('sort_order', 'asc')
            ->get();
    }
}
