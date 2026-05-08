<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeCategory extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(KnowledgeArticle::class)->orderBy('sort_order')->orderBy('title');
    }

    public function scopeVisibleToOrganization(Builder $query, ?int $organizationId): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $visibilityQuery) use ($organizationId) {
                $visibilityQuery->whereNull('organization_id');

                if ($organizationId) {
                    $visibilityQuery->orWhere('organization_id', $organizationId);
                }
            });
    }
}
