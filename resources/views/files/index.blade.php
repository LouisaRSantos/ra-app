@extends('layouts.app')

@section('title', 'Public files')
@section('page', 'files')

@section('content')
    <div class="page-head">
        <div>
            <h1>Public files</h1>
            <p>Documents an Account Specialist has shared. Extracted data is not shown here.</p>
        </div>
    </div>

    <div id="grid" class="file-grid"></div>
    <div id="empty" class="panel" hidden><div class="empty">No public files yet.</div></div>
    <div class="more" id="more" hidden><button class="btn btn-sm" id="btn-more" type="button">Load more</button></div>

    <dialog class="modal modal-wide" id="dlg-file">
        <div class="modal-head detail-head"><span id="v-title"></span></div>
        <div class="modal-body" style="text-align:center">
            <img id="v-img" alt="" style="max-width:100%;max-height:70vh">
        </div>
        <div class="modal-foot"><button class="btn" type="button" data-close>Close</button></div>
    </dialog>

    <script>window.RA = { data: @json(route('files.data')) };</script>
@endsection
