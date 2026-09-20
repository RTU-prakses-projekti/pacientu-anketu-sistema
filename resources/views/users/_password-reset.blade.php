@if(!$user->isBootstrapRoot() || auth()->user()->isBootstrapRoot())
<details>
    <summary>{{ __('messages.reset_password') }}</summary>
    <form method="POST" action="{{ $action }}" class="stack mt-3">
        @csrf
        <label>{{ __('messages.temporary_password') }}<input type="password" name="password" autocomplete="new-password" required></label>
        <label>{{ __('messages.password_confirmation') }}<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
        <button class="btn">{{ __('messages.reset_password') }}</button>
    </form>
</details>
@endif
