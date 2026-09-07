@extends('layouts.app')
@section('content')
<div class="page-header"><div><h1>{{ $form->name }}</h1><span class="badge">{{ __('messages.questionnaire_status_'.$form->status) }}</span></div><div class="actions"><a class="btn" href="{{ route('forms.builder',$form) }}">{{ __('messages.builder') }}</a><a class="btn" href="{{ route('forms.preview',$form) }}">{{ __('messages.preview') }}</a><form method="POST" action="{{ route('forms.duplicate',$form) }}">@csrf<button class="btn">{{ __('messages.duplicate') }}</button></form>@if($form->status!=='archived')<form method="POST" action="{{ route('forms.archive',$form) }}">@csrf<button class="btn danger">{{ __('messages.archive') }}</button></form>@endif</div></div>
@php($versions = $form->versions->sortByDesc('version_number')->values())
<section class="card">
    <h2>{{ __('messages.history') }}</h2>
    @if($versions->isNotEmpty())
        @php($version = $versions->first())
        <div class="list-row">
            <span>v{{ $version->version_number }} · {{ __('messages.questionnaire_status_'.$version->status) }} @if($version->published_at)· {{ $version->published_at }}@endif</span>
            <span class="actions">
                <form method="POST" action="{{ route('questionnaires.export-file',[$form,$version]) }}">@csrf<button class="btn">{{ __('messages.export_questionnaire_file') }} · v{{ $version->version_number }}</button></form>
                @if(in_array(app()->environment(),config('questionnaire_packages.write_environments',[]),true))
                    <form method="POST" action="{{ route('questionnaires.export',[$form,$version]) }}">@csrf<button class="btn">{{ __('messages.export_to_git') }} · v{{ $version->version_number }} ({{ __('messages.questionnaire_status_'.$version->status) }})</button></form>
                @endif
                @if($version->status==='draft')
                    <form method="POST" action="{{ route('forms.publish',[$form,$version]) }}">@csrf<button class="btn primary">{{ __('messages.publish') }}</button></form>
                @elseif($version->status==='published'&&!$form->versions->contains('status','draft'))
                    <form method="POST" action="{{ route('forms.new-draft',[$form,$version]) }}">@csrf<button class="btn">{{ __('messages.new_draft') }}</button></form>
                @endif
            </span>
        </div>
        @if($versions->count() > 1)
            <details class="version-history">
                <summary>{{ __('messages.previous_versions', ['count' => $versions->count() - 1]) }}</summary>
                @foreach($versions->skip(1) as $version)
                    <div class="list-row">
                        <span>v{{ $version->version_number }} · {{ __('messages.questionnaire_status_'.$version->status) }} @if($version->published_at)· {{ $version->published_at }}@endif</span>
                        <span class="actions">
                            <form method="POST" action="{{ route('questionnaires.export-file',[$form,$version]) }}">@csrf<button class="btn">{{ __('messages.export_questionnaire_file') }} · v{{ $version->version_number }}</button></form>
                            @if(in_array(app()->environment(),config('questionnaire_packages.write_environments',[]),true))
                                <form method="POST" action="{{ route('questionnaires.export',[$form,$version]) }}">@csrf<button class="btn">{{ __('messages.export_to_git') }} · v{{ $version->version_number }} ({{ __('messages.questionnaire_status_'.$version->status) }})</button></form>
                            @endif
                            @if($version->status==='draft')
                                <form method="POST" action="{{ route('forms.publish',[$form,$version]) }}">@csrf<button class="btn primary">{{ __('messages.publish') }}</button></form>
                            @elseif($version->status==='published'&&!$form->versions->contains('status','draft'))
                                <form method="POST" action="{{ route('forms.new-draft',[$form,$version]) }}">@csrf<button class="btn">{{ __('messages.new_draft') }}</button></form>
                            @endif
                        </span>
                    </div>
                @endforeach
            </details>
        @endif
    @endif
