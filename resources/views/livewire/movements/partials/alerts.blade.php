@if ($notice)
    <div class="alert alert-success" role="status">{{ $notice }}</div>
@endif

@if ($errorMessage)
    <div class="alert alert-danger" role="alert">{{ $errorMessage }}</div>
@endif
