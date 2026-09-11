<?php

namespace App\Services;

use App\Enums\PaymentMethodProcessor;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentMethodService
{
    /**
     * @var array<string, string>
     */
    private const LEGACY_ALIASES = [
        'card' => 'thawani',
        'wallet' => 'thawani',
        'bank_transfer' => 'thawani',
    ];

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function all(): Collection
    {
        return PaymentMethod::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function active(): Collection
    {
        return PaymentMethod::query()->active()->get();
    }

    public function resolveActive(?string $code): PaymentMethod
    {
        $normalized = $this->normalizeCode($code);

        $query = PaymentMethod::query()->active();
        $method = $normalized !== ''
            ? $query->where('code', $normalized)->first()
            : $query->first();

        if (! $method) {
            throw ValidationException::withMessages([
                'payment_method' => ['No active payment method is available.'],
            ]);
        }

        return $method;
    }

    /**
     * @param  array{code: string, name: string, name_ar: string, processor: string, is_active?: bool, sort_order?: int}  $data
     */
    public function create(array $data): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'code' => Str::slug($data['code'], '_'),
            'name' => $data['name'],
            'name_ar' => $data['name_ar'],
            'processor' => PaymentMethodProcessor::from($data['processor']),
            'is_active' => $data['is_active'] ?? true,
            'is_system' => false,
            'sort_order' => $data['sort_order'] ?? ((int) PaymentMethod::query()->max('sort_order') + 1),
        ]);
    }

    /**
     * @param  array{name?: string, name_ar?: string, processor?: string, is_active?: bool, sort_order?: int, code?: string}  $data
     */
    public function update(PaymentMethod $method, array $data): PaymentMethod
    {
        if ($method->is_system) {
            unset($data['code'], $data['processor']);
        }

        if (isset($data['code'])) {
            $data['code'] = Str::slug($data['code'], '_');
        }

        if (isset($data['processor'])) {
            $data['processor'] = PaymentMethodProcessor::from($data['processor']);
        }

        $method->fill($data)->save();

        return $method->fresh();
    }

    public function delete(PaymentMethod $method): void
    {
        if ($method->is_system) {
            throw ValidationException::withMessages([
                'payment_method' => ['System payment methods cannot be deleted.'],
            ]);
        }

        if (Payment::query()->where('method', $method->code)->exists()) {
            throw ValidationException::withMessages([
                'payment_method' => ['This payment method is used by existing payments and cannot be deleted.'],
            ]);
        }

        $method->delete();
    }

    public function normalizeCode(?string $code): string
    {
        $value = strtolower(trim((string) $code));

        return self::LEGACY_ALIASES[$value] ?? $value;
    }
}
