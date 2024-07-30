<?php

declare(strict_types=1);
/**
 * This file is part of MineAdmin.
 *
 * @link     https://www.mineadmin.com
 * @document https://doc.mineadmin.com
 * @contact  root@imoi.cn
 * @license  https://github.com/mineadmin/MineAdmin/blob/master/LICENSE
 */

namespace App\Repository\Permission;

use App\Exception\BusinessException;
use App\Http\Common\ResultCode;
use App\Model\Permission\Dept;
use App\Model\Permission\User;
use App\Repository\IRepository;
use Hyperf\Collection\Arr;
use Hyperf\Database\Model\Builder;
use Hyperf\DbConnection\Annotation\Transactional as Transaction;

/**
 * Class UserRepository.
 * @extends IRepository<User>
 */
class UserRepository extends IRepository
{
    public function __construct(
        protected readonly User $model
    ) {}

    /**
     * Check the user by username.
     */
    public function checkUserByUsername(string $username): User
    {
        /**
         * @var null|User $result
         */
        $result = $this->model->newQuery()->where('username', $username)->first();
        if (empty($result)) {
            throw new BusinessException(ResultCode::UNPROCESSABLE_ENTITY);
        }
        return $result;
    }

    /**
     * Check for presence by username.
     */
    public function existsByUsername(string $username): bool
    {
        return $this->model->newQuery()->where('username', $username)->exists();
    }

    /**
     * Check user password.
     */
    public function checkPass(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    #[Transaction]
    public function save(array $data): mixed
    {
        $role_ids = $data['role_ids'] ?? [];
        $post_ids = $data['post_ids'] ?? [];
        $dept_ids = $data['dept_ids'] ?? [];

        $user = $this->model::create($data);
        $user->roles()->sync($role_ids, false);
        $user->posts()->sync($post_ids, false);
        $user->depts()->sync($dept_ids, false);
        return $user->id;
    }

    /**
     * 更新用户.
     */
    #[Transaction]
    public function update(mixed $id, array $data): bool
    {
        $role_ids = $data['role_ids'] ?? [];
        $post_ids = $data['post_ids'] ?? [];
        $dept_ids = $data['dept_ids'] ?? [];
        $result = parent::updateById($id, $data);
        $user = $this->model::find($id);
        if ($user && $result) {
            ! empty($role_ids) && $user->roles()->sync($role_ids);
            ! empty($dept_ids) && $user->depts()->sync($dept_ids);
            $user->posts()->sync($post_ids);
            return true;
        }
        return false;
    }

    #[Transaction]
    public function realDelete(array $ids): bool
    {
        foreach ($ids as $id) {
            $user = $this->model::withTrashed()->find($id);
            if ($user) {
                $user->roles()->detach();
                $user->posts()->detach();
                $user->depts()->detach();
                $user->forceDelete();
            }
        }
        return true;
    }

    public function read(mixed $id, array $column = ['*']): ?User
    {
        return $this->model->newQuery()->with([
            'roles:id,name',
            'posts:id,name',
            'depts:id,name',
        ])->whereKey($id)->select($column)->first();
    }

    public function handleSearch(Builder $query, array $params): Builder
    {
        return $query
            ->when(Arr::get($params, 'dept_id'), function (Builder $query, $deptId) {
                $deptIds = Dept::query()
                    ->where('id', $deptId)
                    ->orWhere('level', 'like', $deptId . ',%')
                    ->orWhere('level', 'like', '%,' . $deptId)
                    ->orWhere('level', 'like', '%,' . $deptId . ',%')
                    ->pluck('id')
                    ->toArray();
                $query->whereRelation('depts', 'id', 'in', $deptIds);
            })
            ->when(Arr::get($params, 'username'), function (Builder $query, $username) {
                $query->where('username', 'like', '%' . $username . '%');
            })
            ->when(Arr::get($params, 'phone'), function (Builder $query, $phone) {
                $query->where('phone', $phone);
            })
            ->when(Arr::get($params, 'email'), function (Builder $query, $email) {
                $query->where('email', $email);
            })
            ->when(Arr::exists($params, 'status'), function (Builder $query) use ($params) {
                $query->where('status', Arr::get($params, 'status'));
            })
            ->when(Arr::exists($params, 'user_type'), function (Builder $query) use ($params) {
                $query->where('user_type', Arr::get($params, 'user_type'));
            })
            ->when(Arr::exists($params, 'nickname'), function (Builder $query) use ($params) {
                $query->where('nickname', 'like', '%' . Arr::get($params, 'nickname') . '%');
            })
            ->when(Arr::exists($params, 'created_at'), function (Builder $query) use ($params) {
                $query->whereBetween('created_at', [
                    Arr::get($params, 'created_at')[0] . ' 00:00:00',
                    Arr::get($params, 'created_at')[1] . ' 23:59:59',
                ]);
            })
            ->when(Arr::get($params, 'user_ids'), function (Builder $query, $userIds) {
                $query->whereIn('id', $userIds);
            })
            ->when(Arr::get($params, 'role_id'), function (Builder $query, $roleId) {
                $query->whereHas('roles', function (Builder $query) use ($roleId) {
                    $query->where('role_id', $roleId);
                });
            })
            ->when(Arr::get($params, 'post_id'), function (Builder $query, $postId) {
                $query->whereHas('posts', function (Builder $query) use ($postId) {
                    $query->where('post_id', $postId);
                });
            });
    }

    /**
     * 初始化用户密码
     */
    public function initUserPassword(int $id, string $password): bool
    {
        return (bool) $this->model::query()
            ->whereKey($id)->first()
            ?->fill(['password' => password_hash($password, PASSWORD_DEFAULT)])
            ->save();
    }

    /**
     * 根据用户ID列表获取用户基础信息.
     */
    public function getUserInfoByIds(array $ids, array $select = ['id', 'username', 'nickname', 'phone', 'email', 'created_at']): array
    {
        return $this->model->newQuery()->whereKey($ids)->select($select)->get()->toArray();
    }
}