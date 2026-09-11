<?php

namespace App\Models;

use App\Enums\PaymentMethodProcessor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'name',
    'name_ar',
    'processor',
    'is_active',
    'is_system',
    'sort_order',
])]
class PaymentMethod extends Model
{
    protected function casts(): array
    {
        return [
            'processor' => PaymentMethodProcessor::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function isThawani(): bool
    {
        return $this->processor === PaymentMethodProcessor::Thawani;
    }

    public function isCash(): bool
    {
        return $this->processor === PaymentMethodProcessor::Cash;
    }

    public function localizedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' && filled($this->name_ar) ? $this->name_ar : $this->name;
    }
}
