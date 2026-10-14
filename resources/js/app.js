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

/**
 * Polls an extraction's status. One request at a time (the next is scheduled only after the
 * previous answered), gentle backoff, paused while the tab is hidden, stops at a final state.
 * Returns a function that cancels it.
 */
window.pollStatus = (uuid, onData) => {
    let delay = 2000;
    let stopped = false;
    let timer;

    const tick = () => {
        if (stopped) return;
        if (document.hidden) {
            timer = setTimeout(tick, 3000);
            return;
        }

        $.getJSON(`/extractions/${uuid}/status`)
            .done((data) => {
                onData(data);
                if (data.status === 'completed' || data.status === 'failed') return;
                delay = Math.min(delay * 1.4, 8000);
                timer = setTimeout(tick, delay);
            })
            .fail((xhr) => {
                if (xhr.status >= 400 && xhr.status < 500) return; // gone, forbidden, logged out: do not hammer
                delay = Math.min(delay * 2, 15000);
                timer = setTimeout(tick, delay);
            });
    };

    timer = setTimeout(tick, 1500);

    return () => {
        stopped = true;
        clearTimeout(timer);
    };
};

window.toast =(message, isError = false) => {
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
