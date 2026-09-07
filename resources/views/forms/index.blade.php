@extends('layouts.app')
@section('content')
<div class="page-header">
    <div><a href="{{ route('dashboard') }}">{{ __('messages.back') }}</a><h1 class="page-title">{{ __('messages.questionnaires_label') }}</h1><p class="page-context">{{ __('messages.organisation') }}: {{ $organisation->name }}</p></div>
    <div class="actions">
        <a class="btn" href="{{ $showArchived ? route('forms.index', $organisation) : route('forms.index', ['organisation' => $organisation, 'status' => 'archived']) }}">{{ __($showArchived ? 'messages.active_questionnaires' : 'messages.archived_questionnaires') }}</a>
        @can('create', [\App\Models\Form::class, $organisation->id])
            <a class="btn" href="{{ route('questionnaires.index', $organisation) }}">{{ __('messages.import_questionnaire_file') }}</a>
            <a class="btn primary" href="{{ route('forms.create', $organisation) }}">{{ __('messages.new_questionnaire') }}</a>
        @endcan
    </div>
</div>
<div class="grid-cards">
@forelse($forms as $form)
    <article class="card">
        <div class="badge">{{ __('messages.questionnaire_status_'.$form->status) }}</div>
        <h2>{{ $form->name }}</h2>
        <p>{{ __('messages.'.$form->preset_key) }} · {{ $form->versions_count }} {{ __('messages.versions') }} · {{ $form->publications_count }} {{ __('messages.publications') }}</p>
        <div class="actions">
            <a class="btn" href="{{ route('forms.show', $form) }}">{{ __('messages.view') }}</a>
            @can('update', $form)
                <a class="btn" href="{{ route('forms.builder', $form) }}">{{ __('messages.builder') }}</a>
            @endcan
            @if(!$showArchived && $form->status !== 'archived')
                @can('archive', $form)
                    <form method="POST" action="{{ route('forms.archive', $form) }}" onsubmit="return confirm(@js(__('messages.confirm_archive')))">
                        @csrf
                        <button class="btn" type="submit">{{ __('messages.archive') }}</button>
                    </form>
                @endcan
            @endif
            @can('update', $form)
                @if($deleteEligibility[$form->id]['allowed'])
                    <form method="POST" action="{{ route('forms.destroy', $form) }}" onsubmit="return confirm(@js(__('messages.confirm_permanent_delete')))" >
                        @csrf @method('DELETE')
                        <button class="btn danger" type="submit">{{ __('messages.delete_permanently') }}</button>
                    </form>
                @endif
            @endcan
        </div>
        @if(!$deleteEligibility[$form->id]['allowed'])<p><small>{{ $deleteEligibility[$form->id]['reason'] }}</small></p>@endif
    </article>
@empty
    <p>{{ __('messages.no_records') }}</p>
@endforelse
</div>
@endsection
