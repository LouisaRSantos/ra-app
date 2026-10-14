// Transactions: async list with filters + infinite scroll, and one modal for upload / view / edit / delete.
import { esc, money, fmtDate, fmtDateTime, errorText } from '../lib/ui';

const MAX_FILES = 5;
const MAX_BYTES = 10 * 1024 * 1024;
const CACHE_MS = 20000;

export default function ($) {
    const cfg = window.RA;
    const url = (tpl, id, pos = '') => tpl.replace('__ID__', id).replace('__POS__', pos);

    const $rows = $('#rows');
    const $more = $('#more');
    const dlgUpload = $('#dlg-upload')[0];
    const dlgDetail = $('#dlg-detail')[0];
    const dlgDelete = $('#dlg-delete')[0];

    const list = { page: 1, loading: false, token: 0 };
    const watchers = {};          // uuid -> stop-polling function (rows still being read)
    const cache = new Map();      // uuid -> { at, data } so hover/prefetch makes opening instant

    // ------------------------------------------------------------------ helpers

    function badge(status, confirmed) {
        if (status === 'failed') return '<span class="badge badge-bad">Failed</span>';
        if (status === 'completed') {
            return confirmed ? '<span class="badge badge-ok">Confirmed</span>' : '<span class="badge badge-warn">Needs review</span>';
        }
        return `<span class="badge badge-off">${status === 'pending' ? 'Queued' : 'Reading…'}</span>`;
    }

    const inFlight = (s) => s === 'pending' || s === 'processing';

    function rowHtml(r) {
        const extra = r.files > 1 ? ` <span class="hint">+${r.files - 1} more</span>` : '';
        const owner = r.owner ? `<div class="hint">${esc(r.owner)}</div>` : '';

        return `<tr data-uuid="${esc(r.uuid)}" data-owner="${esc(r.owner || '')}">
            <td>${esc(fmtDateTime(r.created_at))}</td>
            <td>${esc(r.document || 'Document')}${extra}${owner}</td>
            <td>${esc(r.payment_reference_number || '')}</td>
            <td>${esc(r.cr_number || '')}</td>
            <td>${esc(fmtDate(r.deposit_date))}</td>
            <td class="num">${esc(money(r.total_amount))}</td>
            <td class="js-status">${badge(r.status, r.confirmed)}</td>
            <td class="actions">
                <button class="link-btn js-view" type="button">View</button>
                ${r.status === 'completed' ? '<button class="link-btn js-edit" type="button">Edit</button>' : ''}
            </td>
        </tr>`;
    }

    /** The list row shape from a full detail payload (after an edit or when a run finishes). */
    function rowFromDetail(d) {
        return {
            uuid: d.uuid,
            status: d.status,
            confirmed: !!d.confirmed_at,
            document: d.files[0]?.name,
            files: d.files.length,
            payment_reference_number: d.fields.payment_reference_number,
            cr_number: d.fields.cr_number,
            deposit_date: d.fields.deposit_date,
            total_amount: d.fields.total_amount,
            owner: $rows.find(`tr[data-uuid="${d.uuid}"]`).attr('data-owner') || null,
            created_at: d.uploaded_at,
        };
    }

    function replaceRow(r) {
        const $old = $rows.find(`tr[data-uuid="${r.uuid}"]`);
        if ($old.length) $old.replaceWith(rowHtml(r));
        if (inFlight(r.status)) watch(r.uuid);
    }

    // Rows still being read update themselves, one sequential poll each.
    function watch(uuid) {
        if (watchers[uuid]) return;

        watchers[uuid] = window.pollStatus(uuid, (s) => {
            $rows.find(`tr[data-uuid="${uuid}"] .js-status`).html(badge(s.status, false));
            if (!inFlight(s.status)) {
                delete watchers[uuid];
                cache.delete(uuid);
                fetchDetail(uuid).then((d) => replaceRow(rowFromDetail(d)));
            }
        });
    }

    function fetchDetail(uuid, force = false) {
        const hit = cache.get(uuid);
        if (!force && hit && Date.now() - hit.at < CACHE_MS) return $.Deferred().resolve(hit.data).promise();

        return $.getJSON(url(cfg.show, uuid)).then((data) => {
            cache.set(uuid, { at: Date.now(), data });
            return data;
        });
    }

    // ------------------------------------------------------------------ list

    function filters() {
        return {
            q: $('#f-q').val().trim(),
            status: $('#f-status').val(),
            from: $('#f-from').val(),
            to: $('#f-to').val(),
            scope: $('#f-scope').val() || 'mine',
        };
    }

    function load(reset) {
        if (list.loading && !reset) return;
        list.loading = true;
        const token = ++list.token;

        if (reset) {
            list.page = 1;
            Object.values(watchers).forEach((stop) => stop());
            Object.keys(watchers).forEach((k) => delete watchers[k]);
            $rows.html(('<tr class="skeleton">' + '<td><span></span></td>'.repeat(8) + '</tr>').repeat(4));
            $more.prop('hidden', true);
        }

        $.getJSON(cfg.data, { ...filters(), page: list.page }).done((res) => {
            if (token !== list.token) return; // a newer filter change superseded this response

            if (reset) $rows.empty();
            res.data.forEach((r) => {
                $rows.append(rowHtml(r));
                if (inFlight(r.status)) watch(r.uuid);
            });

            if (!$rows.children().length) {
                $rows.html('<tr><td colspan="8" class="empty">No transactions found.</td></tr>');
            }
            $more.prop('hidden', !res.has_more);
            list.page += 1;
        }).fail((xhr) => {
            if (token !== list.token) return;
            $rows.html(`<tr><td colspan="8" class="empty">${esc(errorText(xhr, 'Could not load transactions.'))}</td></tr>`);
        }).always(() => {
            if (token === list.token) list.loading = false;
        });
    }

    if ('IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && !$more.prop('hidden')) load(false);
        }, { rootMargin: '240px' }).observe($more[0]);
    }
    $('#btn-more').on('click', () => load(false));

    let timer;
    $('#f-q').on('input', () => { clearTimeout(timer); timer = setTimeout(() => load(true), 250); });
    $('#f-status, #f-from, #f-to, #f-scope').on('change', () => load(true));

    // Warm the cache while the pointer is over a row, so the modal opens with data already there.
    let hoverTimer;
    $rows.on('mouseenter', 'tr[data-uuid]', function () {
        const uuid = $(this).data('uuid');
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => fetchDetail(uuid), 150);
    });
    $rows.on('mouseleave', 'tr', () => clearTimeout(hoverTimer));

    $('[data-close]').on('click', function () { $(this).closest('dialog')[0].close(); });

    // ------------------------------------------------------------------ upload

    $('#btn-upload').on('click', () => {
        $('#form-upload')[0].reset();
        $('#form-upload [data-err]').text('');
        dlgUpload.showModal();
    });

    $('#form-upload').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this);
        const $err = $f.find('[data-err="_"]').text('');
        const files = Array.from($('#u-files')[0].files);

        if (!files.length) return $err.text('Choose at least one image.');
        if (files.length > MAX_FILES) return $err.text(`Choose at most ${MAX_FILES} images.`);
        if (files.some((x) => x.size > MAX_BYTES)) return $err.text('Each image must be 10 MB or smaller.');
        if (files.some((x) => !/\.(jpe?g|png)$/i.test(x.name))) return $err.text('Only JPG or PNG images are accepted.');

        const data = new FormData();
        files.forEach((x) => data.append('images[]', x));
        data.append('engine', $('#u-engine').val());

        const $btn = $f.find('[type=submit]').prop('disabled', true).text('Uploading…');

        $.ajax({ url: cfg.store, method: 'POST', data, processData: false, contentType: false })
            .done((res) => {
                dlgUpload.close();
                toast('Uploaded. Reading the document…');
                $rows.find('.empty').closest('tr').remove();
                $rows.prepend(rowHtml({
                    uuid: res.uuid, status: 'pending', confirmed: false, document: files[0].name, files: files.length,
                    created_at: new Date().toISOString(),
                }));
                watch(res.uuid);
            })
            .fail((xhr) => $err.text(errorText(xhr, 'Upload failed.')))
            .always(() => $btn.prop('disabled', false).text('Upload'));
    });

    // ------------------------------------------------------------------ detail modal (view / edit)

    const $form = $('#d-form');
    let current = null;
    let stopDetailPoll = null;

    function lineHtml(l = {}) {
        const flag = (f) => ((l.low_confidence_fields || []).includes(f) ? ' is-flag' : '');

        return `<tr>
            <td><input class="input${flag('si_number')}" name="si_number" maxlength="64" value="${esc(l.si_number)}"></td>
            <td><input class="input${flag('amount')}" name="amount" type="number" step="0.01" value="${esc(l.amount)}"></td>
            <td><input class="input${flag('cwt_amount')}" name="cwt_amount" type="number" step="0.01" value="${esc(l.cwt_amount)}"></td>
            <td class="edit-only"><button class="link-btn js-remove-line" type="button" aria-label="Remove line">Remove</button></td>
        </tr>`;
    }

    function setMode(mode) {
        const editing = mode === 'edit';
        $form.toggleClass('is-view', !editing).find('input').prop('readonly', !editing);
        $('#btn-close, #btn-edit, #btn-delete').prop('hidden', editing || false);
        $('#btn-cancel-edit, #btn-save').prop('hidden', !editing);
        $('#btn-edit').prop('hidden', editing || !current?.can.edit);
        $('#btn-delete').prop('hidden', editing || !current?.can.delete);
    }

    function render(d) {
        current = d;
        $('#d-title').text(d.files[0]?.name || 'Transaction');
        $('#d-badge').html(badge(d.status, !!d.confirmed_at));
        $form.find('[data-err]').text('');

        const $state = $('#d-state');
        if (inFlight(d.status)) {
            $state.prop('hidden', false).removeClass('banner-bad').text('Reading the document. This usually takes under a minute.');
        } else if (d.status === 'failed') {
            $state.prop('hidden', false).addClass('banner-bad').text(d.error_message);
        } else {
            $state.prop('hidden', true);
        }
        $('#d-content').prop('hidden', d.status === 'failed' && !d.files.length);

        // pages (lazy images) with a per-file public toggle for those allowed to share
        $('#d-files').html(d.files.map((f) => `<figure>
            <img src="${esc(f.url)}" alt="${esc(f.name)}" loading="lazy" decoding="async">
            <figcaption>${esc(f.name)}
                ${d.can.visibility ? `<label class="check"><input type="checkbox" class="js-public" data-pos="${f.position}" ${f.is_public ? 'checked' : ''}> Public</label>`
                    : (f.is_public ? '<span class="badge badge-ok">Public</span>' : '')}
            </figcaption></figure>`).join(''));

        const flags = d.low_confidence_fields || [];
        Object.entries(d.fields).forEach(([name, value]) => {
            $form.find(`[name="${name}"]`).val(value ?? '').toggleClass('is-flag', flags.includes(name));
        });
        $('#d-lines').html(d.invoices.length ? d.invoices.map(lineHtml).join('') : '');

        const meta = [
            ['Uploaded', `${d.uploaded_by || ''} · ${fmtDateTime(d.uploaded_at)}`],
            d.engine ? ['Read by', `${d.engine === 'claude' ? 'Claude' : 'Groq'}${d.duration_seconds ? ` · ${d.duration_seconds}s` : ''}`] : null,
            d.confirmed_at ? ['Confirmed', `${d.confirmed_by || ''} · ${fmtDateTime(d.confirmed_at)}`] : null,
        ].filter(Boolean);
        $('#d-meta').html(meta.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join(''));

        setMode('view');
    }

    function followUntilDone(uuid) {
        if (stopDetailPoll) stopDetailPoll();
        stopDetailPoll = window.pollStatus(uuid, (s) => {
            if (!inFlight(s.status)) {
                stopDetailPoll = null;
                fetchDetail(uuid, true).then((d) => { if (current?.uuid === uuid) render(d); replaceRow(rowFromDetail(d)); });
            }
        });
    }

    function open(uuid, startEditing = false) {
        current = null;
        $('#d-title').text('Loading…');
        $('#d-badge').empty();
        $('#d-files, #d-lines, #d-meta').empty();
        $('#d-state').prop('hidden', true);
        $('#btn-edit, #btn-delete, #btn-save, #btn-cancel-edit').prop('hidden', true);
        $('#btn-close').prop('hidden', false);
        if (!dlgDetail.open) dlgDetail.showModal();

        fetchDetail(uuid).done((d) => {
            render(d);
            if (inFlight(d.status)) followUntilDone(uuid);
            if (startEditing && d.can.edit) setMode('edit');
        }).fail((xhr) => {
            $('#d-title').text('Could not open this transaction');
            $('#d-state').prop('hidden', false).addClass('banner-bad').text(errorText(xhr, 'Not found.'));
        });
    }

    $rows.on('click', '.js-view', function () { open($(this).closest('tr').data('uuid')); });
    $rows.on('click', '.js-edit', function () { open($(this).closest('tr').data('uuid'), true); });

    dlgDetail.addEventListener('close', () => {
        if (stopDetailPoll) { stopDetailPoll(); stopDetailPoll = null; }
        current = null;
    });
    $('#btn-close').on('click', () => dlgDetail.close());
    $('#btn-edit').on('click', () => setMode('edit'));
    $('#btn-cancel-edit').on('click', () => render(current));
    $('#btn-add-line').on('click', () => $('#d-lines').append(lineHtml()));
    $form.on('click', '.js-remove-line', function () { $(this).closest('tr').remove(); });

    // share / unshare one page
    $('#d-files').on('change', '.js-public', function () {
        const $box = $(this).prop('disabled', true);
        const pos = $box.data('pos');
        const makePublic = $box.prop('checked');

        $.ajax({ url: url(cfg.visibility, current.uuid, pos), method: 'PUT', data: { is_public: makePublic ? 1 : 0 } })
            .done(() => {
                const f = current.files.find((x) => x.position === pos);
                if (f) f.is_public = makePublic;
                toast(makePublic ? 'Page is now public.' : 'Page is now private.');
            })
            .fail((xhr) => { $box.prop('checked', !makePublic); toast(errorText(xhr), true); })
            .always(() => $box.prop('disabled', false));
    });

    // save + confirm
    $('#btn-save').on('click', function () {
        const $btn = $(this).prop('disabled', true).text('Saving…');
        const $err = $form.find('[data-err="_"]').text('');

        const body = {
            payment_reference_number: $form.find('[name=payment_reference_number]').val(),
            cr_number: $form.find('[name=cr_number]').val(),
            total_amount: $form.find('[name=total_amount]').val(),
            total_cwt_amount: $form.find('[name=total_cwt_amount]').val(),
            deposit_date: $form.find('[name=deposit_date]').val(),
            invoices: $('#d-lines tr').map(function () {
                return {
                    si_number: $(this).find('[name=si_number]').val(),
                    amount: $(this).find('[name=amount]').val(),
                    cwt_amount: $(this).find('[name=cwt_amount]').val(),
                };
            }).get(),
        };

        $.ajax({ url: url(cfg.update, current.uuid), method: 'PUT', contentType: 'application/json', data: JSON.stringify(body) })
            .done((d) => {
                cache.set(d.uuid, { at: Date.now(), data: d });
                render(d);
                replaceRow(rowFromDetail(d));
                toast('Saved and confirmed.');
            })
            .fail((xhr) => $err.text(errorText(xhr, 'Could not save. Check the values and try again.')))
            .always(() => $btn.prop('disabled', false).text('Save and confirm'));
    });

    // delete
    $('#btn-delete').on('click', () => { $('#dlg-delete [data-err]').text(''); dlgDelete.showModal(); });
    $('#btn-delete-yes').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        const uuid = current.uuid;

        $.ajax({ url: url(cfg.destroy, uuid), method: 'DELETE' })
            .done(() => {
                cache.delete(uuid);
                if (watchers[uuid]) { watchers[uuid](); delete watchers[uuid]; }
                $rows.find(`tr[data-uuid="${uuid}"]`).remove();
                if (!$rows.children().length) $rows.html('<tr><td colspan="8" class="empty">No transactions found.</td></tr>');
                dlgDelete.close();
                dlgDetail.close();
                toast('Transaction deleted.');
            })
            .fail((xhr) => $('#dlg-delete [data-err]').text(errorText(xhr)))
            .always(() => $btn.prop('disabled', false));
    });

    load(true);
}
