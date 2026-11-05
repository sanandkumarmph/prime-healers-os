@if($salespersonOptions->isNotEmpty())
    <div class="{{ $fieldClass }}">
        <label for="{{ $filterId }}">Salesperson</label>
        <select id="{{ $filterId }}" name="salesperson_id" class="{{ $selectClass }}">
            <option value="">All salespeople</option>
            @foreach($salespersonOptions as $id => $name)
                <option value="{{ $id }}" @selected(request()->integer('salesperson_id') === (int) $id)>{{ $name }}</option>
            @endforeach
        </select>
    </div>
    @if($showBookingMode ?? false)
        <div class="{{ $fieldClass }}">
            <label for="{{ $filterId }}_match">Salesperson match</label>
            <select id="{{ $filterId }}_match" name="salesperson_match" class="{{ $selectClass }}">
                <option value="">Owner or closer</option>
                <option value="booked" @selected(request()->query('salesperson_match') === 'booked')>Booked by</option>
            </select>
        </div>
    @endif
@endif
