@extends('layouts.app')

@section('title', 'Review Extraction')

@section('content')
    @php
        $lowConfidence = $entry['low_confidence_fields'] ?? [];
        $needsReview = fn (string $field) => in_array($field, $lowConfidence, true) ? 'bg-yellow-100 border-yellow-400' : 'border-gray-300';
    @endphp

    <h1 class="text-xl font-bold mb-4">Review Extraction</h1>

    @if ($entry['status'] !== 'completed')
        <div id="processing-banner" class="p-4 bg-blue-50 border border-blue-200 rounded" data-uuid="{{ $uuid }}">
            Status: <span id="processing-status">{{ $entry['status'] }}</span>...
        </div>
    @else
        <div class="grid grid-cols-2 gap-6">
            <div class="space-y-3">
                @foreach ($entry['disk_paths'] as $i => $diskPath)
                    <img src="{{ route('extractions.image', ['uuid' => $uuid, 'index' => $i]) }}"
                         alt="{{ $entry['original_filenames'][$i] ?? 'Remittance advice page '.($i + 1) }}"
                         class="w-full border rounded">
                @endforeach
            </div>
            <div>
                <form id="review-form">
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Payment Reference Number</label>
                        <input type="text" name="payment_reference_number" value="{{ $entry['payment_reference_number'] }}"
                               class="w-full border rounded px-2 py-1 {{ $needsReview('payment_reference_number') }}">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">CR Number</label>
                        <input type="text" name="cr_number" value="{{ $entry['cr_number'] ?? '' }}"
                               class="w-full border rounded px-2 py-1 {{ $needsReview('cr_number') }}">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Total Amount</label>
                        <input type="number" step="0.01" name="total_amount" value="{{ $entry['total_amount'] }}"
                               class="w-full border rounded px-2 py-1 {{ $needsReview('total_amount') }}">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Total CWT Amount</label>
                        <input type="number" step="0.01" name="total_cwt_amount" value="{{ $entry['total_cwt_amount'] ?? '' }}"
                               class="w-full border rounded px-2 py-1 {{ $needsReview('total_cwt_amount') }}">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Deposit Date</label>
                        <input type="date" name="deposit_date" value="{{ $entry['deposit_date'] }}"
                               class="w-full border rounded px-2 py-1 {{ $needsReview('deposit_date') }}">
                    </div>

                    <table class="w-full text-sm mb-4" id="invoices-table">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-1">SI Number</th>
                                <th class="py-1">Amount</th>
                                <th class="py-1">CWT Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entry['invoices'] as $i => $invoice)
                                @php $lineReview = $invoice['low_confidence_fields'] ?? []; @endphp
                                <tr>
                                    <td class="py-1 pr-2">
                                        <input type="text" name="invoices[{{ $i }}][si_number]" value="{{ $invoice['si_number'] }}"
                                               class="w-full border rounded px-2 py-1 {{ in_array('si_number', $lineReview, true) ? 'bg-yellow-100 border-yellow-400' : 'border-gray-300' }}">
                                    </td>
                                    <td class="py-1 pr-2">
                                        <input type="number" step="0.01" name="invoices[{{ $i }}][amount]" value="{{ $invoice['amount'] }}"
                                               class="w-full border rounded px-2 py-1 {{ in_array('amount', $lineReview, true) ? 'bg-yellow-100 border-yellow-400' : 'border-gray-300' }}">
                                    </td>
                                    <td class="py-1">
                                        <input type="number" step="0.01" name="invoices[{{ $i }}][cwt_amount]" value="{{ $invoice['cwt_amount'] }}"
                                               class="w-full border rounded px-2 py-1 {{ in_array('cwt_amount', $lineReview, true) ? 'bg-yellow-100 border-yellow-400' : 'border-gray-300' }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Confirm</button>
                    <span id="confirm-status" class="ml-3 text-sm text-green-700"></span>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script type="module">
$(function () {
    const $banner = $('#processing-banner');

    if ($banner.length) {
        const uuid = $banner.data('uuid');
        const timer = setInterval(function () {
            $.get(`/extractions/${uuid}/status`, function (data) {
                $('#processing-status').text(data.status);
                if (data.status === 'completed' || data.status === 'failed') {
                    clearInterval(timer);
                    window.location.reload();
                }
            });
        }, 2000);
    }

    $('#review-form').on('submit', function (e) {
        e.preventDefault();

        const data = {
            _method: 'PUT',
            payment_reference_number: $('input[name="payment_reference_number"]').val(),
            cr_number: $('input[name="cr_number"]').val(),
            total_amount: $('input[name="total_amount"]').val(),
            total_cwt_amount: $('input[name="total_cwt_amount"]').val(),
            deposit_date: $('input[name="deposit_date"]').val(),
            invoices: [],
        };

        $('#invoices-table tbody tr').each(function () {
            data.invoices.push({
                si_number: $(this).find('input[name*="[si_number]"]').val(),
                amount: $(this).find('input[name*="[amount]"]').val(),
                cwt_amount: $(this).find('input[name*="[cwt_amount]"]').val(),
            });
        });

        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: data,
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            success: function () {
                $('#confirm-status').text('Confirmed.');
            },
            error: function () {
                $('#confirm-status').removeClass('text-green-700').addClass('text-red-700').text('Could not save. Please check the values and try again.');
            },
        });
    });
});
</script>
@endpush
