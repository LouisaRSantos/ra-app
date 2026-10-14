@extends('layouts.app')

@section('title', 'Upload Remittance Advice')

@section('content')
    <h1 class="text-xl font-bold mb-4">Upload Remittance Advice</h1>

    <form id="upload-form" class="mb-8 flex items-center gap-3">
        @csrf
        <input type="file" name="images" id="image-input" accept=".jpg,.jpeg,.png" multiple
               class="block text-sm border border-gray-300 rounded px-3 py-2">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Upload</button>
    </form>
    <p class="text-xs text-gray-500 -mt-6 mb-8">
        Select up to 5 images for the same payment (e.g. multiple voucher pages, or a separate
        BIR Form 2307 showing the CWT) — they'll be combined into a single extraction.
    </p>

    <table class="w-full text-sm border-collapse">
        <thead>
            <tr class="text-left border-b">
                <th class="py-2">Files</th>
                <th class="py-2">Status</th>
                <th class="py-2"></th>
            </tr>
        </thead>
        <tbody id="upload-list"></tbody>
    </table>
@endsection

@push('scripts')
<script type="module">
$(function () {
    const csrfToken = $('input[name="_token"]').val();
    const MAX_FILES = 5;
    const pollers = {};

    function rowHtml(filenames) {
        return `<tr class="border-b">
            <td class="py-2">${filenames.join(', ')}</td>
            <td class="py-2"><span class="status">pending</span></td>
            <td class="py-2"><a class="review-link text-blue-600 hidden" href="#">Review</a></td>
        </tr>`;
    }

    // $row is a direct element reference captured at creation time, not re-selected by a
    // generated DOM id later — a prior version round-tripped through `#row-<id>` selectors,
    // which silently broke because a `.` in a Math.random()-based id is parsed by CSS as a
    // class-selector separator (`#foo.bar` = id "foo" AND class "bar"), matching nothing.
    function poll(uuid, $row) {
        pollers[uuid] = window.pollStatus(uuid, function (data) {
            $row.find('.status').text(data.status);

            if (data.status === 'completed') {
                $row.find('.review-link').removeClass('hidden');
            } else if (data.status === 'failed') {
                $row.find('.status').text('failed: ' + (data.error_message || 'unknown error'));
            }
        });
    }

    $('#upload-form').on('submit', function (e) {
        e.preventDefault();

        const files = Array.from($('#image-input')[0].files);
        if (!files.length) {
            return;
        }

        if (files.length > MAX_FILES) {
            alert(`Please select at most ${MAX_FILES} images per upload.`);
            return;
        }

        const formData = new FormData();
        files.forEach((file) => formData.append('images[]', file));
        formData.append('_token', csrfToken);

        const $row = $(rowHtml(files.map((f) => f.name))).appendTo('#upload-list');

        $.ajax({
            url: '{{ $storeUrl ?? '/extractions' }}',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (data) {
                $row.find('.review-link').attr('href', '/extractions/' + data.uuid);
                poll(data.uuid, $row);
            },
            error: function (xhr) {
                const message = xhr.responseJSON?.message || 'upload failed';
                $row.find('.status').text(message);
            },
        });

        $('#image-input').val('');
    });
});
</script>
@endpush
