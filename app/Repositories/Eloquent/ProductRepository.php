<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    public function __construct(Product $model)
    {
        parent::__construct($model);
    }

    public function getList(array $filters, int $limit = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('status', 1)
            ->with(['category']);

        if (!empty($filters['category_id'])) {
             $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['keyword'])) {
             $query->where('title', 'like', '%' . $filters['keyword'] . '%');
        }

        return $query->orderBy('id', 'desc')->paginate($limit);
    }

    public function getDetail(int $id): Product
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->with(['category', 'skus', 'attributes'])
            ->findOrFail($id);
    }
}
