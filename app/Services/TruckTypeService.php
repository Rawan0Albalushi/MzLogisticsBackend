<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\Truck;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TruckTypeService
{
    /**
     * @var array<string, TruckType>|null
     */
    private ?array $byCode = null;

    /**
     * @return Collection<int, TruckType>
     */
    public function visibleActive(?User $user): Collection
    {
        return TruckType::query()
            ->active()
            ->where(function ($query) use ($user) {
                $query->platform();
                if ($user?->organization_id) {
                    $query->orWhere('organization_id', $user->organization_id);
                }
            })
            ->ordered()
            ->get();
    }

    /**
     * @return Collection<int, TruckType>
     */
    public function manageable(?User $user): Collection
    {
        if ($user?->isPlatform()) {
            return TruckType::query()->platform()->ordered()->get();
        }

        return TruckType::query()
            ->where(function ($query) use ($user) {
                $query->platform();
                if ($user?->organization_id) {
                    $query->orWhere('organization_id', $user->organization_id);
                }
            })
            ->ordered()
            ->get();
    }

    public function isUsable(?User $user, string $code, ?string $currentCode = null): bool
    {
        $type = $this->findByCode($code);
        if (! $type) {
            return false;
        }

        if ($currentCode !== null && $code === $currentCode) {
            return $this->isVisibleTo($user, $type);
        }

        return $type->is_active && $this->isVisibleTo($user, $type);
    }

    public function isVisibleTo(?User $user, TruckType $type): bool
    {
        if ($type->isPlatform()) {
            return true;
        }

        return $user !== null && (int) $user->organization_id === (int) $type->organization_id;
    }

    public function canManage(?User $user, TruckType $type): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isPlatform()) {
            return $type->isPlatform();
        }

        return ! $type->isPlatform()
            && (int) $user->organization_id === (int) $type->organization_id;
    }

    /**
     * @param  array{code: string, name: string, name_ar: string, is_active?: bool, sort_order?: int}  $data
     */
    public function create(User $user, array $data): TruckType
    {
        if (! $user->isPlatform() && ! $user->organization_id) {
            abort(403);
        }

        $type = TruckType::query()->create([
            'organization_id' => $user->isPlatform() ? null : $user->organization_id,
            'code' => Str::slug($data['code'], '_'),
            'name' => $data['name'],
            'name_ar' => $data['name_ar'],
            'is_active' => $data['is_active'] ?? true,
            'is_system' => false,
            'sort_order' => $data['sort_order'] ?? ((int) TruckType::query()->max('sort_order') + 1),
        ]);
        $this->byCode = null;

        return $type;
    }

    /**
     * @param  array{name?: string, name_ar?: string, is_active?: bool, sort_order?: int, code?: string}  $data
     */
    public function update(User $user, TruckType $type, array $data): TruckType
    {
        $this->assertCanManage($user, $type);

        if ($type->is_system) {
            unset($data['code']);
        }

        if (isset($data['code'])) {
            $data['code'] = Str::slug($data['code'], '_');
        }

        $type->fill($data)->save();
        $this->byCode = null;

        return $type->fresh();
    }

    public function delete(User $user, TruckType $type): void
    {
        $this->assertCanManage($user, $type);

        if ($type->is_system) {
            throw ValidationException::withMessages([
                'truck_type' => ['System truck types cannot be deleted.'],
            ]);
        }

        if ($this->isUsed($type->code)) {
            throw ValidationException::withMessages([
                'truck_type' => ['This truck type is used by existing trucks or quotations and cannot be deleted.'],
            ]);
        }

        $type->delete();
        $this->byCode = null;
    }

    public function label(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return $this->findByCode($code)?->localizedName() ?? $code;
    }

    public function findByCode(string $code): ?TruckType
    {
        return $this->byCode()[$code] ?? null;
    }

    public function isUsed(string $code): bool
    {
        return Truck::query()->where('type', $code)->exists()
            || Quotation::query()->where('truck_type', $code)->exists();
    }

    /**
     * @return array<string, TruckType>
     */
    private function byCode(): array
    {
        return $this->byCode ??= TruckType::query()->get()->keyBy('code')->all();
    }

    private function assertCanManage(User $user, TruckType $type): void
    {
        if (! $this->canManage($user, $type)) {
            abort(403);
        }
    }
}
