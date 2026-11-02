<div class="cr-content">
<p class="cr-subtitle">{{ $rental->billingContactName() }} · Rental #{{ $rental->id }}</p>
<div class="cr-errors" data-cylinder-refill-errors role="alert" hidden></div>
@if($message)<p class="cr-success" role="status">{{ $message }}</p>@endif
@php $canBook=auth()->user()->canAccessModule('deliveries','create') && auth()->user()->canAccessModule('rentals','update') && $rental->status==='active'; $stock=auth()->user()->canAccessModule('assets','update') && auth()->user()->scopeType('assets')==='all'; @endphp
@if($canBook && $capacities->isNotEmpty())
<form method="post" action="{{ route('cylinder-refills.store',$rental) }}" data-cylinder-refill-form class="cr-book">@csrf
<input type="hidden" name="request_id" value="{{ (string)Str::uuid() }}">
<div class="cr-grid"><label>Cylinder size / capacity<select name="product_id" required>@foreach($capacities  as $row)<option value="{{ $row['product']->id }}" data-issued="{{ $row['issued'] }}">{{ $row['product']->name }}</option>@endforeach</select><small data-refill-issued>Previously issued: {{ $capacities->first()['issued'] }} cylinder(s)</small></label>
<label>Quantity<input name="quantity" type="number" data-no-auto-select min="1" max="{{ max(1,$capacities->first()['issued']) }}" value="1" required></label>
<label>Refill charges — total (₹, GST included)<input name="refill_charge" type="number" data-no-auto-select min="0" max="999999.99" step="0.01" required></label>
<label>Transportation (₹, GST included)<input name="transport_charge" type="number" data-no-auto-select min="0" max="999999.99" step="0.01" value="0" required></label>
<label>Assign delivery<select name="assigned_user_id" required><option value="">Choose delivery staff</option>@foreach($staff  as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</select></label>
<label>Delivery date &amp; time (IST)<input name="scheduled_at" type="datetime-local" value="{{ now()->format('Y-m-d\TH:i') }}" required></label></div>
<details class="cr-options"><summary>Options · serial number is optional</summary><div class="cr-grid"><label>Refill method<select name="mode"><option value="exchange">Deliver full cylinder and collect the empty</option><option value="same">Collect, refill and return the same cylinder</option></select></label><label>Serial / reference (optional)<input name="serial_reference" maxlength="500"></label>
<label>GST slab<select name="tax_rate">@foreach([0,5,12,18,28] as $rate)<option value="{{ $rate }}" @selected((int)$capacities->first()['product']->defaultGstRate()===$rate)>{{ $rate }}%</option>@endforeach</select></label><label>Notes<textarea name="notes" maxlength="2000"></textarea></label></div></details>
<p class="cr-reminder">Remember: pick up the empty cylinder and record it when received back. Rental dates, rental charges and deposit stay unchanged.</p>
<button type="submit" class="cr-primary">Book refill &amp; assign delivery</button></form>
@elseif($canBook)<p>No cylinder product is currently issued on this rental.</p>@endif
<h3>Refill progress &amp; empty returns</h3>
@forelse($refills  as $refill)<article class="cr-card"><div class="cr-card-head"><strong>#{{ $refill->id }} · {{ $refill->capacity_label }}</strong><span>{{ ucfirst($refill->status) }}</span></div>
<p>{{ $refill->assignee?->name }} · {{ $refill->scheduled_at->format('d M Y, h:i A') }} IST · {{ $refill->quantity }} cylinder(s)</p>
<div class="cr-progress"><span>Refill delivered <b>{{ $refill->delivered_quantity }}/{{ $refill->quantity }}</b></span><span>Empty picked up <b>{{ $refill->collected_quantity }}/{{ $refill->quantity }}</b></span><span>Empty received back <b>{{ $refill->received_quantity }}/{{ $refill->quantity }}</b></span></div>
@if($refill->status==='open' && $refill->pending_empty_quantity)<p class="cr-reminder" role="status">{{ $refill->pending_empty_quantity }} empty cylinder(s) still need to be received back.@if($refill->collected_quantity<$refill->quantity) Ensure delivery staff pick up the empty cylinder.@endif</p>@endif
@if(auth()->user()->canViewDues() || auth()->user()->canViewFinance())<p>Refill ₹{{ number_format($refill->refill_charge,2) }} · Transport ₹{{ number_format($refill->transport_charge,2) }} · Total ₹{{ number_format($refill->refill_charge+$refill->transport_charge,2) }}</p>@endif
@if($refill->serial_reference)<p>Reference: {{ $refill->serial_reference }}</p>@endif
@if($refill->notes)<p>{{ $refill->notes }}</p>@endif
@if(auth()->user()->canAccessModule('deliveries','update') && $refill->status==='open')
<div class="cr-actions">
@if($refill->delivered_quantity<$refill->quantity && ($refill->mode==='exchange'||$refill->refilled_quantity>$refill->delivered_quantity))@include('cylinder-refills.action',['action'=>'deliver','quantity'=>$refill->mode==='same'?$refill->refilled_quantity-$refill->delivered_quantity:$refill->quantity-$refill->delivered_quantity,'label'=>'Mark refill delivered'])@endif
@if($refill->collected_quantity<$refill->quantity)@include('cylinder-refills.action',['action'=>'collect_empty','quantity'=>$refill->quantity-$refill->collected_quantity,'label'=>'Mark empty picked up'])@endif
@if($stock && $refill->received_quantity<$refill->collected_quantity)@include('cylinder-refills.action',['action'=>'receive_empty','quantity'=>$refill->collected_quantity-$refill->received_quantity,'label'=>'Record empty received back'])@endif
</div>@endif
@if($stock && $refill->status!=='cancelled' && $refill->received_quantity>$refill->refilled_quantity)<details class="cr-options"><summary>{{ $refill->mode==='same'?'Record refill ready for return':'Stock: record cylinders received full after refilling' }}</summary>@include('cylinder-refills.action',['action'=>'receive_refilled','quantity'=>$refill->received_quantity-$refill->refilled_quantity,'label'=>'Record full cylinders received'])</details>@endif
@if($refill->status==='open' && $canBook)<details class="cr-options"><summary>Change assignment / cancel</summary><form action="{{ route('cylinder-refills.change',$refill) }}" method="post" data-cylinder-refill-form>@csrf<input type="hidden" name="request_id" value="{{ (string)Str::uuid() }}"><input type="hidden" name="lock_version" value="{{ $refill->lock_version }}"><input type="hidden" name="action" value="reassign"><label>Delivery staff<select name="assigned_user_id">@foreach($staff  as $person)<option value="{{ $person->id }}" @selected($refill->assigned_user_id===$person->id)>{{ $person->name }}</option>@endforeach</select></label><button type="submit">Update assignment</button></form>@if(!$refill->delivered_quantity && !$refill->collected_quantity)@include('cylinder-refills.action',['action'=>'cancel','quantity'=>0,'label'=>'Cancel unstarted refill'])@endif</details>@endif
@if($refill->invoice_id && auth()->user()->canAccessModule('invoices','read'))<a href="{{ route('invoices.show',$refill->invoice_id) }}">Open service invoice</a>@elseif($refill->delivered_quantity===$refill->quantity && $refill->status!=='cancelled' && auth()->user()->canAccessModule('invoices','create'))<form method="post" action="{{ route('cylinder-refills.invoice',$refill) }}" data-cylinder-refill-form>@csrf<button type="submit">Create service invoice</button></form>@endif
<details class="cr-options"><summary>Action history</summary>@foreach($refill->events->sortByDesc('id') as $event)<p><strong>{{ ucwords(str_replace('_',' ',$event->action)) }} · {{ $event->quantity }}</strong><br>{{ $event->created_at->format('d M Y, h:i:s A') }} IST · {{ $event->user?->name }}</p>@endforeach</details>
</article>@empty<p>No refills booked yet.</p>@endforelse
<details class="cr-options"><summary>Earlier cylinder service records</summary><a href="{{ route('cylinder-services.index',['rental_id'=>$rental->id]) }}">Open earlier records</a></details>
</div>
