<form method="post" action="{{ route('cylinder-refills.change',$refill) }}" data-cylinder-refill-form class="cr-action-form">@csrf
<input type="hidden" name="request_id" value="{{ (string)Str::uuid() }}"><input type="hidden" name="lock_version" value="{{ $refill->lock_version }}"><input type="hidden" name="action" value="{{ $action }}">
@if(!empty($taskView))<input type="hidden" name="task_view" value="1">@endif
@if($quantity>0)<label>Quantity<input type="number" data-no-auto-select name="quantity" min="1" max="{{ $quantity }}" value="{{ $quantity }}" required></label>@endif
@if(in_array($action,['receive_empty','receive_refilled']))<label>Warehouse<select name="warehouse_id" required>@foreach($warehouses  as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></label>@endif
<button type="submit">{{ $label }}</button></form>