</section>
@php($isPatientQuestionnaire = $form->preset_key === 'patient_questionnaire')
@php($accessMode = old('access_mode', $isPatientQuestionnaire ? 'invitation' : 'authenticated'))
<section class="card mt-6"><h2>{{ __('messages.create_publication') }}</h2><form method="POST" action="{{ route('publications.store',$form) }}" class="form-grid">@csrf<label>{{ __('messages.version') }}<select name="form_version_id">@foreach($form->versions->where('status','published') as $version)<option value="{{ $version->id }}">v{{ $version->version_number }}</option>@endforeach</select></label><label>{{ __('messages.name') }}<input name="name" required></label><label>{{ __('messages.access_mode') }}<select name="access_mode" id="publication-access-mode">@if($isPatientQuestionnaire)<option value="invitation" @selected($accessMode === 'invitation')>{{ __('messages.invitation') }}</option><option value="public" @selected($accessMode === 'public')>{{ __('messages.public_link') }}</option><option value="access_code" @selected($accessMode === 'access_code')>{{ __('messages.access_code') }}</option>@else<option value="public" @selected($accessMode === 'public')>{{ __('messages.public_link') }}</option><option value="access_code" @selected($accessMode === 'access_code')>{{ __('messages.access_code') }}</option><option value="invitation" @selected($accessMode === 'invitation')>{{ __('messages.invitation') }}</option><option value="authenticated" @selected($accessMode === 'authenticated')>{{ __('messages.authenticated') }}</option>@endif</select></label><label id="publication-access-code-field" class="hidden">{{ __('messages.access_code') }}<input name="access_code" minlength="6" maxlength="100"></label><label>{{ __('messages.opens_at') }}<input type="datetime-local" name="opens_at"></label><label>{{ __('messages.closes_at') }}<input type="datetime-local" name="closes_at"></label>@unless($isPatientQuestionnaire)<label>{{ __('messages.attempt_limit') }}<input type="number" name="attempt_limit" value="1" min="1"></label>@endunless<label class="hidden">{{ __('messages.duration_minutes') }}<input type="number" name="duration_minutes" value="30" min="1"></label><label class="hidden">{{ __('messages.result_visibility') }}<select name="result_visibility"><option value="completion">{{ __('messages.completion_only') }}</option><option value="score">{{ __('messages.score') }}</option><option value="none">{{ __('messages.no') }}</option></select></label>@foreach([
    'timer_enabled' => 'timer',
    'correct_answers_visible' => 'correct_answers',
    'anonymous_allowed' => 'anonymous',
    'identified_required' => 'identified',
    'consent_required' => 'consent_required',
    'autosave_enabled' => 'autosave',
    'resume_enabled' => 'resume',
] as $field => $key)
    @if(! $isPatientQuestionnaire || ! in_array($field, ['anonymous_allowed', 'identified_required', 'autosave_enabled', 'resume_enabled'], true))
        <label class="check{{ in_array($field, ['timer_enabled', 'correct_answers_visible'], true) ? ' hidden' : '' }}">
            <input type="checkbox" name="{{ $field }}" value="1" @checked(in_array($field, ['autosave_enabled', 'resume_enabled', 'identified_required'], true))>
            {{ __('messages.'.$key) }}
        </label>
    @endif
@endforeach<label>{{ __('messages.status') }}<select name="status"><option value="active">{{ __('messages.active') }}</option><option value="inactive">{{ __('messages.inactive') }}</option></select></label><button class="btn primary">{{ __('messages.create') }}</button></form></section>
@push('scripts')
<script>
    (() => {
        const mode = document.getElementById('publication-access-mode');
        const accessCode = document.getElementById('publication-access-code-field');
        if (!mode || !accessCode) return;
        const syncAccessCode = () => {
            const enabled = mode.value === 'access_code';
            accessCode.classList.toggle('hidden', !enabled);
            accessCode.querySelector('input').required = enabled;
        };
        mode.addEventListener('change', syncAccessCode);
        syncAccessCode();
    })();
</script>
@endpush
<?php
    $activePublications = $form->publications->where('status', 'active');
    $inactivePublications = $form->publications->where('status', '!=', 'active');
