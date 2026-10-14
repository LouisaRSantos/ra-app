// Roles admin: permission matrix per role, saved over AJAX.
export default function ($) {
    const cfg = window.RA;
    const esc = (s) => $('<div>').text(s ?? '').html();
    const $wrap = $('#roles');

    $.getJSON(cfg.dataUrl).done((res) => {
        $wrap.empty();

        res.roles.forEach((role) => {
            const boxes = res.permissions.map((p) => {
                const checked = role.locked || role.permissions.includes(p.name) ? 'checked' : '';
                const disabled = role.locked ? 'disabled' : '';
                return `<label class="perm">
                    <input type="checkbox" value="${esc(p.name)}" ${checked} ${disabled}>
                    <span>${esc(p.name)}<small>${esc(p.label)}</small></span>
                </label>`;
            }).join('');

            $wrap.append(`<section class="panel" data-id="${role.id}">
                <div class="modal-head">${esc(role.name)} <span class="hint">· ${role.users} user${role.users === 1 ? '' : 's'}</span></div>
                <div class="perm-grid">${boxes}</div>
                <div class="modal-foot">
                    ${role.locked
                        ? '<span class="hint" style="margin-right:auto">Always has every permission.</span>'
                        : '<button class="btn btn-primary btn-sm js-save" type="button">Save</button>'}
                </div>
            </section>`);
        });
    }).fail(() => {
        $wrap.html('<div class="panel"><div class="empty">Could not load roles.</div></div>');
    });

    $wrap.on('click', '.js-save', function () {
        const $btn = $(this).prop('disabled', true);
        const $sec = $btn.closest('section');
        const permissions = $sec.find('input:checked').map((_, el) => el.value).get();

        $.ajax({
            url: cfg.updateUrl.replace('__ID__', $sec.data('id')),
            method: 'PUT',
            // JSON so "no permissions" is sent as [] rather than omitted
            contentType: 'application/json',
            data: JSON.stringify({ permissions }),
        }).done(() => toast('Role saved.'))
            .fail((xhr) => toast(xhr.responseJSON?.message || 'Could not save.', true))
            .always(() => $btn.prop('disabled', false));
    });
}
