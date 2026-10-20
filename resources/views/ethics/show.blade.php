@extends('layouts.app')

@section('title', 'Expediente #' . $case->public_code . ' — Canal Ético')

@section('content')
<div class="card emp-modal-content border shadow-sm mb-4" style="border-radius: 14px; overflow: hidden; max-width: 920px; margin: 0 auto;">
    @include('ethics.partials.modal-detail')
</div>
@endsection
