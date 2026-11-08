@if(!$isEdit && ($collectionAssignees ?? collect())->isNotEmpty())
    <div class="{{ $fieldClass }}">
        <label for="collection_mode">Collection type</label>
        <select id="collection_mode" name="collection_mode">
            <option value="">Select collection type</option>
            @foreach(\App\Support\FollowUpManager::COLLECTION_MODES as $mode => $label)
                <option value="{{ $mode }}" @selected(old('collection_mode') === $mode)>{{ $label }}</option>
            @endforeach
        </select>
        @error('collection_mode')<span class="{{ $errorClass }}">{{ $message }}</span>@enderror
    </div>
    <div class="{{ $fieldClass }}">
        <label for="collection_assignee_id">Assign collection to Sales</label>
        <select id="collection_assignee_id" name="collection_assignee_id">
            <option value="">Select Sales team member</option>
            @foreach($collectionAssignees as $assignee)
                <option value="{{ $assignee->id }}" @selected((int) old('collection_assignee_id') === (int) $assignee->id)>{{ $assignee->name }}</option>
            @endforeach
        </select>
        @error('collection_assignee_id')<span class="{{ $errorClass }}">{{ $message }}</span>@enderror
    </div>
@endif
