<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeArticle extends Model
{
    protected $fillable = [
        'organization_id',
        'knowledge_category_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'type',
        'status',
        'sort_order',
        'is_featured',
        'published_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'knowledge_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeVisibleToOrganization(Builder $query, ?int $organizationId): Builder
    {
        return $query
            ->where('status', 'published')
            ->where(function (Builder $visibilityQuery) use ($organizationId) {
                $visibilityQuery->whereNull('organization_id');

                if ($organizationId) {
                    $visibilityQuery->orWhere('organization_id', $organizationId);
                }
            });
    }
}
