@php
    $assignmentType=$deliveryAssignment?->delivery_type??'delivery_team';
    $assignmentTarget=$deliveryAssignment?->delivery_target??'';
@endphp
<div class="cr-assignment-fields cr-grid" data-refill-assignment>
<label>Assign delivery<select name="assignment_type" required data-refill-assignment-type>@foreach(\App\Services\Cylinders\RefillDeliveryAssignments::TYPES as $key=>$label)<option value="{{ $key }}" @selected($assignmentType===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Delivery person / provider<select name="assignment_target" required data-refill-assignment-target><option value="">Choose person / provider</option>@foreach($assignmentOptions as $option)<option value="{{ $option['value'] }}" data-type="{{ $option['type'] }}" data-login="{{ $option['login']?'1':'0' }}" @selected($assignmentTarget===$option['value']) @disabled($assignmentType!==$option['type']) @if($assignmentType!==$option['type']) hidden @endif>{{ $option['label'] }}</option>@endforeach</select></label>
<div class="cr-grid cr-assignment-fields" data-refill-third-party @if($assignmentTarget!=='manual') hidden @endif>
<label>Third-party person / transporter<input name="third_party_name" maxlength="255" value="{{ $assignmentTarget==='manual'?$deliveryAssignment?->assignment_name:'' }}" @disabled($assignmentTarget!=='manual') @required($assignmentTarget==='manual')></label>
<label>Contact person (optional)<input name="third_party_contact" maxlength="255" value="{{ $assignmentTarget==='manual'?$deliveryAssignment?->assignment_contact:'' }}" @disabled($assignmentTarget!=='manual')></label>
<label>Phone (optional)<input name="third_party_phone" type="tel" maxlength="20" placeholder="+91…" value="{{ $assignmentTarget==='manual'?$deliveryAssignment?->assignment_phone:'' }}" @disabled($assignmentTarget!=='manual')></label>
</div>
<small class="cr-assignment-fields" data-refill-assignment-note>Internal team lists active delivery logins only. External providers can be assigned without creating a login.</small>
</div>
