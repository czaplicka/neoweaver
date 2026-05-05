document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('ach-char-select');
    if (!select || !select.form) return;

    select.addEventListener('change', function () {
        select.form.submit();
    });
});
