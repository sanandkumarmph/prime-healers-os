@if(($showSuccess ?? false) && session('success'))<div class="sw-alert sw-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="sw-alert sw-error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
