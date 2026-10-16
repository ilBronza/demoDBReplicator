@extends('dbReplicator::layout')
@section('content')
    <h1>{{ $label }}</h1>
    @if ($success)
        <p>{{ __('dbReplicator::dbReplicator.success') }}</p>
        <p>{{ __('dbReplicator::dbReplicator.backup') }}: <code>{{ $backup }}</code></p>
        <p>{{ __('dbReplicator::dbReplicator.sessionNotice') }}</p>
    @else
        <p class="notice">{{ __('dbReplicator::dbReplicator.failed') }}</p>
    @endif
@endsection
