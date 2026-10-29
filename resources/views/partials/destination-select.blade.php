{{-- $name, $options (grouped), $selected, $empty (optional label for "none") --}}
<select name="{{ $name }}" id="{{ $id ?? str_replace(['[', ']'], ['_', ''], $name) }}">
    @isset($empty)
        <option value="">{{ $empty }}</option>
    @endisset
    @foreach ($options as $group => $items)
        @if (count($items))
            <optgroup label="{{ $group }}">
                @foreach ($items as $value => $label)
                    <option value="{{ $value }}" @selected($selected === $value)>{{ $label }}</option>
                @endforeach
            </optgroup>
        @endif
    @endforeach
</select>