?>
<section class="mt-6">
    <h2>{{ __('messages.publications') }}</h2>
    @foreach($activePublications as $publication)
        <article class="card mb-3">
            <div class="page-header">
                <div>
                    <strong>{{ $publication->name }}</strong>
                    <span class="badge">{{ __('messages.publication_status_'.$publication->status) }}</span>
                    <p><a href="{{ route('publications.show',$publication) }}">{{ route('publications.show',$publication) }}</a></p>
                </div>
                <form method="POST" action="{{ route('publications.toggle',[$form,$publication]) }}">
                    @csrf
                    <button class="btn">{{ __('messages.inactive') }}</button>
                </form>
            </div>
            @if($publication->access_mode === 'invitation')
                <form method="POST" action="{{ route('invitations.store',[$form,$publication]) }}" class="form-grid">
                    @csrf
                    <label>{{ __('messages.ui_reference') }}<input name="recipient_reference"></label>
                    <label>{{ __('messages.ui_max_uses') }}<input type="number" name="max_uses" value="1"></label>
                    <label>{{ __('messages.ui_expires') }}<input type="datetime-local" name="expires_at"></label>
                    <button class="btn">{{ __('messages.invitation') }}</button>
                </form>
            @endif
        </article>
    @endforeach
    @if($inactivePublications->isNotEmpty())
        <details class="publication-history">
            <summary>{{ __('messages.inactive_publications', ['count' => $inactivePublications->count()]) }}</summary>
            @foreach($inactivePublications as $publication)
                <article class="card mb-3">
                    <div class="page-header">
                        <div>
                            <strong>{{ $publication->name }}</strong>
                            <span class="badge">{{ __('messages.publication_status_'.$publication->status) }}</span>
                            <p><a href="{{ route('publications.show',$publication) }}">{{ route('publications.show',$publication) }}</a></p>
                        </div>
                        <form method="POST" action="{{ route('publications.toggle',[$form,$publication]) }}">
                            @csrf
                            <button class="btn">{{ __('messages.active') }}</button>
                        </form>
                    </div>
                    @if($publication->access_mode === 'invitation')
                        <form method="POST" action="{{ route('invitations.store',[$form,$publication]) }}" class="form-grid">
                            @csrf
                            <label>{{ __('messages.ui_reference') }}<input name="recipient_reference"></label>
                            <label>{{ __('messages.ui_max_uses') }}<input type="number" name="max_uses" value="1"></label>
                            <label>{{ __('messages.ui_expires') }}<input type="datetime-local" name="expires_at"></label>
                            <button class="btn">{{ __('messages.invitation') }}</button>
                        </form>
                    @endif
                </article>
            @endforeach
        </details>
    @endif
</section>
@php($activeInvitationGroups = [])
@php($previousInvitationGroups = [])
@php($invitationLinkCount = 0)
@php($previousInvitationCount = 0)
@foreach($form->publications->where('access_mode', 'invitation') as $publication)
    @foreach($publication->invitations as $invitation)
        <?php
            $isExpired = $invitation->expires_at?->isPast() ?? false;
            $isUsed = $invitation->max_uses !== null && $invitation->uses >= $invitation->max_uses;
            $status = 'active';
            if ($invitation->revoked_at) {
                $status = 'revoked';
            } elseif ($isExpired) {
                $status = 'expired';
            } elseif ($isUsed) {
                $status = 'used';
            } elseif ($publication->status !== 'active') {
                $status = 'inactive';
            }
            $link = ['publication' => $publication, 'invitation' => $invitation, 'status' => $status];
            $invitationLinkCount++;
            if ($status === 'active') {
                $activeInvitationGroups[$publication->id][] = $link;
            } else {
                $previousInvitationGroups[$publication->id][] = $link;
                $previousInvitationCount++;
            }
        ?>
    @endforeach
@endforeach
@if($invitationLinkCount > 0)
    <section class="card mt-6">
        <h2>{{ __('messages.invitation_links') }}</h2>
        @foreach($activeInvitationGroups as $links)
            <h3>{{ $links[0]['publication']->name }}</h3>
            @foreach($links as $link)
                @php($invitation = $link['invitation'])
                <div class="page-header">
                    <div>
                        <strong>{{ $invitation->recipient_reference ?: '#'.$invitation->id }}</strong>
                        <span class="badge">{{ __('messages.active') }}</span>
                        <small>{{ $invitation->uses }}/{{ $invitation->max_uses }}</small>
                    </div>
                    <form method="POST" action="{{ route('invitations.revoke', [$form, $link['publication'], $invitation]) }}" onsubmit="return confirm(@js(__('messages.confirm_link_revoke')))" >
                        @csrf @method('DELETE')
                        <button class="btn danger" type="submit">{{ __('messages.deactivate') }}</button>
                    </form>
                </div>
            @endforeach
        @endforeach
        @if(count($previousInvitationGroups) > 0)
            <details class="invitation-history">
                <summary>{{ __('messages.previous_links', ['count' => $previousInvitationCount]) }}</summary>
                @foreach($previousInvitationGroups as $links)
                    <h3>{{ $links[0]['publication']->name }}</h3>
                    @foreach($links as $link)
                        @php($invitation = $link['invitation'])
                        <div class="page-header">
                            <div>
                                <strong>{{ $invitation->recipient_reference ?: '#'.$invitation->id }}</strong>
                                <span class="badge">{{ __('messages.'.$link['status']) }}</span>
                                <small>{{ $invitation->uses }}/{{ $invitation->max_uses }}</small>
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </details>
        @endif
    </section>
@endif
@php($draftVersion=$form->versions->firstWhere('status','draft'))
@if($draftVersion)<section class="card mt-6"><h2>{{ __('messages.add_questionnaire_part_from_git') }}</h2><a class="btn" href="{{ route('questionnaires.parts',[$form,$draftVersion]) }}">{{ __('messages.add_questionnaire_part_from_git') }}</a></section>@endif
@endsection
