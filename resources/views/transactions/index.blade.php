@extends('layouts.app')

@section('title', 'Transactions')
@section('page', 'transactions')

@section('content')
    <div class="page-head">
        <div>
            <h1>Transactions</h1>
            <p>Your uploaded collections and what was read from them.</p>
        </div>
        @if ($canUpload)
            <button class="btn btn-primary" id="btn-upload" type="button">Upload documents</button>
        @endif
    </div>

    <div class="panel">
        <div class="toolbar">
            <input class="input" id="f-q" type="search" placeholder="Search ref, SI no., CR no. or payment reference" aria-label="Search">
            <select class="select" id="f-status" aria-label="Status">
                <option value="">Any status</option>
                <option value="processing">Processing</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
            </select>
            <input class="input" id="f-from" type="date" aria-label="Uploaded from" title="Uploaded from">
            <input class="input" id="f-to" type="date" aria-label="Uploaded to" title="Uploaded to">
            @if ($canViewAll)
                <select class="select" id="f-scope" aria-label="Whose">
                    <option value="mine">My uploads</option>
                    <option value="all">All users</option>
                </select>
            @endif
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr id="head">
                        <th class="sortable" data-sort="created_at">Uploaded</th>
                        <th>Document</th>
                        <th class="sortable" data-sort="payment_reference_number">Reference</th>
                        <th class="sortable" data-sort="cr_number">CR no.</th>
                        <th class="sortable" data-sort="deposit_date">Deposit date</th>
                        <th class="sortable num" data-sort="total_amount">Total</th>
                        <th class="sortable" data-sort="status">Status</th>
                        <th></th>
                    </tr>
                </thead>
                {{-- skeleton is part of the HTML, so the table frame paints before any script runs --}}
                <tbody id="rows" aria-busy="true">
                    <tr class="skeleton"><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td></tr>
                    <tr class="skeleton"><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td></tr>
                    <tr class="skeleton"><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td></tr>
                    <tr class="skeleton"><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td></tr>
                    <tr class="skeleton"><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td><td><span></span></td></tr>
                    </tbody>
            </table>
        </div>
        <div class="pager">
            <span id="pg-info" class="hint"></span>
            <span class="pager-controls">
                <label class="hint" for="f-per">Rows</label>
                <select class="select" id="f-per" aria-label="Rows per page">
                    <option>15</option><option>25</option><option>50</option>
                </select>
                <button class="btn btn-sm" id="pg-prev" type="button" disabled>Previous</button>
                <button class="btn btn-sm" id="pg-next" type="button" disabled>Next</button>
            </span>
        </div>
    </div>

    {{-- Upload --}}
    <dialog class="modal" id="dlg-upload">
        <form id="form-upload">
            <div class="modal-head">Upload documents</div>
            <div class="modal-body">
                <div class="field">
                    <label for="u-files">Images (JPG or PNG, up to 5)</label>
                    <input class="input" id="u-files" name="images[]" type="file" accept=".jpg,.jpeg,.png" multiple required>
                    <div class="hint">Pages of the same payment (voucher, collection receipt, BIR 2307) are read together as one transaction.</div>
                </div>
                <div class="field">
                    <label for="u-engine">Reader</label>
                    <select class="select" id="u-engine" name="engine" style="width:100%">
                        <option value="claude">Claude (slower, more careful)</option>
                        <option value="groq">Groq (faster)</option>
                    </select>
                </div>
                <div class="err" data-err="_"></div>
            </div>
            <div class="modal-foot">
                <button class="btn" type="button" data-close>Cancel</button>
                <button class="btn btn-primary" type="submit">Upload</button>
            </div>
        </form>
    </dialog>

    {{-- View / edit --}}
    <dialog class="modal modal-wide" id="dlg-detail">
        <div class="modal-head detail-head">
            <span id="d-title">Transaction</span>
            <span id="d-badge"></span>
        </div>
        <div class="modal-body">
            <div id="d-state" class="banner" hidden></div>
            <div class="detail-grid" id="d-content">
                <div>
                    <div id="d-files" class="pages"></div>
                </div>
                <form id="d-form" class="is-view" autocomplete="off" novalidate>
                    <div class="field-row">
                        <div class="field">
                            <label for="d-ref">Payment reference no.</label>
                            <input class="input" id="d-ref" name="payment_reference_number" maxlength="255">
                        </div>
                        <div class="field">
                            <label for="d-cr">CR no.</label>
                            <input class="input" id="d-cr" name="cr_number" maxlength="255">
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label for="d-total">Total amount</label>
                            <input class="input" id="d-total" name="total_amount" type="number" step="0.01">
                        </div>
                        <div class="field">
                            <label for="d-cwt">Total CWT</label>
                            <input class="input" id="d-cwt" name="total_cwt_amount" type="number" step="0.01">
                        </div>
                    </div>
                    <div class="field">
                        <label for="d-date">Deposit date</label>
                        <input class="input" id="d-date" name="deposit_date" type="date">
                    </div>

                    <table class="table lines">
                        <thead><tr><th>SI no.</th><th>Amount</th><th>CWT</th><th></th></tr></thead>
                        <tbody id="d-lines"></tbody>
                    </table>
                    <button class="link-btn edit-only" id="btn-add-line" type="button">Add invoice line</button>

                    <div class="err" data-err="_"></div>
                    <dl class="meta" id="d-meta"></dl>
                </form>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-danger btn-left" id="btn-delete" type="button" hidden>Delete</button>
            <button class="btn" id="btn-close" type="button">Close</button>
            <button class="btn" id="btn-cancel-edit" type="button" hidden>Cancel</button>
            <button class="btn" id="btn-edit" type="button" hidden>Edit</button>
            <button class="btn btn-primary" id="btn-save" type="button" hidden>Save and confirm</button>
        </div>
    </dialog>

    {{-- Delete confirmation --}}
    <dialog class="modal" id="dlg-delete">
        <div class="modal-head">Delete transaction</div>
        <div class="modal-body">
            <p style="margin:0">This removes the transaction, its extracted data and the uploaded files. It cannot be undone.</p>
            <div class="err" data-err="_"></div>
        </div>
        <div class="modal-foot">
            <button class="btn" type="button" data-close>Keep it</button>
            <button class="btn btn-danger" id="btn-delete-yes" type="button">Delete</button>
        </div>
    </dialog>

    <script>
        window.RA = {
            data: @json(route('transactions.data')),
            store: @json(route('transactions.store')),
            show: @json(route('transactions.show', ['ref' => '__ID__'])),
            update: @json(route('transactions.update', ['ref' => '__ID__'])),
            destroy: @json(route('transactions.destroy', ['ref' => '__ID__'])),
            visibility: @json(route('transactions.visibility', ['ref' => '__ID__', 'position' => '__POS__'])),
        };
    </script>
@endsection
