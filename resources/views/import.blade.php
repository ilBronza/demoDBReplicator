@extends('dbReplicator::layout')
@section('content')
    <h1>{{ __('dbReplicator::dbReplicator.importDatabases') }} — {{ $label }}</h1>
    <dl>
        <dt>{{ __('dbReplicator::dbReplicator.source') }}</dt><dd>{{ $sourceHost }}</dd>
        <dt>{{ __('dbReplicator::dbReplicator.destination') }}</dt><dd>{{ $destination }} ({{ $host }})</dd>
    </dl>
    <p class="notice">{{ __('dbReplicator::dbReplicator.replaceNotice') }}</p>
    <p>{{ __('dbReplicator::dbReplicator.sessionNotice') }}</p>
    <form method="post" action="{{ route('dbreplicator.import.run', ['database' => $database]) }}"
        onsubmit="this.querySelector('button').disabled = true; this.querySelector('[role=status]').hidden = false;">
        @csrf
        <input type="hidden" name="confirm" value="{{ $database }}">
        <button type="submit">{{ __('dbReplicator::dbReplicator.confirmImport') }}</button>
        <p role="status" hidden>{{ __('dbReplicator::dbReplicator.importing') }}</p>
    </form>
@endsection
