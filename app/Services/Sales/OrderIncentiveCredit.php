<?php

namespace App\Services\Sales;

use App\Models\{Enquiry, OrderIncentiveAttribution, Rental, Sale, User};
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class OrderIncentiveCredit
{
    public const OWNER_PERCENT = 80;

    public function salesStaff(User $actor): Collection
    {
        return User::where('organization_id', $actor->organization_id)->with('assignedRole')
            ->where('is_active', true)->orderBy('name')->get()->filter(fn (User $user) => $user->isSales())->keyBy('id');
    }

    public function defaultOwner(Request $request, Collection $salesStaff): ?int
    {
        $owner = (int) $request->attributes->get('conversion_quote')?->enquiry?->owner_id;
        $actor = (int) $request->user()->id;

        return $salesStaff->has($owner) ? $owner : ($salesStaff->has($actor) ? $actor : null);
    }

    public function prepare(Request $request): array
    {
        $actor = $request->user();
        $quote = $request->attributes->get('conversion_quote');
        $salesStaff = $this->salesStaff($actor);
        $data = $request->validate([
            'incentive_owner_id' => ['nullable', 'integer', Rule::in($salesStaff->keys()->all())],
            'incentive_source' => ['nullable', Rule::requiredIf(!$quote && $request->filled('incentive_owner_id')
                && $request->input('customer_type') !== 'business_partner'), Rule::in(array_keys(Enquiry::SOURCES))],
        ]);

        $owner = isset($data['incentive_owner_id']) ? (int) $data['incentive_owner_id'] : $this->defaultOwner($request, $salesStaff);
        $source = $quote ? (string) $quote->enquiry->source : ($data['incentive_source'] ?? '');
        $source = Enquiry::incentiveSource($source) ?? (isset(Enquiry::SOURCES[$source]) ? $source : '');

        return [
            'owner' => $owner,
            'closer' => $actor->isSales() ? (int) $actor->id : null,
            'source' => $source,
            'actor' => (int) $actor->id,
        ];
    }

    public function save(Rental|Sale $order, array $credit): void
    {
        if ($credit['owner'] === null && $credit['source'] === '') {
            return;
        }

        OrderIncentiveAttribution::record((int) $order->organization_id, $order instanceof Sale ? 'sale' : 'rental',
            (int) $order->id, $credit['source'], $credit['owner'], $credit['actor'], $credit['closer']);
    }
}
