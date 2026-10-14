// Users admin: async paginated list, modal create/edit, password reset. All JSON over AJAX.
export default function ($) {
    const cfg = window.RA;
    const url = (tpl, id) => tpl.replace('__ID__', id);
    const esc = (s) => $('<div>').text(s ?? '').html();

    const state = { page: 1, loading: false };
    const $rows = $('#rows');
    const $more = $('#more');

    const skeleton = () => '<tr class="skeleton">' + '<td><span></span></td>'.repeat(5) + '</tr>';

    function rowHtml(u) {
        const status = u.is_active
            ? (u.must_change_password ? '<span class="badge badge-warn">Password pending</span>' : '<span class="badge badge-ok">Active</span>')
            : '<span class="badge badge-off">Disabled</span>';

        return `<tr data-id="${u.id}">
            <td>${esc(u.name)}${u.is_self ? ' <span class="hint">(you)</span>' : ''}</td>
            <td>${esc(u.email)}</td>
            <td>${esc(u.role ?? 'None')}</td>
            <td>${status}</td>
            <td class="actions">
                <button class="link-btn js-edit" type="button">Edit</button>
                <button class="link-btn js-pw" type="button">Reset password</button>
            </td>
        </tr>`;
    }

    function load(reset) {
        if (state.loading) return;
        state.loading = true;

        if (reset) {
            state.page = 1;
            $rows.html(skeleton().repeat(4));
            $more.prop('hidden', true);
        }

        $.getJSON(cfg.dataUrl, {
            page: state.page,
            q: $('#f-q').val().trim(),
            role: $('#f-role').val(),
            status: $('#f-status').val(),
        }).done((res) => {
            if (reset) $rows.empty();
            // the row keeps its user object for the edit / reset dialogs
            res.data.forEach((u) => $(rowHtml(u)).data('user', u).appendTo($rows));

            if (reset && !res.data.length) {
                $rows.html('<tr><td colspan="5" class="empty">No users match.</td></tr>');
            }
            $more.prop('hidden', !res.has_more);
            state.page += 1;
        }).fail(() => {
            $rows.html('<tr><td colspan="5" class="empty">Could not load users. Try again.</td></tr>');
        }).always(() => { state.loading = false; });
    }

    // Auto-load the next page when the "load more" bar scrolls into view.
    if ('IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && !$more.prop('hidden')) load(false);
        }, { rootMargin: '200px' }).observe($more[0]);
    }
    $('#btn-more').on('click', () => load(false));

    let timer;
    $('#f-q').on('input', () => { clearTimeout(timer); timer = setTimeout(() => load(true), 250); });
    $('#f-role, #f-status').on('change', () => load(true));

    // ---- dialogs ----
    const dlgUser = $('#dlg-user')[0];
    const dlgPw = $('#dlg-pw')[0];
    let editing = null;

    $('[data-close]').on('click', function () { $(this).closest('dialog')[0].close(); });

    function clearErrors($form) { $form.find('[data-err]').text(''); }
    function showErrors($form, xhr) {
        const body = xhr.responseJSON || {};
        if (xhr.status === 422 && body.errors) {
            Object.entries(body.errors).forEach(([k, v]) => $form.find(`[data-err="${k}"]`).text(v[0]));
        }
        if (body.message && !body.errors) $form.find('[data-err="_"]').text(body.message);
    }

    $('#btn-new').on('click', () => {
        editing = null;
        $('#dlg-user-title').text('New user');
        $('#form-user')[0].reset();
        $('#g-password').prop('hidden', false);
        $('#u-password').prop('required', true);
        $('#g-active').prop('hidden', true);
        clearErrors($('#form-user'));
        dlgUser.showModal();
    });

    $rows.on('click', '.js-edit', function () {
        const u = $(this).closest('tr').data('user');
        editing = u;
        $('#dlg-user-title').text('Edit user');
        $('#u-name').val(u.name);
        $('#u-email').val(u.email);
        $('#u-role').val(u.role);
        $('#u-active').prop('checked', u.is_active).prop('disabled', u.is_self);
        $('#g-password').prop('hidden', true);
        $('#u-password').prop('required', false);
        $('#g-active').prop('hidden', false);
        clearErrors($('#form-user'));
        dlgUser.showModal();
    });

    $('#form-user').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this);
        const $btn = $f.find('[type=submit]').prop('disabled', true);
        clearErrors($f);

        const data = { name: $('#u-name').val(), email: $('#u-email').val(), role: $('#u-role').val() };
        let req;
        if (editing) {
            data.is_active = $('#u-active').prop('checked') ? 1 : 0;
            req = $.ajax({ url: url(cfg.updateUrl, editing.id), method: 'PUT', data });
        } else {
            data.password = $('#u-password').val();
            req = $.post(cfg.storeUrl, data);
        }

        req.done(() => { dlgUser.close(); toast('User saved.'); load(true); })
            .fail((xhr) => showErrors($f, xhr))
            .always(() => $btn.prop('disabled', false));
    });

    $rows.on('click', '.js-pw', function () {
        const u = $(this).closest('tr').data('user');
        editing = u;
        $('#pw-who').text(`${u.name} (${u.email})`);
        $('#form-pw')[0].reset();
        clearErrors($('#form-pw'));
        dlgPw.showModal();
    });

    $('#form-pw').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this);
        const $btn = $f.find('[type=submit]').prop('disabled', true);
        clearErrors($f);

        $.ajax({ url: url(cfg.passwordUrl, editing.id), method: 'PUT', data: { password: $('#p-password').val() } })
            .done(() => { dlgPw.close(); toast('Password reset.'); load(true); })
            .fail((xhr) => showErrors($f, xhr))
            .always(() => $btn.prop('disabled', false));
    });

    load(true);
}
