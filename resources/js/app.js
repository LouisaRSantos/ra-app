import './bootstrap';

const $ = window.$;

// Every AJAX call carries the CSRF token; a lapsed session sends the user back to login.
$.ajaxSetup({
    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
});
$(document).ajaxError((_e, xhr) => {
    if (xhr.status === 401 || xhr.status === 419) {
        window.location.href = '/login';
    } else if (xhr.status === 423) {
        window.location.href = '/password';
    }
});

window.toast = (message, isError = false) => {
    const $t = $('<div class="toast" role="status"></div>').toggleClass('is-error', isError).text(message).appendTo('body');
    setTimeout(() => $t.fadeOut(200, () => $t.remove()), 3200);
};

$('#menu-btn').on('click', () => $('#side').toggleClass('is-open'));

// Page scripts are separate chunks, fetched only on the page that needs them.
const pages = import.meta.glob('./pages/*.js');
const page = document.body.dataset.page;

if (page && pages[`./pages/${page}.js`]) {
    pages[`./pages/${page}.js`]().then((m) => m.default($));
}
