@extends('layouts.app')
@section('content')
<h1>{{ __('messages.dashboard') }}</h1>
<div class="grid-cards">
@foreach($organisations as $organisation)<article class="card"><h2>{{ $organisation->name }}</h2><div class="actions">@if(auth()->user()->hasOrganisationPermission($organisation->id,'forms.view'))<a class="btn" href="{{ route('forms.index',$organisation) }}">{{ __('messages.questionnaires_label') }}</a>@endif @if(auth()->user()->hasOrganisationPermission($organisation->id,'submissions.view'))<a class="btn" href="{{ route('admin.submissions.index',$organisation) }}">{{ __('messages.submissions') }}</a>@endif @if(auth()->user()->hasOrganisationPermission($organisation->id,'exports.create'))<a class="btn" href="{{ route('exports.index',$organisation) }}">{{ __('messages.exports') }}</a>@endif @if(auth()->user()->hasOrganisationPermission($organisation->id,'users.manage'))<a class="btn" href="{{ route('users.index',$organisation) }}">{{ __('messages.users') }}</a>@endif @if(auth()->user()->hasOrganisationPermission($organisation->id,'audit.view'))<a class="btn" href="{{ route('audit.index',$organisation) }}">{{ __('messages.audit') }}</a>@endif</div></article>@endforeach
</div>
@endsection
