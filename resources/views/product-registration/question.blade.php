@php($answer = old('answers.'.$question->key))
<label for="question-{{ $question->key }}">{{ $question->label }} {{ $question->required ? '*' : __('registration.optional') }}</label>
@if(in_array($question->type, ['select', 'multiselect'], true))
    <select id="question-{{ $question->key }}" name="answers[{{ $question->key }}]{{ $question->type === 'multiselect' ? '[]' : '' }}" data-combobox="search" @if($question->type === 'multiselect') multiple @endif @required($question->required)>
        @if($question->type === 'select')<option value="">{{ __('registration.choose') }}</option>@endif
        @foreach($question->options as $option)<option value="{{ $option }}" @selected(is_array($answer) ? in_array($option, $answer, true) : $answer === $option)>{{ $option }}</option>@endforeach
    </select>
@elseif($question->type === 'textarea')
    <textarea id="question-{{ $question->key }}" name="answers[{{ $question->key }}]" rows="3" minlength="{{ $question->minimum }}" maxlength="{{ $question->maximum }}" @required($question->required)>{{ is_string($answer) ? $answer : '' }}</textarea>
@else
    <input id="question-{{ $question->key }}" name="answers[{{ $question->key }}]" type="{{ $question->validation === 'none' ? 'text' : $question->validation }}" value="{{ is_string($answer) ? $answer : '' }}" minlength="{{ $question->minimum }}" maxlength="{{ $question->maximum }}" @required($question->required)>
@endif
@error('answers.'.$question->key)<p class="registration-error" role="alert">{{ $message }}</p>@enderror
