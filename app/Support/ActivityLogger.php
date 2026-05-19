<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\PartnerClient;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
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
            $context = self::enrichContext($subject, $context);
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
            $query = self::queryFor($subject)
                ->with('user');

            $user = auth()->user();
            if (!$user?->canAccessModule('payments', 'read')) {
                $query->where('action', 'not like', 'payment.%');
            }

            if (!$user?->canAccessModule('invoices', 'read')) {
                $query->where('action', 'not like', 'invoice.%')
                    ->where('action', 'not like', '%.invoice.%');
            }

            return $query
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

    public static function queryFor(?Model $subject): Builder
    {
        $context = self::subjectContext($subject);
        $organizationId = $context['organization_id'] ?? auth()->user()?->organization_id;

        $query = ActivityLog::query()
            ->when($organizationId, fn (Builder $innerQuery) => $innerQuery->where('organization_id', $organizationId));

        if (!$subject) {
            return $query->whereRaw('1 = 0');
        }

        if ($subject instanceof Customer) {
            $rentalIds = $subject->rentals()->pluck('id');
            $saleIds = $subject->sales()->pluck('id');
            $invoiceIds = $subject->invoices()->pluck('id');

            return $query->where(function (Builder $timelineQuery) use ($subject, $rentalIds, $saleIds, $invoiceIds, $context) {
                $timelineQuery->where('customer_id', $subject->getKey());
                self::matchSubject($timelineQuery, $context, 'or');

                if ($rentalIds->isNotEmpty()) {
                    $timelineQuery->orWhereIn('rental_id', $rentalIds);
                }

                if ($saleIds->isNotEmpty()) {
                    $timelineQuery->orWhereIn('sale_id', $saleIds);
                }

                if ($invoiceIds->isNotEmpty()) {
                    $timelineQuery->orWhereIn('invoice_id', $invoiceIds);
                }
            });
        }

        if ($subject instanceof BusinessPartner) {
            $partnerClientIds = $subject->partnerClients()->pluck('id');
            $rentalIds = $subject->rentals()->pluck('id');
            $saleIds = $subject->sales()->pluck('id');

            return $query->where(function (Builder $timelineQuery) use ($subject, $partnerClientIds, $rentalIds, $saleIds, $context) {
                $timelineQuery->where('business_partner_id', $subject->getKey());
                self::matchSubject($timelineQuery, $context, 'or');

                if ($partnerClientIds->isNotEmpty()) {
                    $timelineQuery->orWhereIn('partner_client_id', $partnerClientIds);
                }

                if ($rentalIds->isNotEmpty()) {
                    $timelineQuery->orWhereIn('rental_id', $rentalIds);
                }

                if ($saleIds->isNotEmpty()) {
                    $timelineQuery->orWhereIn('sale_id', $saleIds);
                }
            });
        }

        if ($subject instanceof PartnerClient) {
            $rentalIds = $subject->rentals()->pluck('id');
            $saleIds = $subject->sales()->pluck('id');

            return $query->where(function (Builder $timelineQuery) use ($subject, $rentalIds, $saleIds, $context) {
                $timelineQuery->where('partner_client_id', $subject->getKey());
                self::matchSubject($timelineQuery, $context, 'or');

                if ($rentalIds->isNotEmpty()) {
                    $timelineQuery->orWhereIn('rental_id', $rentalIds);
                }

                if ($saleIds->isNotEmpty()) {
                    $timelineQuery->orWhereIn('sale_id', $saleIds);
                }
            });
        }

        return $query->where(function (Builder $timelineQuery) use ($context) {
            $matched = false;

            if (!empty($context['subject_type']) && !empty($context['subject_id'])) {
                self::matchSubject($timelineQuery, $context, 'and');
                $matched = true;
            }

            foreach (['rental_id', 'sale_id', 'invoice_id', 'payment_id', 'delivery_id', 'business_partner_id', 'partner_client_id'] as $column) {
                if (!empty($context[$column])) {
                    $timelineQuery->orWhere($column, $context[$column]);
                    $matched = true;
                }
            }

            if (!$matched) {
                $timelineQuery->whereRaw('1 = 0');
            }
        });
    }

    public static function contextFor(?Model $subject): array
    {
        return self::subjectContext($subject);
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
            $context['business_partner_id'] = $subject->business_partner_id;
            $context['partner_client_id'] = $subject->partner_client_id;
        } elseif ($subject instanceof Sale) {
            $context['sale_id'] = $subject->id;
            $context['customer_id'] = $subject->customer_id;
            $context['rental_id'] = $subject->rental_id;
            $context['business_partner_id'] = $subject->business_partner_id;
            $context['partner_client_id'] = $subject->partner_client_id;
        } elseif ($subject instanceof Invoice) {
            $context['invoice_id'] = $subject->id;
            $context['customer_id'] = $subject->customer_id;
            $context['rental_id'] = method_exists($subject, 'linkedRentalId') ? $subject->linkedRentalId() : null;
            $context['sale_id'] = $subject->sale_id;
            $context['business_partner_id'] = $subject->rental?->business_partner_id ?? $subject->sale?->business_partner_id ?? null;
            $context['partner_client_id'] = $subject->rental?->partner_client_id ?? $subject->sale?->partner_client_id ?? null;
        } elseif ($subject instanceof Payment) {
            $context['payment_id'] = $subject->id;
            $context['invoice_id'] = $subject->invoice_id;
            $context['rental_id'] = $subject->rental_id;
            $context['customer_id'] = $subject->customer_id;
            $context['sale_id'] = $subject->invoice?->sale_id;
            $context['business_partner_id'] = $subject->rental?->business_partner_id
                ?? $subject->invoice?->rental?->business_partner_id
                ?? $subject->invoice?->sale?->business_partner_id
                ?? null;
            $context['partner_client_id'] = $subject->rental?->partner_client_id
                ?? $subject->invoice?->rental?->partner_client_id
                ?? $subject->invoice?->sale?->partner_client_id
                ?? null;
        } elseif ($subject instanceof Delivery) {
            $context['delivery_id'] = $subject->id;
            $context['rental_id'] = $subject->rental_id;
            $context['sale_id'] = $subject->sale_id ?? null;
            $context['customer_id'] = $subject->rental?->customer_id ?? $subject->sale?->customer_id ?? null;
            $context['business_partner_id'] = $subject->rental?->business_partner_id ?? $subject->sale?->business_partner_id ?? null;
            $context['partner_client_id'] = $subject->rental?->partner_client_id ?? $subject->sale?->partner_client_id ?? null;
        } elseif ($subject instanceof BusinessPartner) {
            $context['business_partner_id'] = $subject->id;
        } elseif ($subject instanceof PartnerClient) {
            $context['business_partner_id'] = $subject->business_partner_id;
            $context['partner_client_id'] = $subject->id;
        } elseif ($subject instanceof Customer) {
            $context['customer_id'] = $subject->id;
        }

        return $context;
    }

    private static function matchSubject(Builder $query, array $context, string $boolean = 'or'): void
    {
        $method = $boolean === 'and' ? 'where' : 'orWhere';

        $query->{$method}(function (Builder $subjectQuery) use ($context) {
            $subjectQuery
                ->where('subject_type', $context['subject_type'])
                ->where('subject_id', $context['subject_id']);
        });
    }

    private static function enrichContext(?Model $subject, array $context): array
    {
        if (!$subject) {
            return $context;
        }

        if ($subject instanceof Rental || $subject instanceof Sale) {
            $context['customer_type'] = $context['customer_type'] ?? $subject->customerTypeValue();
            $context['reminder_contact_name'] = $context['reminder_contact_name'] ?? $subject->reminderContactName();
            $context['reminder_contact_phone'] = $context['reminder_contact_phone'] ?? $subject->reminderContactPhone();
            $context['delivery_contact_name'] = $context['delivery_contact_name'] ?? $subject->deliveryContactName();
            $context['delivery_contact_phone'] = $context['delivery_contact_phone'] ?? $subject->deliveryContactPhone();
            $context['delivery_contact_address'] = $context['delivery_contact_address'] ?? $subject->deliveryContactAddress();
            $context['delivery_contact_map_url'] = $context['delivery_contact_map_url'] ?? $subject->deliveryContactMapUrl();
        } elseif ($subject instanceof Delivery) {
            $context['task_type'] = $context['task_type'] ?? $subject->type;
            $context['reminder_contact_name'] = $context['reminder_contact_name'] ?? $subject->reminderContactName();
            $context['reminder_contact_phone'] = $context['reminder_contact_phone'] ?? $subject->reminderContactPhone();
            $context['delivery_contact_name'] = $context['delivery_contact_name'] ?? $subject->linkedCustomerName();
            $context['delivery_contact_phone'] = $context['delivery_contact_phone'] ?? $subject->linkedCustomerPhone();
            $context['delivery_contact_address'] = $context['delivery_contact_address'] ?? $subject->linkedCustomerAddress();
            $context['delivery_contact_map_url'] = $context['delivery_contact_map_url'] ?? $subject->linkedCustomerMapUrl();
        } elseif ($subject instanceof BusinessPartner) {
            $context['business_partner_name'] = $context['business_partner_name'] ?? $subject->displayName();
            $context['reminder_contact_name'] = $context['reminder_contact_name'] ?? $subject->displayName();
            $context['reminder_contact_phone'] = $context['reminder_contact_phone'] ?? $subject->preferredReminderNumber();
        } elseif ($subject instanceof PartnerClient) {
            $context['partner_client_name'] = $context['partner_client_name'] ?? $subject->displayName();
            $context['delivery_contact_name'] = $context['delivery_contact_name'] ?? $subject->displayName();
            $context['delivery_contact_phone'] = $context['delivery_contact_phone'] ?? $subject->primaryPhone();
            $context['delivery_contact_address'] = $context['delivery_contact_address'] ?? $subject->address;
            $context['delivery_contact_map_url'] = $context['delivery_contact_map_url'] ?? $subject->openMapUrl();
        } elseif ($subject instanceof Customer) {
            $context['customer_name'] = $context['customer_name'] ?? $subject->displayName();
            $context['reminder_contact_name'] = $context['reminder_contact_name'] ?? $subject->displayName();
            $context['reminder_contact_phone'] = $context['reminder_contact_phone'] ?? $subject->preferredWhatsAppNumber();
            $context['delivery_contact_name'] = $context['delivery_contact_name'] ?? $subject->displayName();
            $context['delivery_contact_phone'] = $context['delivery_contact_phone'] ?? $subject->phone;
            $context['delivery_contact_address'] = $context['delivery_contact_address'] ?? $subject->address;
            $context['delivery_contact_map_url'] = $context['delivery_contact_map_url'] ?? $subject->openMapUrl();
        }

        return $context;
    }
}
