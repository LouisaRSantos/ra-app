// Public files: lazy-loaded grid (infinite scroll), click for a larger view. No extracted data is shown.
import { esc, fmtDateTime, fmtSize } from '../lib/ui';

export default function ($) {
    const cfg = window.RA;
    const $grid = $('#grid');
    const $more = $('#more');
    const dlg = $('#dlg-file')[0];
    const state = { page: 1, loading: false };

    function cardHtml(f) {
        return `<figure class="file-card" data-url="${esc(f.url)}" data-name="${esc(f.name)}" tabindex="0">
            <div class="file-thumb"><img src="${esc(f.url)}" alt="${esc(f.name)}" loading="lazy" decoding="async"></div>
            <figcaption>
                <strong>${esc(f.name)}</strong>
                <span>${esc(f.uploader)} · ${esc(fmtDateTime(f.uploaded_at))}${f.size ? ' · ' + esc(fmtSize(f.size)) : ''}</span>
            </figcaption>
        </figure>`;
    }

    function load() {
        if (state.loading) return;
        state.loading = true;

        $.getJSON(cfg.data, { page: state.page }).done((res) => {
            res.data.forEach((f) => $grid.append(cardHtml(f)));
            $('#empty').prop('hidden', $grid.children().length > 0);
            $more.prop('hidden', !res.has_more);
            state.page += 1;
        }).fail(() => {
            $('#empty').prop('hidden', false).find('.empty').text('Could not load files.');
        }).always(() => { state.loading = false; });
    }

    function show($card) {
        $('#v-title').text($card.data('name'));
        $('#v-img').attr({ src: $card.data('url'), alt: $card.data('name') });
        dlg.showModal();
    }

    $grid.on('click', '.file-card', function () { show($(this)); });
    $grid.on('keydown', '.file-card', function (e) { if (e.key === 'Enter') show($(this)); });
    $('[data-close]').on('click', () => dlg.close());
    dlg.addEventListener('close', () => $('#v-img').removeAttr('src'));

    if ('IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && !$more.prop('hidden')) load();
        }, { rootMargin: '300px' }).observe($more[0]);
    }
    $('#btn-more').on('click', load);

    load();
}
