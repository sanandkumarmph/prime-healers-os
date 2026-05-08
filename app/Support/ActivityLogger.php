<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ActivityLogger
{
    private static ?bool $hasTable = null;

    public static function log(string $action, ?Model $subject = null, array $context = [], ?string $description = null): void
    {
        if (!self::hasTable()) {
            return;
        }

        try {
            $user = auth()->user();
            $request = request();
            $subjectContext = self::subjectContext($subject);
            $organizationId = $context['organization_id']
                ?? $subjectContext['organization_id']
                ?? $user?->organization_id;

            ActivityLog::create(array_merge($subjectContext, [
                'organization_id' => $organizationId,
                'user_id' => $user?->id,
                'action' => $action,
                'description' => $description,
                'properties' => $context,
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255),
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function recentFor(?Model $subject, int $limit = 8): Collection
    {
        if (!$subject || !self::hasTable()) {
            return collect();
        }

        try {
            $context = self::subjectContext($subject);
            $organizationId = $context['organization_id'] ?? auth()->user()?->organization_id;

            return ActivityLog::query()
                ->with('user')
                ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
                ->where(function ($query) use ($context) {
                    $matched = false;

                    if (!empty($context['subject_type']) && !empty($context['subject_id'])) {
                        $query->orWhere(function ($subjectQuery) use ($context) {
                            $subjectQuery
                                ->where('subject_type', $context['subject_type'])
                                ->where('subject_id', $context['subject_id']);
                        });
                        $matched = true;
                    }

                    foreach (['rental_id', 'sale_id', 'invoice_id', 'payment_id', 'delivery_id'] as $column) {
                        if (!empty($context[$column])) {
                            $query->orWhere($column, $context[$column]);
                            $matched = true;
                        }
                    }

                    if (!$matched) {
                        $query->whereRaw('1 = 0');
                    }
                })
                ->latest()
                ->limit($limit)
                ->get();
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    private static function hasTable(): bool
    {
        return self::$hasTable ??= Schema::hasTable('activity_logs');
    }

    private static function subjectContext(?Model $subject): array
    {
        if (!$subject) {
            return [];
        }

        $context = [
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'organization_id' => $subject->organization_id ?? null,
        ];

        if ($subject instanceof Rental) {
            $context['rental_id'] = $subject->id;
            $context['customer_id'] = $subject->customer_id;
        } elseif ($subject instanceof Sale) {
            $context['sale_id'] = $subject->id;
            $context['customer_id'] = $subject->customer_id;
            $context['rental_id'] = $subject->rental_id;
        } elseif ($subject instanceof Invoice) {
            $context['invoice_id'] = $subject->id;
            $context['customer_id'] = $subject->customer_id;
            $context['rental_id'] = method_exists($subject, 'linkedRentalId') ? $subject->linkedRentalId() : null;
        } elseif ($subject instanceof Payment) {
            $context['payment_id'] = $subject->id;
            $context['invoice_id'] = $subject->invoice_id;
            $context['rental_id'] = $subject->rental_id;
            $context['customer_id'] = $subject->customer_id;
        } elseif ($subject instanceof Delivery) {
            $context['delivery_id'] = $subject->id;
            $context['rental_id'] = $subject->rental_id;
            $context['sale_id'] = $subject->sale_id ?? null;
        }

        return $context;
    }
}
